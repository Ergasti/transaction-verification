<?php

namespace Modules\TransactionVerification\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\TransactionVerification\Contracts\OcrEngine;
use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\TransactionVerifier;
use Modules\TransactionVerification\Data\CheckResult;
use Modules\TransactionVerification\Data\ExpectedDestination;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Data\VerificationRequest;
use Modules\TransactionVerification\Data\VerificationResult;
use Modules\TransactionVerification\Enums\CheckEnum;
use Modules\TransactionVerification\Enums\DestinationTypeEnum;
use Modules\TransactionVerification\Enums\SecondEngineModeEnum;
use Modules\TransactionVerification\Enums\VerdictEnum;
use Modules\TransactionVerification\Enums\VerificationStatusEnum;
use Modules\TransactionVerification\Events\TransactionVerificationCompleted;
use Modules\TransactionVerification\Models\TransactionVerification;
use Modules\TransactionVerification\Services\Ocr\RapidOcrEngine;
use Modules\TransactionVerification\Services\Ocr\TesseractEngine;
use RuntimeException;
use Throwable;

class TransactionVerificationService implements TransactionVerifier
{
    public function __construct(
        private readonly OcrEngine $engine,
        private readonly ReceiptParser $parser,
        private readonly DuplicateDetector $duplicates,
        private readonly Matcher $matcher,
        private readonly RapidOcrEngine $secondEngine,
    ) {}

