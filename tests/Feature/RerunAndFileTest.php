<?php

namespace Modules\TransactionVerification\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\TransactionVerification\Contracts\OcrEngine;
use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\TransactionVerifier;
use Modules\TransactionVerification\Data\ExpectedDestination;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Data\VerificationRequest;
use Modules\TransactionVerification\Enums\VerdictEnum;
use Modules\TransactionVerification\Enums\VerificationStatusEnum;
use Modules\TransactionVerification\Events\TransactionVerificationCompleted;
use Modules\TransactionVerification\Jobs\ProcessVerificationJob;
use Modules\TransactionVerification\Models\TransactionVerification;
use Modules\TransactionVerification\Services\Ocr\TesseractEngine;
use Modules\TransactionVerification\Services\Preprocess\ImagePreprocessor;
use Modules\TransactionVerification\Services\Preprocess\PdfPage;
use Modules\TransactionVerification\Services\TransactionVerificationService;
use RuntimeException;
use Modules\TransactionVerification\Tests\TestCase;

/** The public PHP interface beyond submit: what a caller reads back, re-runs and opens. */
class RerunAndFileTest extends TestCase
{
    use RefreshDatabase;

    private const RECEIPT = "3,070 EGP\nTransfer Amount\nTo Instapay\n01000000001\nReference 100000000001";

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned like SubmitVerificationTest: a developer .env may point these elsewhere.
        config([
            'transaction-verification.disk' => 'hetzner',
            'transaction-verification.folder' => 'transaction-verifications',
            'transaction-verification.engine' => 'null',
            'transaction-verification.second_engine.mode' => 'off',
            'transaction-verification.confidence_threshold' => 0.90,
        ]);
        Storage::fake('hetzner');
        Http::preventStrayRequests();
    }

    public function test_a_result_shows_what_was_read_with_the_phone_masked(): void
    {
        $this->reading((string) file_get_contents(__DIR__.'/../fixtures/ocr/instapay-01.txt'));

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

    public function test_reprocessing_reads_the_receipt_again_and_decides_again(): void
    {
        Event::fake([TransactionVerificationCompleted::class]);
        $this->reading(self::RECEIPT);
        $first = $this->verifier()->submit($this->request());

        // An engine upgrade now reads another amount off the same receipt.
        $this->reading(str_replace('3,070', '3,080', self::RECEIPT));
        $again = $this->verifier()->reprocess($first->uuid);

        $this->assertSame([VerdictEnum::MATCH, VerdictEnum::MISMATCH], [$first->verdict, $again->verdict]);
        $this->assertSame($first->uuid, $again->uuid);
        Event::assertDispatchedTimes(TransactionVerificationCompleted::class, 2);
    }

    public function test_a_reprocessed_receipt_shows_no_old_reading_while_it_waits(): void
    {
        $this->reading(self::RECEIPT);
        $uuid = $this->verifier()->submit($this->request())->uuid;
        Queue::fake();

        $waiting = $this->verifier()->reprocess($uuid);

        $this->assertSame(VerificationStatusEnum::PENDING, $waiting->status);
        $this->assertSame([], array_filter($waiting->extracted));
    }

    public function test_a_copy_cleared_by_a_rerun_is_caught_again_when_the_original_is_rerun_to_the_same_reference(): void
    {
        $this->reading(self::RECEIPT);
        $original = $this->verifier()->submit($this->request('a', subjectId: 1))->uuid;
        $copy = $this->verifier()->submit($this->request('b', subjectId: 2))->uuid;
        $this->assertSame(VerdictEnum::DUPLICATE, $this->verifier()->find($copy)->verdict);

        // Both are read again and both now show another reference: they are still one transfer.
        $this->reading(str_replace('100000000001', '100000000002', self::RECEIPT));
        $this->assertSame(VerdictEnum::MATCH, $this->verifier()->reprocess($copy)->verdict);
        $this->verifier()->reprocess($original);

        $this->assertSame(VerdictEnum::DUPLICATE, $this->verifier()->find($copy)->verdict);
    }

    public function test_a_copy_is_cleared_when_a_rerun_of_the_original_reads_another_reference(): void
    {
        $this->reading(self::RECEIPT);
        $original = $this->verifier()->submit($this->request('a', subjectId: 1))->uuid;
        $copy = $this->verifier()->submit($this->request('b', subjectId: 2))->uuid;

        // The original's reference was misread: the copy no longer shares it.
        $this->reading(str_replace('100000000001', '100000000002', self::RECEIPT));
        $this->verifier()->reprocess($original);

        $this->assertSame(VerdictEnum::MATCH, $this->verifier()->find($copy)->verdict);
    }

    public function test_a_copy_is_caught_again_when_the_original_loses_its_reference_and_then_reads_it_again(): void
    {
        $this->reading(self::RECEIPT);
        $original = $this->verifier()->submit($this->request('a', subjectId: 1))->uuid;
        $copy = $this->verifier()->submit($this->request('b', subjectId: 2))->uuid;

        $this->reading(str_replace('Reference 100000000001', '', self::RECEIPT));
        $this->verifier()->reprocess($original);
        $this->assertSame(VerdictEnum::MATCH, $this->verifier()->find($copy)->verdict);

        $this->reading(self::RECEIPT);
        $this->verifier()->reprocess($original);

        $this->assertSame(VerdictEnum::DUPLICATE, $this->verifier()->find($copy)->verdict);
    }

    public function test_a_copy_is_decided_again_when_a_rerun_that_stored_a_new_reference_is_recovered(): void
    {
        $this->reading(self::RECEIPT);
        $original = $this->verifier()->submit($this->request('a', subjectId: 1))->uuid;
        $copy = $this->verifier()->submit($this->request('b', subjectId: 2))->uuid;

        // A rerun stored the new reference, then its worker died before the copy was re-checked.
        $other = str_replace('100000000001', '100000000002', self::RECEIPT);
        $this->reading($other);
        $this->verifier()->reprocess($original);
        TransactionVerification::where('uuid', $copy)->update(['verdict' => VerdictEnum::DUPLICATE->value]);
        TransactionVerification::where('uuid', $original)->update(['status' => VerificationStatusEnum::PROCESSING->value]);
        $this->travel(1)->day();

        app(TransactionVerificationService::class)->recoverStale();

        $this->assertSame(VerificationStatusEnum::COMPLETED, $this->verifier()->find($original)->status);
        $this->assertSame(VerdictEnum::MATCH, $this->verifier()->find($copy)->verdict);
    }

    public function test_a_copy_whose_verdict_stays_the_same_is_not_announced_again(): void
    {
        $this->reading(self::RECEIPT);
        $original = $this->verifier()->submit($this->request('a', subjectId: 1))->uuid;
        $this->verifier()->submit($this->request('b', subjectId: 2));
        Event::fake([TransactionVerificationCompleted::class]);

        $this->verifier()->reprocess($original);

        Event::assertDispatchedTimes(TransactionVerificationCompleted::class, 1);
    }

    public function test_a_receipt_still_waiting_to_be_read_is_not_queued_twice(): void
    {
        Queue::fake();
        $pending = $this->verifier()->submit($this->request());

        $again = $this->verifier()->reprocess($pending->uuid);

        $this->assertSame(VerificationStatusEnum::PENDING, $again->status);
        Queue::assertPushed(ProcessVerificationJob::class, 1);
    }

    public function test_a_failed_receipt_can_be_reprocessed(): void
    {
        $this->app->instance(OcrEngine::class, new class implements OcrEngine
        {
            public function read(string $localPath): string
            {
                throw new RuntimeException('engine crashed');
            }

            public function name(): string
            {
                return 'broken';
            }

            public function version(): string
            {
                return '1';
            }
        });
        $failed = $this->verifier()->submit($this->request());

        $this->reading(self::RECEIPT);
        $again = $this->verifier()->reprocess($failed->uuid);

        $this->assertSame([VerificationStatusEnum::FAILED, VerificationStatusEnum::COMPLETED], [$failed->status, $again->status]);
        $this->assertSame(VerdictEnum::MATCH, $again->verdict);
        $this->assertNull($again->error);
    }

    public function test_reprocessing_an_unknown_uuid_returns_null(): void
    {
        $this->assertNull($this->verifier()->reprocess((string) Str::uuid()));
    }

    public function test_a_receipt_opens_through_a_link_that_expires(): void
    {
        $this->freezeTime();
        $result = $this->verifier()->submit($this->request());

        $url = $this->verifier()->temporaryFileUrl($result->uuid, 10);

        $this->assertStringContainsString("transaction-verifications/{$result->uuid}.png", $url);
        $this->assertStringContainsString('expiration='.now()->addMinutes(10)->getTimestamp(), $url);
        $this->assertNull($this->verifier()->temporaryFileUrl((string) Str::uuid()));
    }

    public function test_a_receipt_link_lasts_one_to_sixty_minutes(): void
    {
        $uuid = $this->verifier()->submit($this->request())->uuid;

        foreach ([0, 61] as $minutes) {
            try {
                $this->verifier()->temporaryFileUrl($uuid, $minutes);
                $this->fail("{$minutes} minutes was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_reprocess_command_reruns_each_receipt_and_fails_on_an_unknown_one(): void
    {
        $this->reading(self::RECEIPT);
        $uuid = $this->verifier()->submit($this->request())->uuid;
        $unknown = (string) Str::uuid();

        $this->artisan('transaction-verification:reprocess', ['uuid' => [$uuid, $unknown]])
            ->expectsOutput("{$uuid}: completed, match")
            ->expectsOutput("{$unknown}: not found")
            ->assertFailed();

        $this->artisan('transaction-verification:reprocess', ['uuid' => [$uuid]])->assertSuccessful();
    }

    public function test_the_shadow_report_lists_recent_payout_checks_with_a_link_and_no_personal_values(): void
    {
        $this->reading(self::RECEIPT);
        $old = $this->verifier()->submit($this->request('old', 1))->uuid;
        TransactionVerification::where('uuid', $old)->update(['created_at' => now()->subDays(30)]);
        $recent = $this->verifier()->submit($this->request('new', 2))->uuid;

        $this->assertSame(0, Artisan::call('transaction-verification:shadow-report', ['--since' => now()->subDays(7)->toDateString()]));
        $output = Artisan::output();
        $lines = array_map('str_getcsv', explode("\n", trim($output)));

        $this->assertSame(['uuid', 'subject_id', 'created_at', 'status', 'verdict', 'confidence', 'amount', 'destination', 'duplicate', 'receipt_link', 'label'], $lines[0]);
        $this->assertCount(2, $lines);
        $row = array_combine($lines[0], $lines[1]);
        $this->assertSame($recent, $row['uuid']);
        $this->assertSame(['pass', 'pass', ''], [$row['amount'], $row['destination'], $row['label']]);
        $this->assertStringContainsString("transaction-verifications/{$recent}", $row['receipt_link']);
        $this->assertStringNotContainsString('01000000001', $output);
    }

    public function test_the_shadow_report_leaves_out_other_subjects_and_defuses_spreadsheet_formulas(): void
    {
        $this->reading(self::RECEIPT);
        $other = $this->verifier()->submit(new VerificationRequest(
            subjectType: 'invoice', subjectId: 7, expectedAmountMinor: 307000,
            expectedDestination: new ExpectedDestination(ExpectedDestination::PHONE, '01000000001'),
            file: UploadedFile::fake()->image('receipt.png', 700, 1600), idempotencyKey: 'invoice:7',
        ))->uuid;
        // Another service may name the subject anything; a spreadsheet must not run it.
        $formula = $this->verifier()->submit(new VerificationRequest(
            subjectType: 'affiliate_payout', subjectId: '=HYPERLINK("http://x")', expectedAmountMinor: 307000,
            expectedDestination: new ExpectedDestination(ExpectedDestination::PHONE, '01000000001'),
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
                expectedDestination: new ExpectedDestination(ExpectedDestination::PHONE, '01000000001'),
                file: UploadedFile::fake()->image('receipt.png', 710 + $i, 1600), idempotencyKey: "formula-{$i}",
            ));
        }

        Artisan::call('transaction-verification:shadow-report');
        $rows = array_slice(array_map('str_getcsv', preg_split('/\n(?=[0-9a-f]{8}-)/', trim(Artisan::output()))), 1);

        $this->assertSame(["'\u{FF1D}HYPERLINK(\"http://x\")", "'\n=1+1"], array_column($rows, 1));
    }

    public function test_the_shadow_report_keeps_going_when_a_link_cannot_be_signed(): void
    {
        $this->reading(self::RECEIPT);
        $uuid = $this->verifier()->submit($this->request())->uuid;
        $verifier = $this->createStub(TransactionVerifier::class);
        $verifier->method('temporaryFileUrl')->willThrowException(new RuntimeException('This driver does not support creating temporary URLs.'));
        $this->app->instance(TransactionVerifier::class, $verifier);

        $this->assertSame(0, Artisan::call('transaction-verification:shadow-report'));
        $lines = array_map('str_getcsv', explode("\n", trim(Artisan::output())));

        $this->assertSame($uuid, $lines[1][0]);
        $this->assertSame('', $lines[1][9]);
    }

    public function test_the_shadow_report_refuses_a_date_that_is_not_y_m_d(): void
    {
        $this->assertSame(1, Artisan::call('transaction-verification:shadow-report', ['--since' => 'last tuesday']));
        $this->assertStringContainsString('Y-m-d', Artisan::output());
        // Shaped right, but no such day.
        $this->assertSame(1, Artisan::call('transaction-verification:shadow-report', ['--since' => '2026-02-30']));
    }

    public function test_the_shadow_report_refuses_a_link_outside_one_to_sixty_minutes(): void
    {
        $this->assertSame(1, Artisan::call('transaction-verification:shadow-report', ['--minutes' => 61]));
        $this->assertStringContainsString('1-60', Artisan::output());
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
        $this->assertSame(['id', 'engine', 'download_ms', 'first_ms', 'second_ms', 'passes', 'total_ms'], array_keys($logged[0]));
        // Not Tesseract, so no passes.
        $this->assertNull($logged[0]['passes']);
        $this->assertSame(['recorded+rapidocr', 'recorded'], array_column($logged, 'engine'));
        $this->assertIsInt($logged[0]['second_ms']);
        $this->assertNull($logged[1]['second_ms']);
        foreach ($logged as $context) {
            array_map($this->assertIsInt(...), [$context['id'], $context['download_ms'], $context['first_ms'], $context['total_ms']]);
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
            expectedDestination: new ExpectedDestination(ExpectedDestination::INSTAPAY_HANDLE, 'creator0x@instapay'),
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
            expectedDestination: new ExpectedDestination(ExpectedDestination::PHONE, '01000000001'),
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
