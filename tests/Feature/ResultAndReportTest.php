<?php

namespace Modules\TransactionVerification\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\TransactionVerification\Contracts\OcrEngine;
use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\TransactionVerifier;
use Modules\TransactionVerification\Data\ExpectedDestination;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Data\VerificationRequest;
use Modules\TransactionVerification\Enums\DestinationTypeEnum;
use Modules\TransactionVerification\Enums\VerdictEnum;
use Modules\TransactionVerification\Models\TransactionVerification;
use Modules\TransactionVerification\Services\Ocr\TesseractEngine;
use Modules\TransactionVerification\Services\Preprocess\ImagePreprocessor;
use Modules\TransactionVerification\Services\Preprocess\PdfPage;
use RuntimeException;
use Modules\TransactionVerification\Tests\TestCase;

/** Beyond submit: what a caller reads back, the shadow report, the timing log and how the engines share a read. */
class ResultAndReportTest extends TestCase
{
    use RefreshDatabase;

    private const RECEIPT = "3,070 EGP\nTransfer Amount\nTo Instapay\n01000000001\nReference 100000000001";

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned like SubmitVerificationTest: a developer .env may point these elsewhere.
        config([
            'transaction-verification.engine' => 'null',
            'transaction-verification.second_engine.mode' => 'off',
            'transaction-verification.confidence_threshold' => 0.90,
        ]);
        Http::preventStrayRequests();
    }

    public function test_a_result_shows_what_was_read_with_the_phone_masked(): void
    {
        $this->reading(self::RECEIPT);

        $result = $this->verifier()->find($this->verifier()->submit($this->request())->uuid);

        $this->assertSame(307000, $result->extracted['amount_minor']);
        $this->assertSame('********001', $result->extracted['phone']);
        $this->assertSame('100000000001', $result->extracted['reference']);
        $this->assertStringNotContainsString('01000000001', json_encode($result));
    }

    public function test_a_result_shows_only_the_ends_of_a_handle_and_an_account(): void
    {
        $this->parsed(new ExtractedFields(handle: 'someone@instapay', account: '1000000000000000010', note: 'rent'));

        $extracted = $this->verifier()->submit($this->request())->extracted;

        $this->assertSame('so***@instapay', $extracted['handle']);
        $this->assertSame('****0010', $extracted['account']);
        // Free text can hold anything a person typed.
        $this->assertArrayNotHasKey('note', $extracted);
    }

    public function test_a_value_too_short_to_hide_a_part_of_is_masked_whole(): void
    {
        $this->parsed(new ExtractedFields(phone: '01234', handle: 'ab@instapay', account: '1234567'));

        $extracted = $this->verifier()->submit($this->request())->extracted;

        $this->assertSame(['*****', '***@instapay', '****'], [$extracted['phone'], $extracted['handle'], $extracted['account']]);
    }

    public function test_the_shadow_report_lists_recent_payout_checks_and_no_personal_values(): void
    {
        $this->reading(self::RECEIPT);
        $old = $this->verifier()->submit($this->request('old', 1))->uuid;
        TransactionVerification::where('uuid', $old)->update(['created_at' => now()->subDays(30)]);
        $recent = $this->verifier()->submit($this->request('new', 2))->uuid;

        $this->assertSame(0, Artisan::call('transaction-verification:shadow-report', ['--since' => now()->subDays(7)->toDateString()]));
        $output = Artisan::output();
        $lines = array_map('str_getcsv', explode("\n", trim($output)));

        $this->assertSame(['uuid', 'subject_id', 'created_at', 'status', 'verdict', 'confidence', 'amount', 'destination', 'duplicate', 'label'], $lines[0]);
        $this->assertCount(2, $lines);
        $row = array_combine($lines[0], $lines[1]);
        $this->assertSame($recent, $row['uuid']);
        $this->assertSame(['pass', 'pass', ''], [$row['amount'], $row['destination'], $row['label']]);
        $this->assertStringNotContainsString('01000000001', $output);
    }

    public function test_the_shadow_report_leaves_out_other_subjects_and_defuses_spreadsheet_formulas(): void
    {
        $this->reading(self::RECEIPT);
        $other = $this->verifier()->submit(new VerificationRequest(
            subjectType: 'invoice', subjectId: 7, expectedAmountMinor: 307000,
            expectedDestination: new ExpectedDestination(DestinationTypeEnum::PHONE, '01000000001'),
            file: UploadedFile::fake()->image('receipt.png', 700, 1600), idempotencyKey: 'invoice:7',
        ))->uuid;
        // Another service may name the subject anything; a spreadsheet must not run it.
        $formula = $this->verifier()->submit(new VerificationRequest(
            subjectType: 'affiliate_payout', subjectId: '=HYPERLINK("http://x")', expectedAmountMinor: 307000,
            expectedDestination: new ExpectedDestination(DestinationTypeEnum::PHONE, '01000000001'),
            file: UploadedFile::fake()->image('receipt.png', 701, 1600), idempotencyKey: 'formula',
        ))->uuid;

        Artisan::call('transaction-verification:shadow-report');
        $output = Artisan::output();
        $lines = array_map('str_getcsv', explode("\n", trim($output)));

        $this->assertStringNotContainsString($other, $output);
        $this->assertCount(2, $lines);
        $this->assertSame($formula, $lines[1][0]);
        $this->assertSame('\'=HYPERLINK("http://x")', $lines[1][1]);
    }

    public function test_the_shadow_report_defuses_full_width_and_line_feed_formula_prefixes(): void
    {
        $this->reading(self::RECEIPT);

        foreach (["\u{FF1D}HYPERLINK(\"http://x\")", "\n=1+1"] as $i => $subject) {
            $this->verifier()->submit(new VerificationRequest(
                subjectType: 'affiliate_payout', subjectId: $subject, expectedAmountMinor: 307000,
                expectedDestination: new ExpectedDestination(DestinationTypeEnum::PHONE, '01000000001'),
                file: UploadedFile::fake()->image('receipt.png', 710 + $i, 1600), idempotencyKey: "formula-{$i}",
            ));
        }

        Artisan::call('transaction-verification:shadow-report');
        $rows = array_slice(array_map('str_getcsv', preg_split('/\n(?=[0-9a-f]{8}-)/', trim(Artisan::output()))), 1);

        $this->assertSame(["'\u{FF1D}HYPERLINK(\"http://x\")", "'\n=1+1"], array_column($rows, 1));
    }

    public function test_the_shadow_report_refuses_a_date_that_is_not_y_m_d(): void
    {
        $this->assertSame(1, Artisan::call('transaction-verification:shadow-report', ['--since' => 'last tuesday']));
        $this->assertStringContainsString('Y-m-d', Artisan::output());
        // Shaped right, but no such day.
        $this->assertSame(1, Artisan::call('transaction-verification:shadow-report', ['--since' => '2026-02-30']));
    }

    public function test_each_read_logs_where_its_time_went_and_nothing_it_read(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $log) use (&$logged) {
            if ($log->message === 'transaction-verification.timing') {
                $logged[] = $log->context;
            }
        });
        $this->reading(self::RECEIPT);
        config(['transaction-verification.second_engine' => ['mode' => 'always', 'url' => 'http://ocr.test', 'timeout' => 5]]);
        Http::fake(['ocr.test/read' => Http::response(['lines' => explode("\n", self::RECEIPT), 'version' => 'rapidocr test'])]);

        $this->verifier()->submit($this->request());
        config(['transaction-verification.second_engine.mode' => 'off']);
        $this->verifier()->submit($this->request('affiliate_payout:2', 2));

        $this->assertCount(2, $logged);
        // Exactly these keys, so receipt text can never ride along.
        $this->assertSame(['id', 'engine', 'first_ms', 'second_ms', 'passes', 'total_ms'], array_keys($logged[0]));
        // Not Tesseract, so no passes.
        $this->assertNull($logged[0]['passes']);
        $this->assertSame(['recorded+rapidocr', 'recorded'], array_column($logged, 'engine'));
        $this->assertIsInt($logged[0]['second_ms']);
        $this->assertNull($logged[1]['second_ms']);
        foreach ($logged as $context) {
            array_map($this->assertIsInt(...), [$context['id'], $context['first_ms'], $context['total_ms']]);
        }
    }

    public function test_a_handle_the_engines_read_differently_goes_to_review_not_mismatch(): void
    {
        // Tesseract takes the zero for the letter o; RapidOCR reads it right. Nobody can tell which is true, so a person looks.
        $receipt = "3,070 EGP\nTransfer Amount\nTo Instapay\n%s\nReference 100000000001";
        $this->reading(sprintf($receipt, 'creatorox@instapay'));
        config(['transaction-verification.second_engine' => ['mode' => 'always', 'url' => 'http://ocr.test', 'timeout' => 5]]);
        Http::fake(['ocr.test/read' => Http::response(['lines' => explode("\n", sprintf($receipt, 'creator0x@instapay')), 'version' => 'rapidocr test'])]);

        $result = $this->verifier()->submit(new VerificationRequest(
            subjectType: 'affiliate_payout',
            subjectId: 1,
            expectedAmountMinor: 307000,
            expectedDestination: new ExpectedDestination(DestinationTypeEnum::INSTAPAY_HANDLE, 'creator0x@instapay'),
            file: UploadedFile::fake()->image('receipt.png', 898, 1600),
            idempotencyKey: 'affiliate_payout:1',
        ));

        $this->assertSame(VerdictEnum::NEEDS_REVIEW, $result->verdict);
        $this->assertSame('fail', TransactionVerification::where('uuid', $result->uuid)->value('checks')['destination']['outcome']);
        $this->assertSame('recorded+rapidocr', TransactionVerification::where('uuid', $result->uuid)->value('engine'));
    }

    public function test_on_always_rapidocr_reads_while_tesseract_does(): void
    {
        $tesseract = $this->tesseractReading(self::RECEIPT);
        config(['transaction-verification.second_engine' => ['mode' => 'always', 'url' => 'http://ocr.test', 'timeout' => 5]]);
        Http::fake(['ocr.test/read' => Http::response(['lines' => explode("\n", self::RECEIPT), 'version' => 'rapidocr test'])]);

        $result = $this->verifier()->submit($this->request());

        $this->assertTrue($tesseract->gotMeanwhile);
        Http::assertSentCount(1);
        $this->assertSame(VerdictEnum::MATCH, $result->verdict);
        $this->assertSame('tesseract+rapidocr', TransactionVerification::where('uuid', $result->uuid)->value('engine'));
    }

    public function test_a_mode_that_is_not_text_reads_alongside_tesseract_like_always(): void
    {
        $tesseract = $this->tesseractReading(self::RECEIPT);
        config(['transaction-verification.second_engine' => ['mode' => ['off'], 'url' => 'http://ocr.test', 'timeout' => 5]]);
        Http::fake(['ocr.test/read' => Http::response(['lines' => explode("\n", self::RECEIPT), 'version' => 'rapidocr test'])]);

        $this->assertSame(VerdictEnum::MATCH, $this->verifier()->submit($this->request())->verdict);
        $this->assertTrue($tesseract->gotMeanwhile);
        Http::assertSentCount(1);
    }

    public function test_on_always_two_passes_are_enough_when_both_engines_agree(): void
    {
        $tesseract = $this->tesseractReading(self::RECEIPT);
        config(['transaction-verification.second_engine' => ['mode' => 'always', 'url' => 'http://ocr.test', 'timeout' => 5]]);
        Http::fake(['ocr.test/read' => Http::response(['lines' => explode("\n", self::RECEIPT), 'version' => 'rapidocr test'])]);

        $this->assertSame(VerdictEnum::MATCH, $this->verifier()->submit($this->request())->verdict);
        $this->assertSame([array_slice(config('transaction-verification.tesseract.passes'), 0, 2)], $tesseract->reads);
    }

    public function test_on_always_the_other_passes_run_when_the_engines_disagree(): void
    {
        $tesseract = $this->tesseractReading(self::RECEIPT);
        config(['transaction-verification.second_engine' => ['mode' => 'always', 'url' => 'http://ocr.test', 'timeout' => 5]]);
        Http::fake(['ocr.test/read' => Http::response(['lines' => explode("\n", str_replace('01000000001', '01000000002', self::RECEIPT)), 'version' => 'rapidocr test'])]);

        $this->assertSame(VerdictEnum::NEEDS_REVIEW, $this->verifier()->submit($this->request())->verdict);
        $all = config('transaction-verification.tesseract.passes');
        $this->assertSame([array_slice($all, 0, 2), array_slice($all, 2)], $tesseract->reads);
    }

    public function test_a_reference_the_first_passes_missed_means_reading_them_all(): void
    {
        // Both engines agree on the amount and phone, but without a reference a reused receipt could slip through.
        $receipt = "3,070 EGP\nTransfer Amount\nTo Instapay\n01000000001";
        $tesseract = $this->tesseractReading($receipt);
        config(['transaction-verification.second_engine' => ['mode' => 'always', 'url' => 'http://ocr.test', 'timeout' => 5]]);
        Http::fake(['ocr.test/read' => Http::response(['lines' => explode("\n", $receipt), 'version' => 'rapidocr test'])]);

        $this->verifier()->submit($this->request());

        $this->assertCount(2, $tesseract->reads);
    }

    public function test_early_passes_zero_reads_every_pass_at_once(): void
    {
        $tesseract = $this->tesseractReading(self::RECEIPT);
        config([
            'transaction-verification.second_engine' => ['mode' => 'always', 'url' => 'http://ocr.test', 'timeout' => 5],
            'transaction-verification.tesseract.early_passes' => 0,
        ]);
        Http::fake(['ocr.test/read' => Http::response(['lines' => explode("\n", self::RECEIPT), 'version' => 'rapidocr test'])]);

        $this->verifier()->submit($this->request());

        $this->assertSame([config('transaction-verification.tesseract.passes')], $tesseract->reads);
    }

    public function test_on_fallback_rapidocr_never_reads_alongside(): void
    {
        $tesseract = $this->tesseractReading(self::RECEIPT);
        config(['transaction-verification.second_engine' => ['mode' => 'fallback', 'url' => 'http://ocr.test', 'timeout' => 5]]);
        Http::fake();

        $this->assertSame(VerdictEnum::MATCH, $this->verifier()->submit($this->request())->verdict);
        $this->assertFalse($tesseract->gotMeanwhile);
        Http::assertNothingSent();
    }

    private function verifier(): TransactionVerifier
    {
        return app(TransactionVerifier::class);
    }

    /** Another subject gets another file too (another width), so only the reference can tie two receipts. */
    private function request(string $key = 'affiliate_payout:1', int $subjectId = 1): VerificationRequest
    {
        return new VerificationRequest(
            subjectType: 'affiliate_payout',
            subjectId: $subjectId,
            expectedAmountMinor: 307000,
            expectedDestination: new ExpectedDestination(DestinationTypeEnum::PHONE, '01000000001'),
            file: UploadedFile::fake()->image('receipt.png', 897 + $subjectId, 1600),
            idempotencyKey: $key,
        );
    }

    private function parsed(ExtractedFields $fields): void
    {
        $this->reading('receipt text');
        $this->app->instance(ReceiptParser::class, new class($fields) implements ReceiptParser
        {
            public function __construct(private readonly ExtractedFields $fields) {}

            public function parse(string $text): ExtractedFields
            {
                return $this->fields;
            }
        });
    }

    /** The real engine class (so the service can read alongside it), returning $text without running tesseract. */
    private function tesseractReading(string $text): TesseractEngine
    {
        $engine = new class($text, app(ImagePreprocessor::class), new PdfPage) extends TesseractEngine
        {
            public bool $gotMeanwhile = false;

            /** @var list<?array> the passes asked for, per read() */
            public array $reads = [];

            public function __construct(private readonly string $text, ImagePreprocessor $preprocessor, PdfPage $pdf)
            {
                parent::__construct($preprocessor, $pdf);
            }

            public function read(string $localPath, ?callable $meanwhile = null, ?array $passes = null): string
            {
                $this->gotMeanwhile = $this->gotMeanwhile || $meanwhile !== null;
                $this->reads[] = $passes;
                $meanwhile && $meanwhile();

                return $this->text;
            }

            public function version(): string
            {
                return '5';
            }
        };
        $this->app->instance(OcrEngine::class, $engine);

        return $engine;
    }

    private function reading(string $text): void
    {
        $this->app->instance(OcrEngine::class, new class($text) implements OcrEngine
        {
            public function __construct(private readonly string $text) {}

            public function read(string $localPath): string
            {
                return $this->text;
            }

            public function name(): string
            {
                return 'recorded';
            }

            public function version(): string
            {
                return '1';
            }
        });
    }
}