    public function submit(VerificationRequest $request): VerificationResult
    {
        if ($existing = TransactionVerification::where('idempotency_key', $request->idempotencyKey)->first()) {
            return $this->toResult($existing);
        }

        $file = $request->file;
        $destination = $request->expectedDestination;

        try {
            // Created already claimed: this request checks it, and nothing else ever will.
            $row = TransactionVerification::create([
                'uuid' => (string) Str::uuid(),
                'subject_type' => $request->subjectType,
                'subject_id' => (string) $request->subjectId,
                'merchant_id' => $request->merchantId,
                'status' => VerificationStatusEnum::PROCESSING,
                'attempts' => 1,
                'expected_amount_minor' => $request->expectedAmountMinor,
                'currency' => $request->currency,
                'expected_destination' => ['type' => $destination->type->value, 'value' => $destination->value],
                'expected_destination_hash' => $this->blindIndex($destination->type->value.':'.$this->matcher->normaliseDestination($destination)),
                'file_mime' => (string) $file->getMimeType(),
                'file_size' => (int) $file->getSize(),
                'file_sha256' => hash_file('sha256', $file->getRealPath()),
                'idempotency_key' => $request->idempotencyKey,
                'context' => $request->context,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent submit with the same key won the insert. A locking read sees its
            // committed row even inside a caller's REPEATABLE READ transaction.
            $winner = TransactionVerification::where('idempotency_key', $request->idempotencyKey)->sharedLock()->first();

            return $winner ? $this->toResult($winner) : throw $e;
        }

        // Read straight from the upload: the receipt is never stored, and PHP deletes the upload when the request ends.
        try {
            $completed = $this->check($row, $file->getRealPath());
        } catch (Throwable $e) {
            // The class only: a message may quote the receipt. The row keeps the detail.
            rescue(fn () => Log::warning('transaction-verification.failed', ['id' => $row->id, 'exception' => $e::class]), report: false);
            $completed = null;
        }

        // Outside the try: a throwing listener (or a broken reporter) never turns a stored verdict into 'failed'.
        if ($completed) {
            $this->quietly(fn () => event($completed));
        }

        // A database gone after the verdict: the row as created, rather than throwing at the caller.
        return $this->toResult(rescue(fn () => $row->fresh(), null, report: false) ?? $row);
    }

    public function latestFor(string $subjectType, string|int $subjectId): ?VerificationResult
    {
        $row = TransactionVerification::where('subject_type', $subjectType)
            ->where('subject_id', (string) $subjectId)
            ->latest('id')
            ->first();

        return $row ? $this->toResult($row) : null;
    }

    public function find(string $uuid): ?VerificationResult
    {
        $row = TransactionVerification::where('uuid', $uuid)->first();

        return $row ? $this->toResult($row) : null;
    }

    /** Newest first. Not on the contract: only the HTTP API lists history so far. */
    public function history(string $subjectType, string|int $subjectId, int $perPage): LengthAwarePaginator
    {
        return TransactionVerification::where('subject_type', $subjectType)
            ->where('subject_id', (string) $subjectId)
            ->latest('id')
            ->paginate($perPage)
            ->through(fn (TransactionVerification $row) => $this->toResult($row));
    }

    /**
     * Scheduled. A check that died mid-way (crash, time limit) can't be retried without the receipt: a person checks it.
     * Pending: rows queued by 0.1 and never read.
     */
    public function failStale(): void
    {
        $stale = fn () => TransactionVerification::where('updated_at', '<', now()->subMinutes((int) config('transaction-verification.stale_minutes')))
            ->whereIn('status', [VerificationStatusEnum::PENDING->value, VerificationStatusEnum::PROCESSING->value]);
        $rows = $stale()->get(['id', 'subject_type', 'subject_id', 'reference_hash']);

        $stale()->whereKey($rows->modelKeys())
            ->update(['status' => VerificationStatusEnum::FAILED->value, 'error' => 'Gave up: the check stopped before it finished.', 'updated_at' => now()]);

        // One that died after storing its reference never told the copies that finished before it.
        $rows->each(fn (TransactionVerification $row) => $this->recheckLaterCopies($row, $row->reference_hash));
    }

    private function check(TransactionVerification $row, string $path): ?TransactionVerificationCompleted
    {
        $id = $row->id;
        $attempt = $row->attempts;
        $referenceHash = null;
        $started = hrtime(true);

        try {
            if ($row->file_size > (int) config('transaction-verification.max_file_bytes')) {
                throw new RuntimeException('The receipt file is too large to read.');
            }

            $step = hrtime(true);
            [$text, $fields, $early, $passes] = $this->firstRead($path, $row);
            $timing = ['first_ms' => $this->ms($step)];
            // Before the reference is hashed: on 'fallback' the second engine may supply it.
            [$fields, $secondText, $secondMs] = $this->secondRead($path, $row, $fields, $early);
            $timing['second_ms'] = $secondText === null ? null : $secondMs;
            $timing['passes'] = $passes;

            // Normalised before the emptiness check: blank references must not all share one hash.
            $reference = $this->matcher->normaliseReference($fields->reference);
            $referenceHash = $reference === null ? null : $this->blindIndex('ref:'.$reference);

            // Stored before the lookup: either a later copy's lookup sees it, or this row's later-copies check sees that copy.
            $this->claimed($id, $attempt)->update(['reference_hash' => $referenceHash]);

            $decision = $this->verdictFor($row, $fields, $referenceHash);

            // Before the row lock: version() may spawn a process.
            [$engineName, $engineVersion] = $secondText === null
                ? [$this->engine->name(), $this->engine->version()]
                : [$this->engine->name().'+'.$this->secondEngine->name(), Str::limit($this->engine->version().' + '.$this->secondEngine->version(), 60)];

            $stored = DB::connection(config('transaction-verification.connection'))->transaction(function () use ($id, $attempt, $decision, $fields, $engineName, $engineVersion) {
                $current = TransactionVerification::whereKey($id)->lockForUpdate()->first();

                // The sweep gave up on it meanwhile: failed stays failed.
                if ($current?->status !== VerificationStatusEnum::PROCESSING || $current->attempts !== $attempt) {
                    return false;
                }

                return $current->update([
                    'status' => VerificationStatusEnum::COMPLETED,
                    'verdict' => $decision['verdict'],
                    'checks' => $decision['checks'],
                    'confidence' => $decision['confidence'],
                    'extracted' => $fields->toArray(),
                    'engine' => $engineName,
                    'engine_version' => $engineVersion,
                    'processed_at' => now(),
                    'error' => null,
                ]);
            });

            if (! $stored) {
                return null;
            }

            // Numbers only: where a scan's time goes, from the real server.
            $this->quietly(fn () => Log::info('transaction-verification.timing', ['id' => $id, 'engine' => $engineName, ...$timing, 'total_ms' => $this->ms($started)]));

            // Best effort: the verdict is stored, so a failure here is reported, never turned into 'failed'.
            $this->recheckLaterCopies($row, $referenceHash);

            // An original whose reference landed after this lookup, while this verdict was on its way, is seen now.
            $redecided = rescue(fn () => $this->redecide($id), fn (Throwable $e) => $this->quietly(fn () => report($e)), report: false);

            // Unchanged here, but another request may have re-decided this row meanwhile: announce what is stored now,
            // so the last event is never an older verdict.
            $current = $redecided ? null : rescue(fn () => TransactionVerification::find($id), null, report: false);

            return $redecided ?? ($current?->status === VerificationStatusEnum::COMPLETED
                ? $this->completedEvent($current, ['verdict' => $current->verdict, 'checks' => $current->checks ?? []])
                : $this->completedEvent($row, $decision));
        } catch (Throwable $e) {
            // A query, not the model: a half-filled model must not save its verdict next to 'failed'.
            // Only while this run holds it: a row the sweep marked failed keeps its reason.
            rescue(fn () => $this->claimed($id, $attempt)
                ->update(['status' => VerificationStatusEnum::FAILED->value, 'error' => $this->errorText($e), 'updated_at' => now()]), report: false);

            // A failed row keeps its stored reference, so copies that ran before it still get caught.
            $this->recheckLaterCopies($row, $referenceHash);

            throw $e;
        }
    }

    /**
     * The second engine, per config second_engine.mode (see the config). A failure never fails the row: it reads
     * nothing, so on 'always' every deciding field drops to 0 and a match waits for a person.
     *
     * @param  ?array{string, ExtractedFields, int}  $early  askSecond()'s result if it already read alongside Tesseract
     * @return array{ExtractedFields, ?string, int} the fields to decide on, the second engine's text (null: not asked)
     *                                              and how long it took
     */
    private function secondRead(string $path, TransactionVerification $row, ExtractedFields $fields, ?array $early = null): array
    {
        $mode = SecondEngineModeEnum::current();
        $threshold = (float) config('transaction-verification.confidence_threshold');
        $expected = new ExpectedDestination(DestinationTypeEnum::from($row->expected_destination['type']), $row->expected_destination['value']);

        if ($mode === SecondEngineModeEnum::OFF || ($mode === SecondEngineModeEnum::FALLBACK && $this->matcher->sure($expected, $fields, $threshold))) {
            return [$fields, null, 0];
        }

        [$text, $second, $ms] = $early ?? $this->askSecond($path, $row->id);

        return [$mode === SecondEngineModeEnum::FALLBACK ? $fields->filledFrom($second, $threshold) : $fields->confirmedBy($second), $text, $ms];
    }

    /**
     * The first engine. On 'always' RapidOCR reads while Tesseract's passes run, and Tesseract reads only its first
     * early_passes unless they and RapidOCR don't agree ('fallback' needs Tesseract's result first; 'off' never asks).
     *
     * @return array{string, ExtractedFields, ?array{string, ExtractedFields, int}, ?int} the text, its fields,
     *                                                                                    askSecond()'s result if it already ran, and the Tesseract passes read (null: another engine)
     */
    private function firstRead(string $path, TransactionVerification $row): array
    {
        $parse = fn (string $text) => $text === '' ? new ExtractedFields : $this->parser->parse($text);

        if (! $this->engine instanceof TesseractEngine) {
            $text = $this->engine->read($path);

            return [$text, $parse($text), null, null];
        }

        $all = config('transaction-verification.tesseract.passes');

        if (SecondEngineModeEnum::current() !== SecondEngineModeEnum::ALWAYS) {
            $text = $this->engine->read($path);

            return [$text, $parse($text), null, count($all)];
        }

        $early = null;
        $n = (int) config('transaction-verification.tesseract.early_passes');
        $first = $n > 0 && $n < count($all) ? array_slice($all, 0, $n) : $all;
        $text = $this->engine->read($path, function () use (&$early, $path, $row) {
            $early = $this->askSecond($path, $row->id);
        }, $first);
        $fields = $parse($text);

        if (count($first) < count($all) && ! $this->agreed($row, $fields, $early)) {
            $more = $this->engine->read($path, passes: array_slice($all, count($first)));
            $text = implode("\f", array_filter([$text, $more], fn (string $read) => $read !== ''));
            [$fields, $first] = [$parse($text), $all];
        }

        return [$text, $fields, $early, count($first)];
    }

    /** Tesseract's first passes and RapidOCR both read the amount, the destination and the reference, surely and alike. */
    private function agreed(TransactionVerification $row, ExtractedFields $fields, ?array $early): bool
    {
        if ($early === null || $early[0] === '') {
            return false;
        }

        $expected = new ExpectedDestination(DestinationTypeEnum::from($row->expected_destination['type']), $row->expected_destination['value']);
        $threshold = (float) config('transaction-verification.confidence_threshold');

        // The reference too: it finds a receipt reused for another payout, so fewer passes must not lose it.
        foreach ([...$this->matcher->decidingKeys($expected, $fields), 'reference'] as $key) {
            $field = $key === 'amount' ? 'amountMinor' : $key;

            if ($fields->$field === null || ($fields->confidence[$key] ?? 1.0) < $threshold || $fields->$field !== $early[1]->$field) {
                return false;
            }
        }

        return true;
    }

    /** @return array{string, ExtractedFields, int} the second engine's text ('' when it failed), its fields, its ms */
    private function askSecond(string $path, int $id): array
    {
        $step = hrtime(true);

        try {
            $text = $this->secondEngine->read($path);
            $second = $text === '' ? new ExtractedFields : $this->parser->parse($text);
        } catch (Throwable $e) {
            $this->quietly(fn () => Log::warning('transaction-verification.second-engine-failed', ['id' => $id, 'exception' => $e::class]));
            [$text, $second] = ['', new ExtractedFields];
        }

        return [$text, $second, $this->ms($step)];
    }

    /** A later copy that ran before this row's reference was stored missed it; each one is checked again. */
    private function recheckLaterCopies(TransactionVerification $row, ?string $referenceHash): void
    {
        if ($referenceHash === null) {
            return;
        }

        $this->quietly(fn () => $this->recheck($this->duplicates->laterCopies($row, $referenceHash)));
    }

    /** @param  list<int>  $ids */
    private function recheck(array $ids): void
    {
        foreach ($ids as $id) {
            $this->quietly(function () use ($id) {
                // Finished: decided again from its stored reading (no receipt to re-read). One still being checked
                // decides itself again once its verdict is stored.
                if ($event = $this->redecide($id)) {
                    event($event);
                }
            });
        }
    }

    private function redecide(int $id): ?TransactionVerificationCompleted
    {
        return DB::connection(config('transaction-verification.connection'))->transaction(function () use ($id) {
            $row = TransactionVerification::whereKey($id)->lockForUpdate()->first();

            if ($row?->status !== VerificationStatusEnum::COMPLETED) {
                return null;
            }

            $decision = $this->verdictFor($row, ExtractedFields::fromArray($row->extracted ?? []), $row->reference_hash);
            // Loose: stored checks come back from JSON, so 1.0 may read as 1.
            $changed = $row->verdict !== $decision['verdict'] || $row->checks != $decision['checks'];
            $row->update(['verdict' => $decision['verdict'], 'checks' => $decision['checks'], 'confidence' => $decision['confidence']]);

            return $changed ? $this->completedEvent($row, $decision) : null;
        });
    }

    /** @return array{verdict: VerdictEnum, checks: array<string, array>, confidence: ?float} */
    private function verdictFor(TransactionVerification $row, ExtractedFields $fields, ?string $referenceHash): array
    {
        $decision = $this->matcher->decide(
            $row->expected_amount_minor,
            new ExpectedDestination(DestinationTypeEnum::from($row->expected_destination['type']), $row->expected_destination['value']),
            $fields,
            $this->duplicates->find($row, $referenceHash),
            (float) config('transaction-verification.confidence_threshold'),
        );

        return [...$decision, 'checks' => array_map(fn (CheckResult $check) => $check->toArray(), $decision['checks'])];
    }

    private function completedEvent(TransactionVerification $row, array $decision): TransactionVerificationCompleted
    {
        return new TransactionVerificationCompleted(
            $row->uuid, $row->subject_type, $row->subject_id, $decision['verdict']->value, $decision['checks'],
        );
    }

    /** The row, only while this run still holds its claim. */
    private function claimed(int $id, int $attempt): Builder
    {
        return TransactionVerification::whereKey($id)
            ->where('status', VerificationStatusEnum::PROCESSING->value)
            ->where('attempts', $attempt);
    }

    /** Runs $fn; a failure is reported, and a broken reporter is swallowed too. */
    private function quietly(callable $fn): void
    {
        rescue($fn, fn (Throwable $e) => rescue(fn () => report($e), report: false), report: false);
    }

    /** Whole milliseconds since an hrtime(true) reading. */
    private function ms(int $since): int
    {
        return intdiv(hrtime(true) - $since, 1_000_000);
    }

    private function errorText(Throwable $e): string
    {
        return Str::limit(class_basename($e).': '.$e->getMessage(), 250);
    }

    private function blindIndex(string $value): string
    {
        return hash_hmac('sha256', $value, (string) (config('transaction-verification.hmac_key') ?: config('app.key')));
    }

    private function toResult(TransactionVerification $row): VerificationResult
    {
        return new VerificationResult(
            uuid: $row->uuid,
            subjectType: $row->subject_type,
            subjectId: $row->subject_id,
            status: $row->status,
            verdict: $row->verdict,
            checks: $row->checks ?? [],
            duplicateOf: $row->checks[CheckEnum::DUPLICATE->value]['of'] ?? [],
            error: $row->error,
            extracted: ExtractedFields::fromArray($row->extracted ?? [])->masked(),
        );
    }
}
