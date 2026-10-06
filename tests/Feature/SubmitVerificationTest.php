<?php

namespace Modules\TransactionVerification\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\TransactionVerification\Contracts\OcrEngine;
use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\TransactionVerifier;
use Modules\TransactionVerification\Data\ExpectedDestination;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Data\VerificationRequest;
use Modules\TransactionVerification\Enums\VerdictEnum;
use Modules\TransactionVerification\Enums\VerificationStatusEnum;
use Modules\TransactionVerification\Events\TransactionVerificationCompleted;
use Modules\TransactionVerification\Models\TransactionVerification;
use Modules\TransactionVerification\Services\TransactionVerificationService;
use RuntimeException;
use Modules\TransactionVerification\Tests\TestCase;
use Throwable;

/** Submit → check in the same call → verdict → event. Generated images only: the real receipts are not in git. */
class SubmitVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '01000000001';

    // An InstaPay receipt as OCR reads it: amount, destination and reference.
    private const RECEIPT = "3,070 EGP\nTransfer Amount\nTo Instapay\n01000000001\nReference 100000000001";

    private ?string $secondText = null;

    protected function setUp(): void
    {
        parent::setUp();

        // A developer .env may point the engine at 'tesseract'; tests pin it. The second engine is off except in the
        // tests about it, which fake its HTTP answer.
        config([
            'transaction-verification.engine' => 'null',
            'transaction-verification.second_engine.mode' => 'off',
            'transaction-verification.confidence_threshold' => 0.90,
        ]);
        Http::preventStrayRequests();
    }

    public function test_always_a_second_engine_that_reads_the_same_confirms_the_match(): void
    {
        $this->reading(self::RECEIPT);
        $this->secondReading('always', "3,070 EGP\nTransfer Amount\nTo Instapay\n01000000001\nReference 100000000001");

        $result = $this->verifier()->submit($this->request());
        $row = TransactionVerification::where('uuid', $result->uuid)->first();

        $this->assertSame(VerdictEnum::MATCH, $result->verdict);
        $this->assertSame('recorded+rapidocr', $row->engine);
        $this->assertSame('1 + rapidocr test', $row->engine_version);
        Http::assertSent(fn ($request) => $request->url() === 'http://ocr.test/read' && $request->header('Content-Type') === ['image/png']);
    }

    public function test_always_a_second_engine_that_reads_another_amount_or_no_phone_sends_the_match_to_a_person(): void
    {
        foreach ([
            'another amount' => "3,080 EGP\nTransfer Amount\nTo Instapay\n01000000001",
            'no phone' => "3,070 EGP\nTransfer Amount\nTo Instapay",
            'nothing' => '',
        ] as $case => $second) {
            $this->reading(self::RECEIPT);
            $this->secondReading('always', $second);

            $result = $this->verifier()->submit($this->request(key: $case));

            $this->assertSame(VerdictEnum::NEEDS_REVIEW, $result->verdict, $case);
        }
    }

    public function test_always_a_second_engine_that_is_down_sends_the_match_to_a_person_and_never_fails_the_row(): void
    {
        Log::spy();
        $this->reading(self::RECEIPT);
        $this->secondReading('always', null);

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerificationStatusEnum::COMPLETED, $result->status);
        $this->assertSame(VerdictEnum::NEEDS_REVIEW, $result->verdict);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'transaction-verification.second-engine-failed')->once();
    }

    public function test_always_a_field_only_the_second_engine_read_stays_missing(): void
    {
        $this->reading(implode("\f", array_fill(0, 4, "3,070 EGP\nTransfer Amount\nTo Instapay\nX*** Y***")));
        $this->secondReading('always', "3,070 EGP\nTransfer Amount\nTo Instapay\n01000000001");

        // RapidOCR alone read the phone: never enough to approve.
        $this->assertSame(VerdictEnum::UNREADABLE, $this->verifier()->submit($this->request())->verdict);
    }

    public function test_fallback_a_reference_only_the_second_engine_read_still_catches_a_reused_receipt(): void
    {
        $this->reading(self::RECEIPT);
        $this->verifier()->submit($this->request(subjectId: 1, width: 800));

        // Tesseract lost the phone and the reference; the second engine's reference must reach the duplicate check.
        $this->reading(implode("\f", array_fill(0, 4, "3,070 EGP\nTransfer Amount\nTo Instapay")));
        $this->secondReading('fallback', "3,070 EGP\nTransfer Amount\nTo Instapay\n01000000001\nReference 100000000001");

        $this->assertSame(VerdictEnum::DUPLICATE, $this->verifier()->submit($this->request(subjectId: 2, width: 900))->verdict);
    }

    public function test_a_mistyped_mode_is_always_never_off(): void
    {
        $this->reading(self::RECEIPT);
        $this->secondReading('alwyas', "3,080 EGP\nTransfer Amount\nTo Instapay\n01000000001");

        $this->assertSame(VerdictEnum::NEEDS_REVIEW, $this->verifier()->submit($this->request())->verdict);
    }

    public function test_a_mode_that_is_not_text_is_always_too(): void
    {
        $this->reading(self::RECEIPT);
        $this->secondReading('always', "3,080 EGP\nTransfer Amount\nTo Instapay\n01000000001");
        config(['transaction-verification.second_engine.mode' => ['off']]);

        $this->assertSame(VerdictEnum::NEEDS_REVIEW, $this->verifier()->submit($this->request())->verdict);
    }

    public function test_off_never_asks_the_second_engine(): void
    {
        $this->reading(self::RECEIPT);
        $this->secondReading('off', 'unused');

        $this->assertSame(VerdictEnum::MATCH, $this->verifier()->submit($this->request())->verdict);
        Http::assertNothingSent();
    }

    public function test_fallback_leaves_a_sure_reading_alone(): void
    {
        $this->reading(self::RECEIPT);
        $this->secondReading('fallback', "3,080 EGP\nTransfer Amount\nTo Instapay\n01000000009");

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerdictEnum::MATCH, $result->verdict);
        $this->assertSame('recorded', TransactionVerification::where('uuid', $result->uuid)->value('engine'));
        Http::assertNothingSent();
    }

    public function test_fallback_fills_what_tesseract_missed_and_confirms_what_it_was_unsure_of(): void
    {
        $pass = fn (string $amount, string $phone = '') => "{$amount} EGP\nTransfer Amount\nTo Instapay\n{$phone}";

        // Tesseract lost the phone in every pass: the second engine's phone decides.
        $this->reading(implode("\f", array_fill(0, 4, $pass('3,070'))));
        $this->secondReading('fallback', $pass('3,070', self::PHONE));
        $this->assertSame(VerdictEnum::MATCH, $this->verifier()->submit($this->request(key: 'missing'))->verdict);

        // Three passes of four agree (0.75, under 0.90): the same amount from the second engine confirms it...
        $unsure = implode("\f", [$pass('3,070', self::PHONE), $pass('3,070', self::PHONE), $pass('3,070', self::PHONE), $pass('3,080', self::PHONE)]);
        $this->reading($unsure);
        $this->secondReading('fallback', $pass('3,070', self::PHONE));
        $this->assertSame(VerdictEnum::MATCH, $this->verifier()->submit($this->request(key: 'confirmed'))->verdict);

        // ...and another amount leaves it to a person.
        $this->reading($unsure);
        $this->secondReading('fallback', $pass('3,080', self::PHONE));
        $this->assertSame(VerdictEnum::NEEDS_REVIEW, $this->verifier()->submit($this->request(key: 'disagreed'))->verdict);
    }

    public function test_a_matching_receipt_is_stored_and_verified(): void
    {
        Event::fake([TransactionVerificationCompleted::class]);
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE, reference: '100000000001'));

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerificationStatusEnum::COMPLETED, $result->status);
        $this->assertSame(VerdictEnum::MATCH, $result->verdict);
        Event::assertDispatched(TransactionVerificationCompleted::class, fn ($e) => $e->uuid === $result->uuid && $e->verdict === 'match');
    }

    public function test_submitting_the_same_key_twice_creates_one_row(): void
    {
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE));

        $first = $this->verifier()->submit($this->request(key: 'same'));
        $second = $this->verifier()->submit($this->request(key: 'same'));

        $this->assertSame($first->uuid, $second->uuid);
        $this->assertSame(1, TransactionVerification::count());
    }

    public function test_the_same_reference_on_another_payout_is_a_duplicate(): void
    {
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE, reference: '100000000001'));

        $first = $this->verifier()->submit($this->request(subjectId: 1, width: 800));
        $second = $this->verifier()->submit($this->request(subjectId: 2, width: 900));

        $this->assertSame(VerdictEnum::DUPLICATE, $second->verdict);
        $this->assertSame([$first->uuid], $second->duplicateOf);
    }

    public function test_a_recorded_instapay_receipt_is_read_and_matched(): void
    {
        $this->reading(self::RECEIPT);

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerdictEnum::MATCH, $result->verdict);
        $this->assertSame('pass', $result->checks['amount']['outcome']);
        $this->assertSame('pass', $result->checks['destination']['outcome']);
    }

    public function test_a_recorded_receipt_for_another_amount_is_a_mismatch(): void
    {
        $this->reading(self::RECEIPT);

        $result = $this->verifier()->submit(new VerificationRequest(
            subjectType: 'affiliate_payout',
            subjectId: 1,
            expectedAmountMinor: 200000,
            expectedDestination: new ExpectedDestination(ExpectedDestination::PHONE, self::PHONE),
            file: UploadedFile::fake()->image('receipt.png'),
            idempotencyKey: 'other-amount',
        ));

        $this->assertSame(VerdictEnum::MISMATCH, $result->verdict);
        $this->assertSame(307000, $result->checks['amount']['found']);
    }

    public function test_a_decimal_point_one_pass_kept_turns_the_match_into_needs_review(): void
    {
        // Receipt 01 re-sent for 1.50: three passes lose the point and read the payout's 150, one keeps it.
        $pass = self::RECEIPT;
        $this->reading(implode("\f", [...array_fill(0, 3, str_replace('3,070', '150', $pass)), str_replace('3,070', '1.50', $pass)]));

        $result = $this->verifier()->submit(new VerificationRequest(
            subjectType: 'affiliate_payout',
            subjectId: 1,
            expectedAmountMinor: 15000,
            expectedDestination: new ExpectedDestination(ExpectedDestination::PHONE, self::PHONE),
            file: UploadedFile::fake()->image('receipt.png'),
            idempotencyKey: 'lost-point',
        ));

        $this->assertSame(VerdictEnum::NEEDS_REVIEW, $result->verdict);
    }

    public function test_the_same_transfer_in_english_and_arabic_is_a_duplicate(): void
    {
        // Fixtures 01 (English UI) and 08 (Arabic UI) are one transfer: different bytes, same reference.
        $this->reading(self::RECEIPT);
        $english = $this->verifier()->submit($this->request(subjectId: 1, width: 800));

        $this->recordedReading('08');
        $arabic = $this->verifier()->submit($this->request(subjectId: 2, width: 900));

        $this->assertSame(VerdictEnum::MATCH, $english->verdict);
        $this->assertSame(VerdictEnum::DUPLICATE, $arabic->verdict);
        $this->assertSame([$english->uuid], $arabic->duplicateOf);
    }

    public function test_a_receipt_over_the_byte_limit_fails_before_it_is_read(): void
    {
        config(['transaction-verification.max_file_bytes' => 10]);
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE));

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerificationStatusEnum::FAILED, $result->status);
        $this->assertSame('RuntimeException: The receipt file is too large to read.', $result->error);
    }

    public function test_the_null_engine_leaves_every_receipt_unreadable(): void
    {
        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerdictEnum::UNREADABLE, $result->verdict);
    }

    public function test_a_concurrent_submit_with_the_same_key_returns_the_winner(): void
    {
        // Another request inserts the same key between our idempotency check and our insert.
        $winner = null;
        TransactionVerification::creating(function (TransactionVerification $row) use (&$winner) {
            if ($winner === null) {
                $winner = tap($row->replicate()->fill(['uuid' => (string) Str::uuid()]))->saveQuietly();
            }
        });

        $result = $this->verifier()->submit($this->request(key: 'raced'));

        $this->assertSame($winner->uuid, $result->uuid);
        $this->assertSame(1, TransactionVerification::count());
    }

    public function test_an_insert_failure_rethrows_and_leaves_no_row(): void
    {
        // NAN can't be JSON-encoded, so the insert fails for a reason other than the unique key.
        $request = new VerificationRequest(
            subjectType: 'affiliate_payout',
            subjectId: 1,
            expectedAmountMinor: 307000,
            expectedDestination: new ExpectedDestination(ExpectedDestination::PHONE, self::PHONE),
            file: UploadedFile::fake()->image('receipt.png'),
            idempotencyKey: 'broken',
            context: ['bad' => NAN],
        );

        try {
            $this->verifier()->submit($request);
            $this->fail('submit() should rethrow the insert failure.');
        } catch (\Illuminate\Database\Eloquent\JsonEncodingException) {
        }

        $this->assertSame(0, TransactionVerification::count());
    }

    public function test_resubmitting_a_checked_receipt_never_reads_it_again(): void
    {
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE));
        $result = $this->verifier()->submit($this->request());

        $this->app->instance(OcrEngine::class, new class implements OcrEngine
        {
            public function read(string $localPath): string
            {
                throw new RuntimeException('should not run');
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
        $again = $this->verifier()->submit($this->request());

        $this->assertSame($result->uuid, $again->uuid);
        $this->assertSame(VerificationStatusEnum::COMPLETED, $again->status);
        $this->assertSame(VerdictEnum::MATCH, $again->verdict);
        $this->assertNull($again->error);
    }

    public function test_blank_references_are_not_duplicates_of_each_other(): void
    {
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE, reference: "  \u{00A0} "));

        $this->verifier()->submit($this->request(subjectId: 1, width: 800));
        $second = $this->verifier()->submit($this->request(subjectId: 2, width: 900));

        $this->assertSame(VerdictEnum::MATCH, $second->verdict);
    }

    public function test_a_throwing_listener_never_turns_a_stored_verdict_into_failed(): void
    {
        Event::listen(TransactionVerificationCompleted::class, fn () => throw new RuntimeException('listener down'));
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE));

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerificationStatusEnum::COMPLETED, $result->status);
        $this->assertSame(VerdictEnum::MATCH, $result->verdict);
        $this->assertNull($result->error);
    }

    public function test_a_throwing_listener_with_a_broken_error_reporter_still_does_not_throw(): void
    {
        Event::listen(TransactionVerificationCompleted::class, fn () => throw new RuntimeException('listener down'));
        $this->app->instance(ExceptionHandler::class, new class($this->app) extends Handler
        {
            public function report(Throwable $e): void
            {
                throw new RuntimeException('log channel down');
            }
        });
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE));

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerificationStatusEnum::COMPLETED, $result->status);
    }

    public function test_a_check_that_died_mid_way_is_marked_failed(): void
    {
        $row = $this->stuckRow();

        $this->travel(10)->minutes();
        app(TransactionVerificationService::class)->failStale();
        $this->assertSame(VerificationStatusEnum::PROCESSING, $row->fresh()->status, 'a check inside the window may still be running');

        $this->travel(6)->minutes();
        app(TransactionVerificationService::class)->failStale();

        $this->assertSame(VerificationStatusEnum::FAILED, $row->fresh()->status);
        $this->assertSame('Gave up: the check stopped before it finished.', $row->fresh()->error);
    }

    public function test_a_check_that_died_after_storing_its_reference_still_flags_a_copy_that_finished_first(): void
    {
        // The copy finished before the original stored its reference; the original's request then died before it
        // could re-check later copies.
        $original = $this->readingRow(subjectId: 1);
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE, reference: '100000000001'));
        $copy = $this->verifier()->submit($this->request(subjectId: 2));
        $this->assertSame(VerdictEnum::MATCH, $copy->verdict);
        $original->update(['reference_hash' => TransactionVerification::where('uuid', $copy->uuid)->value('reference_hash')]);

        $this->travel(16)->minutes();
        app(TransactionVerificationService::class)->failStale();

        $this->assertSame(VerificationStatusEnum::FAILED, $original->fresh()->status);
        $this->assertSame(VerdictEnum::DUPLICATE, $this->verifier()->find($copy->uuid)->verdict);
    }

    public function test_a_database_drop_after_the_verdict_never_throws_at_the_caller(): void
    {
        // Every read after the verdict write fails: the result can't be reloaded, but submit() must not throw.
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE));
        $db = DB::connection(config('transaction-verification.connection'));
        $stored = false;
        $db->listen(function ($query) use (&$stored) {
            $stored = $stored || str_contains($query->sql, '"verdict"');
        });
        $db->beforeExecuting(function (string $sql) use (&$stored) {
            if ($stored && str_starts_with(strtolower($sql), 'select')) {
                throw new RuntimeException('connection lost');
            }
        });

        $result = $this->verifier()->submit($this->request());

        $this->assertTrue($stored);
        $this->assertNotNull($result->uuid);
    }

    public function test_a_slow_check_never_overwrites_the_sweep(): void
    {
        Event::fake([TransactionVerificationCompleted::class]);
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE));
        $this->app->instance(OcrEngine::class, new class implements OcrEngine
        {
            public function read(string $localPath): string
            {
                // While this check reads, the sweep gives up on it.
                TransactionVerification::query()->update(['status' => VerificationStatusEnum::FAILED->value, 'error' => 'Gave up: the check stopped before it finished.']);

                return 'receipt text';
            }

            public function name(): string
            {
                return 'slow';
            }

            public function version(): string
            {
                return '1';
            }
        });

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerificationStatusEnum::FAILED, $result->status);
        $this->assertNull($result->verdict);
        Event::assertNotDispatched(TransactionVerificationCompleted::class);
    }

    public function test_a_slow_check_that_then_fails_keeps_the_sweeps_reason(): void
    {
        $this->app->instance(OcrEngine::class, new class implements OcrEngine
        {
            public function read(string $localPath): string
            {
                TransactionVerification::query()->update(['status' => VerificationStatusEnum::FAILED->value, 'error' => 'Gave up: the check stopped before it finished.']);

                throw new RuntimeException('engine down');
            }

            public function name(): string
            {
                return 'slow';
            }

            public function version(): string
            {
                return '1';
            }
        });

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerificationStatusEnum::FAILED, $result->status);
        $this->assertSame('Gave up: the check stopped before it finished.', $result->error);
    }

    public function test_a_copy_checked_while_the_original_is_read_is_flagged_once_the_original_stores(): void
    {
        // The copy arrives in another request while the original is still being read, and finishes first.
        Event::fake([TransactionVerificationCompleted::class]);
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE, reference: '100000000001'));
        $engine = $this->duringFirstRead(fn () => $this->verifier()->submit($this->request(subjectId: 2, width: 900)));

        $original = $this->verifier()->submit($this->request(subjectId: 1, width: 800));

        $this->assertSame(VerdictEnum::MATCH, $engine->result->verdict, 'the copy saw no reference yet');
        $this->assertSame(VerdictEnum::MATCH, $original->verdict);
        $recheck = $this->verifier()->find($engine->result->uuid);
        $this->assertSame(VerdictEnum::DUPLICATE, $recheck->verdict);
        $this->assertSame([$original->uuid], $recheck->duplicateOf);
        // Listeners see match, then duplicate: the latest event is current.
        $this->assertSame(['match', 'duplicate'], Event::dispatched(TransactionVerificationCompleted::class, fn ($e) => $e->uuid === $recheck->uuid)->map(fn ($args) => $args[0]->verdict)->values()->all());
    }

    public function test_an_original_that_finishes_while_a_copy_is_being_checked_still_makes_it_a_duplicate(): void
    {
        Event::fake([TransactionVerificationCompleted::class]);
        // Another request is still reading the original (lower id, no reference yet).
        $original = $this->readingRow(subjectId: 1);
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE, reference: '100000000001'));

        // The copy has looked up duplicates (none yet); just before its verdict lands, the original stores its reference.
        $db = DB::connection(config('transaction-verification.connection'));
        $done = false;
        $db->beforeExecuting(function (string $sql) use (&$done, $db, $original) {
            if (! $done && str_contains($sql, '"verdict"')) {
                $done = true;
                $copyReference = $db->table('transaction_verifications')->where('id', '!=', $original->id)->value('reference_hash');
                $db->table('transaction_verifications')->where('id', $original->id)->update(['status' => 'completed', 'reference_hash' => $copyReference]);
            }
        });

        $copy = $this->verifier()->submit($this->request(subjectId: 2));

        $this->assertTrue($done);
        $this->assertSame(VerdictEnum::DUPLICATE, $this->verifier()->find($copy->uuid)->verdict);
        $this->assertSame([$original->uuid], $this->verifier()->find($copy->uuid)->duplicateOf);
        // One announcement, the re-decided one: listeners never see the copy as a match.
        $this->assertSame(['duplicate'], Event::dispatched(TransactionVerificationCompleted::class, fn ($e) => $e->uuid === $copy->uuid)->map(fn ($args) => $args[0]->verdict)->values()->all());
    }

    public function test_a_copy_flagged_by_the_original_between_its_verdict_and_its_own_recheck_ends_on_duplicate(): void
    {
        // The original (another request, lower id) is read while the copy is checked here.
        Event::fake([TransactionVerificationCompleted::class]);
        $original = $this->readingRow(subjectId: 1);
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE, reference: '100000000001'));

        // The copy has stored 'match'; before it re-decides itself, the original finishes and re-decides the copy.
        Log::listen(function (MessageLogged $log) use ($original) {
            if ($log->message === 'transaction-verification.timing' && $log->context['id'] !== $original->id) {
                $copy = TransactionVerification::find($log->context['id']);
                $original->update(['status' => VerificationStatusEnum::COMPLETED, 'reference_hash' => $copy->reference_hash]);
                $service = app(TransactionVerificationService::class);
                event((new \ReflectionMethod($service, 'redecide'))->invoke($service, $copy->id));
            }
        });

        $copy = $this->verifier()->submit($this->request(subjectId: 2));

        $this->assertSame(VerdictEnum::DUPLICATE, $this->verifier()->find($copy->uuid)->verdict);
        // Whatever arrives last is current: never the copy's stale 'match'.
        $verdicts = Event::dispatched(TransactionVerificationCompleted::class, fn ($e) => $e->uuid === $copy->uuid)->map(fn ($args) => $args[0]->verdict)->values()->all();
        $this->assertNotContains('match', $verdicts);
        $this->assertSame('duplicate', end($verdicts));
    }

    public function test_a_recheck_never_reruns_a_finished_copy(): void
    {
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE, reference: '100000000001'));
        $engine = $this->duringFirstRead(fn () => $this->verifier()->submit($this->request(subjectId: 2, width: 900)));

        $original = $this->verifier()->submit($this->request(subjectId: 1, width: 800));

        $recheck = TransactionVerification::where('uuid', $engine->result->uuid)->first();
        $this->assertSame(2, $engine->reads, 'each receipt read once');
        $this->assertSame(VerificationStatusEnum::COMPLETED, $recheck->status);
        $this->assertSame(VerdictEnum::DUPLICATE, $recheck->verdict);
        $this->assertSame(1, $recheck->attempts);
        $this->assertSame([$original->uuid], $recheck->checks['duplicate']['of']);
    }

    public function test_an_original_that_fails_after_reading_still_flags_a_copy_that_ran_first(): void
    {
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE, reference: '100000000001'));
        // The original's verdict write, once the copy is done, hits a DB blip.
        $armed = false;
        $failed = false;
        DB::connection(config('transaction-verification.connection'))->beforeExecuting(function (string $sql) use (&$armed, &$failed) {
            if ($armed && ! $failed && str_contains($sql, '"verdict"')) {
                $failed = true;
                throw new RuntimeException('connection blip');
            }
        });
        $engine = $this->duringFirstRead(function () use (&$armed) {
            $copy = $this->verifier()->submit($this->request(subjectId: 2, width: 900));
            $armed = true;

            return $copy;
        });

        $original = $this->verifier()->submit($this->request(subjectId: 1, width: 800));

        $this->assertTrue($failed);
        $this->assertSame(VerificationStatusEnum::FAILED, $original->status);
        $this->assertSame(VerdictEnum::DUPLICATE, $this->verifier()->find($engine->result->uuid)->verdict);
    }

    public function test_an_original_that_failed_after_reading_still_catches_a_later_copy(): void
    {
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE, reference: '100000000001'));
        $failed = false;
        DB::connection(config('transaction-verification.connection'))->beforeExecuting(function (string $sql) use (&$failed) {
            if (! $failed && str_contains($sql, '"verdict"')) {
                $failed = true;
                throw new RuntimeException('connection blip');
            }
        });

        $original = $this->verifier()->submit($this->request(subjectId: 1, width: 800));
        $copy = $this->verifier()->submit($this->request(subjectId: 2, width: 900));

        $this->assertSame(VerificationStatusEnum::FAILED, $this->verifier()->find($original->uuid)->status);
        $this->assertSame(VerdictEnum::DUPLICATE, $copy->verdict);
    }

    public function test_a_broken_log_channel_on_the_failure_path_does_not_throw(): void
    {
        Log::shouldReceive('warning')->andThrow(new RuntimeException('log channel down'));
        $this->app->instance(OcrEngine::class, new class implements OcrEngine
        {
            public function read(string $localPath): string
            {
                throw new RuntimeException('engine down');
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

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerificationStatusEnum::FAILED, $result->status);
    }

    public function test_a_failure_quoting_the_receipt_never_reaches_the_log(): void
    {
        // An engine or parser error may quote what it read; only its class is logged.
        $this->secondReading('always', null);
        $this->app->instance(ReceiptParser::class, new class implements ReceiptParser
        {
            public function parse(string $text): ExtractedFields
            {
                throw new RuntimeException('could not parse: To 01000000001');
            }
        });
        $this->reading('receipt text');
        $logged = [];
        Log::listen(function (MessageLogged $log) use (&$logged) {
            $logged[] = $log->message.' '.json_encode($log->context);
        });

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerificationStatusEnum::FAILED, $result->status);
        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString(self::PHONE, implode("\n", $logged));
        $this->assertStringContainsString('RuntimeException', implode("\n", $logged));
    }

    public function test_lookups_use_keyed_hashes(): void
    {
        config(['transaction-verification.hmac_key' => 'test-key']);
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE, reference: ' 1000 0000 0001 '));

        $this->verifier()->submit($this->request());

        $row = TransactionVerification::first();
        $this->assertSame(hash_hmac('sha256', 'phone:'.self::PHONE, 'test-key'), $row->expected_destination_hash);
        $this->assertSame(hash_hmac('sha256', 'ref:100000000001', 'test-key'), $row->reference_hash);
    }

    public function test_an_engine_failure_marks_the_row_failed_without_throwing(): void
    {
        Event::fake([TransactionVerificationCompleted::class]);
        $this->app->instance(OcrEngine::class, new class implements OcrEngine
        {
            public function read(string $localPath): string
            {
                throw new RuntimeException('engine down');
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

        $result = $this->verifier()->submit($this->request());

        $this->assertSame(VerificationStatusEnum::FAILED, $result->status);
        $this->assertSame('RuntimeException: engine down', $result->error);
        $this->assertNull($result->verdict);
        Event::assertNotDispatched(TransactionVerificationCompleted::class);
    }

    public function test_personal_data_is_encrypted_at_rest_and_hidden_from_serialisation(): void
    {
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE));
        $this->secondReading('always', "To Instapay\n".self::PHONE);

        $this->verifier()->submit($this->request());

        $raw = DB::connection(config('transaction-verification.connection'))->table('transaction_verifications')->first();
        $this->assertStringNotContainsString(self::PHONE, (string) $raw->expected_destination);
        $this->assertStringNotContainsString(self::PHONE, (string) $raw->extracted);
        $this->assertStringNotContainsString(self::PHONE, (string) $raw->checks);
        // What the engines read is used, never kept.
        $this->assertObjectNotHasProperty('ocr_text', $raw);
        $this->assertObjectNotHasProperty('second_ocr_text', $raw);

        $array = TransactionVerification::first()->toArray();
        $this->assertArrayNotHasKey('expected_destination', $array);
        $this->assertArrayNotHasKey('extracted', $array);
    }

    public function test_the_check_writes_nothing_to_any_disk(): void
    {
        Storage::fake('local');
        $temp = fn () => glob(sys_get_temp_dir().'/tv_*') ?: [];
        $before = $temp();
        $this->fakeReading(new ExtractedFields(amountMinor: 307000, phone: self::PHONE));
        $request = $this->request(key: 'read');
        $this->verifier()->submit($request);
        // The upload is the caller's: PHP deletes it when the request ends, the package never does.
        $this->assertFileExists($request->file->getRealPath());

        $this->app->instance(OcrEngine::class, new class implements OcrEngine
        {
            public function read(string $localPath): string
            {
                throw new RuntimeException('engine down');
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
        $this->verifier()->submit($this->request(subjectId: 2, key: 'failed'));

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame($before, $temp());
    }

    public function test_latest_for_accepts_an_integer_subject_id(): void
    {
        $this->verifier()->submit($this->request(subjectId: 123, key: 'one'));
        $newest = $this->verifier()->submit($this->request(subjectId: 123, key: 'two'));

        $this->assertSame($newest->uuid, $this->verifier()->latestFor('affiliate_payout', 123)?->uuid);
        $this->assertSame($newest->uuid, $this->verifier()->find($newest->uuid)?->uuid);
        $this->assertNull($this->verifier()->latestFor('affiliate_payout', 999));
    }

    private function stuckRow(): TransactionVerification
    {
        $result = $this->verifier()->submit($this->request());
        $row = TransactionVerification::where('uuid', $result->uuid)->first();
        $row->update(['status' => VerificationStatusEnum::PROCESSING]);

        return $row;
    }

    /** A row another request is still reading: lower id, no reference yet. */
    private function readingRow(int $subjectId): TransactionVerification
    {
        return TransactionVerification::create([
            'uuid' => (string) Str::uuid(),
            'subject_type' => 'affiliate_payout',
            'subject_id' => (string) $subjectId,
            'status' => VerificationStatusEnum::PROCESSING,
            'attempts' => 1,
            'expected_amount_minor' => 307000,
            'currency' => 'EGP',
            'expected_destination' => ['type' => ExpectedDestination::PHONE, 'value' => self::PHONE],
            'expected_destination_hash' => str_repeat('0', 64),
            'file_mime' => 'image/png',
            'file_size' => 1,
            'file_sha256' => str_repeat('a', 64),
            'idempotency_key' => "reading:{$subjectId}",
        ]);
    }

    /** The fake reading's engine, except that its first read runs $during first: another request arriving mid-read. */
    private function duringFirstRead(callable $during): object
    {
        $engine = new class($during) implements OcrEngine
        {
            public int $reads = 0;

            public mixed $result = null;

            public function __construct(private $during) {}

            public function read(string $localPath): string
            {
                if ($this->reads++ === 0) {
                    $this->result = ($this->during)();
                }

                return 'receipt text';
            }

            public function name(): string
            {
                return 'fake';
            }

            public function version(): string
            {
                return '1';
            }
        };
        $this->app->instance(OcrEngine::class, $engine);

        return $engine;
    }

    private function verifier(): TransactionVerifier
    {
        return app(TransactionVerifier::class);
    }

    private function request(int $subjectId = 1, ?string $key = null, int $width = 898): VerificationRequest
    {
        return new VerificationRequest(
            subjectType: 'affiliate_payout',
            subjectId: $subjectId,
            expectedAmountMinor: 307000,
            expectedDestination: new ExpectedDestination(ExpectedDestination::PHONE, self::PHONE),
            file: UploadedFile::fake()->image('receipt.png', $width, 1600),
            idempotencyKey: $key ?? "affiliate_payout:{$subjectId}",
        );
    }

    /** The engine returns a recorded (synthetic) Tesseract output; the real InstaPay parser reads it. */
    private function recordedReading(string $fixture): void
    {
        $path = __DIR__."/../fixtures/ocr/instapay-{$fixture}.txt";

        if (! is_file($path)) {
            $this->markTestSkipped('Needs the recorded receipts in tests/fixtures/ocr/ (local only, not in git).');
        }

        $this->reading((string) file_get_contents($path));
    }

    /** The sidecar's answer: its lines, or null for a 500. Read when the request is made: the first fake stub wins. */
    private function secondReading(string $mode, ?string $text): void
    {
        config(['transaction-verification.second_engine.mode' => $mode, 'transaction-verification.second_engine.url' => 'http://ocr.test']);
        $this->secondText = $text;
        Http::fake(['ocr.test/*' => fn () => $this->secondText === null
            ? Http::response(['error' => 'RuntimeError'], 500)
            : Http::response(['lines' => $this->secondText === '' ? [] : explode("\n", $this->secondText), 'version' => 'rapidocr test'])]);
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

    private function fakeReading(ExtractedFields $fields): void
    {
        $this->app->instance(OcrEngine::class, new class implements OcrEngine
        {
            public function read(string $localPath): string
            {
                return 'receipt text';
            }

            public function name(): string
            {
                return 'fake';
            }

            public function version(): string
            {
                return '1';
            }
        });

        $this->app->instance(ReceiptParser::class, new class($fields) implements ReceiptParser
        {
            public function __construct(private readonly ExtractedFields $fields) {}

            public function parse(string $text): ExtractedFields
            {
                return $this->fields;
            }
        });
    }
}
