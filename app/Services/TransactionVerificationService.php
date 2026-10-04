<?php

namespace Modules\TransactionVerification\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\TransactionVerification\Contracts\OcrEngine;
use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\TransactionVerifier;
use Modules\TransactionVerification\Data\CheckResult;
use Modules\TransactionVerification\Data\ExpectedDestination;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Data\VerificationRequest;
use Modules\TransactionVerification\Data\VerificationResult;
use Modules\TransactionVerification\Enums\VerdictEnum;
use Modules\TransactionVerification\Enums\VerificationStatusEnum;
use Modules\TransactionVerification\Events\TransactionVerificationCompleted;
use Modules\TransactionVerification\Jobs\ProcessVerificationJob;
use Modules\TransactionVerification\Models\TransactionVerification;
use Modules\TransactionVerification\Services\Ocr\RapidOcrEngine;
use Modules\TransactionVerification\Services\Ocr\TesseractEngine;
use RuntimeException;
use Throwable;

class TransactionVerificationService implements TransactionVerifier
{
    // A worker that died on the same receipt this many times won't do better on the next try.
    private const MAX_ATTEMPTS = 3;

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
            // Its job may never have been queued (queue down). A second job is harmless: run() claims the row atomically.
            if ($existing->status === VerificationStatusEnum::PENDING) {
                $this->queue($existing->id);
                $existing = $existing->fresh();
            }

            return $this->toResult($existing);
        }

        $disk = config('transaction-verification.disk');
        $uuid = (string) Str::uuid();
        $file = $request->file;
        $folder = config('transaction-verification.folder');
        $name = $uuid.'.'.($file->extension() ?: 'bin');

        try {
            $path = $file->storeAs($folder, $name, $disk);
        } catch (Throwable $e) {
            // A timed-out reply can hide an object that did land.
            rescue(fn () => Storage::disk($disk)->delete(trim($folder.'/'.$name, '/')), report: false);

            throw $e;
        }

        if ($path === false) {
            throw new RuntimeException("Could not store the receipt on disk [{$disk}].");
        }

        $destination = $request->expectedDestination;

        try {
            $row = TransactionVerification::create([
                'uuid' => $uuid,
                'subject_type' => $request->subjectType,
                'subject_id' => (string) $request->subjectId,
                'merchant_id' => $request->merchantId,
                'status' => VerificationStatusEnum::PENDING,
                'expected_amount_minor' => $request->expectedAmountMinor,
                'currency' => $request->currency,
                'expected_destination' => ['type' => $destination->type, 'value' => $destination->value],
                'expected_destination_hash' => $this->blindIndex($destination->type.':'.$this->matcher->normaliseDestination($destination)),
                'file_disk' => $disk,
                'file_path' => $path,
                'file_mime' => (string) $file->getMimeType(),
                'file_size' => (int) $file->getSize(),
                'file_sha256' => hash_file('sha256', $file->getRealPath()),
                'idempotency_key' => $request->idempotencyKey,
                'context' => $request->context,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent submit with the same key won the insert. A locking read sees its
            // committed row even inside a caller's REPEATABLE READ transaction.
            rescue(fn () => Storage::disk($disk)->delete($path), report: false);
            $winner = TransactionVerification::where('idempotency_key', $request->idempotencyKey)->sharedLock()->first();

            if (! $winner) {
                throw $e;
            }

            // Same as the pre-check: the winner's own queue push may have failed.
            if ($winner->status === VerificationStatusEnum::PENDING) {
                $this->queue($winner->id);
                $winner = $winner->fresh();
            }

            return $this->toResult($winner);
        } catch (Throwable $e) {
            // Never leave a receipt (personal data) behind without its row.
            rescue(fn () => Storage::disk($disk)->delete($path), report: false);

            throw $e;
        }

        $this->queue($row->id);

        return $this->toResult($row->fresh());
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

    public function reprocess(string $uuid): ?VerificationResult
    {
        // Attempts keep counting, so a slow old run is still refused.
        $reopened = TransactionVerification::where('uuid', $uuid)
            ->whereIn('status', [VerificationStatusEnum::COMPLETED->value, VerificationStatusEnum::FAILED->value])
            ->update(['status' => VerificationStatusEnum::PENDING->value, 'verdict' => null, 'checks' => null, 'confidence' => null, 'extracted' => null, 'error' => null, 'processed_at' => null, 'updated_at' => now()]);

        $row = TransactionVerification::where('uuid', $uuid)->first();

        if ($row && $reopened) {
            $this->queue($row->id);
            $row = $row->fresh();
        }

        return $row ? $this->toResult($row) : null;
    }

    public function temporaryFileUrl(string $uuid, int $minutes = 5): ?string
    {
        if ($minutes < 1 || $minutes > 60) {
            throw new \InvalidArgumentException('A receipt link lasts 1-60 minutes.');
        }

        $row = TransactionVerification::where('uuid', $uuid)->first();

        return $row ? Storage::disk($row->file_disk)->temporaryUrl($row->file_path, now()->addMinutes($minutes)) : null;
    }

    /** OCR, parse, duplicate lookup, verdict. Never throws: this module warns, it doesn't fail the caller. */
    public function process(int $id): void
    {
        try {
            $completed = $this->run($id);
        } catch (Throwable $e) {
            // A failure before the claim leaves the row pending; the scheduled recovery re-queues it.
            rescue(fn () => Log::warning('transaction-verification.failed', ['id' => $id, 'error' => $this->errorText($e)]), report: false);

            return;
        }

        // Outside the try: a throwing listener (or a broken reporter) never turns a stored verdict into 'failed'.
        if ($completed) {
            $this->quietly(fn () => event($completed));
        }
    }

    /** Scheduled. Re-queues rows whose job was lost or whose worker died mid-run. */
    public function recoverStale(): void
    {
        $cutoff = now()->subMinutes((int) config('transaction-verification.stale_minutes'));
        $stale = fn () => TransactionVerification::where('updated_at', '<', $cutoff);

        $stale()->where('status', VerificationStatusEnum::PROCESSING->value)
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->update(['status' => VerificationStatusEnum::FAILED->value, 'error' => 'Gave up: claimed '.self::MAX_ATTEMPTS.' times without finishing.', 'updated_at' => now()]);

        $ids = $stale()->whereIn('status', [VerificationStatusEnum::PENDING->value, VerificationStatusEnum::PROCESSING->value])->pluck('id');

        // Back to pending so a new job can claim it; a slow old worker's result is then refused (attempts moved on).
        $stale()->whereKey($ids)
            ->whereIn('status', [VerificationStatusEnum::PENDING->value, VerificationStatusEnum::PROCESSING->value])
            ->update(['status' => VerificationStatusEnum::PENDING->value, 'updated_at' => now()]);

        $ids->each(fn (int $id) => $this->queue($id));
    }

    private function run(int $id): ?TransactionVerificationCompleted
    {
        // Atomic claim: only one job ever moves a row out of pending, so duplicate dispatches are no-ops.
        $claimed = TransactionVerification::whereKey($id)
            ->where('status', VerificationStatusEnum::PENDING->value)
            ->increment('attempts', 1, ['status' => VerificationStatusEnum::PROCESSING->value]);

        if ($claimed === 0) {
            return null;
        }

        $row = TransactionVerification::findOrFail($id);
        $attempt = $row->attempts;
        $tmp = null;
        $referenceHash = null;
        $started = hrtime(true);

        try {
            // Checked before the whole file is read into memory.
            if ($row->file_size > (int) config('transaction-verification.max_file_bytes')) {
                throw new RuntimeException('The receipt file is too large to read.');
            }

            $contents = Storage::disk($row->file_disk)->get($row->file_path);

            if ($contents === null) {
                throw new RuntimeException('The stored receipt file is missing.');
            }

            $tmp = tempnam(sys_get_temp_dir(), 'tv_');
            file_put_contents($tmp, $contents);
            $timing = ['download_ms' => $this->ms($started)];

            $step = hrtime(true);
            [$text, $fields, $early, $passes] = $this->firstRead($tmp, $row);
            $timing['first_ms'] = $this->ms($step);
            // Before the reference is hashed: on 'fallback' the second engine may supply it.
            [$fields, $secondText, $secondMs] = $this->secondRead($tmp, $row, $fields, $early);
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

            $stored = DB::connection(config('transaction-verification.connection'))->transaction(function () use ($id, $attempt, $decision, $fields, $text, $secondText, $engineName, $engineVersion) {
                $current = TransactionVerification::whereKey($id)->lockForUpdate()->first();

                // Recovered or sent back for a re-check since this run claimed it: the newer run owns the row.
                if ($current?->status !== VerificationStatusEnum::PROCESSING || $current->attempts !== $attempt) {
                    return false;
                }

                return $current->update([
                    'status' => VerificationStatusEnum::COMPLETED,
                    'verdict' => $decision['verdict'],
                    'checks' => $decision['checks'],
                    'confidence' => $decision['confidence'],
                    'extracted' => $fields->toArray(),
                    'ocr_text' => $text,
                    'second_ocr_text' => $secondText,
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
            $this->recheckLinkedCopies($row);

            return $this->completedEvent($row, $decision);
        } catch (Throwable $e) {
            // A query, not the model: a half-filled model must not save its verdict next to 'failed'.
            // Only this run's claim: a newer run of a recovered row keeps going.
            rescue(fn () => $this->claimed($id, $attempt)
                ->update(['status' => VerificationStatusEnum::FAILED->value, 'error' => $this->errorText($e), 'updated_at' => now()]), report: false);

            // A failed row keeps its stored reference, so copies that ran before it still get caught.
            $this->recheckLaterCopies($row, $referenceHash);
            $this->recheckLinkedCopies($row);

            throw $e;
        } finally {
            // A failed unlink must not turn a completed row's run into a failure.
            if ($tmp !== null) {
                rescue(fn () => is_file($tmp) && unlink($tmp), report: false);
            }
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
        $mode = config('transaction-verification.second_engine.mode');
        $threshold = (float) config('transaction-verification.confidence_threshold');
        $expected = new ExpectedDestination($row->expected_destination['type'], $row->expected_destination['value']);

        // Any value but 'off' or 'fallback' (a typo too) is 'always': a mistake must not switch the check off.
        if ($mode === 'off' || ($mode === 'fallback' && $this->matcher->sure($expected, $fields, $threshold))) {
            return [$fields, null, 0];
        }

        [$text, $second, $ms] = $early ?? $this->askSecond($path, $row->id);

        return [$mode === 'fallback' ? $fields->filledFrom($second, $threshold) : $fields->confirmedBy($second), $text, $ms];
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

        if (in_array(config('transaction-verification.second_engine.mode'), ['off', 'fallback'], true)) {
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

        $expected = new ExpectedDestination($row->expected_destination['type'], $row->expected_destination['value']);
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
            $this->quietly(fn () => Log::warning('transaction-verification.second-engine-failed', ['id' => $id, 'error' => $this->errorText($e)]));
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

    /**
     * Copies once flagged through this row's reference, which a rerun may have changed. Every run, not only on a
     * change: a run that died after storing its reference leaves no trace of the old one.
     */
    private function recheckLinkedCopies(TransactionVerification $row): void
    {
        $this->quietly(fn () => $this->recheck($this->duplicates->copiesByReference($row)));
    }

    /** @param  list<int>  $ids */
    private function recheck(array $ids): void
    {
        foreach ($ids as $id) {
            $this->quietly(function () use ($id) {
                // Still running: its run can no longer store a verdict, and the new one finds this row.
                if (TransactionVerification::whereKey($id)->where('status', VerificationStatusEnum::PROCESSING->value)
                    ->update(['status' => VerificationStatusEnum::PENDING->value, 'updated_at' => now()])) {
                    $this->queue($id);

                    return;
                }

                // Finished: decided again from its stored reading. No new OCR run that could fail and lose the verdict.
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
            new ExpectedDestination($row->expected_destination['type'], $row->expected_destination['value']),
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

    private function queue(int $id): void
    {
        ProcessVerificationJob::dispatch($id)
            ->onQueue(config('transaction-verification.queue'))
            ->afterCommit();
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
            duplicateOf: $row->checks['duplicate']['of'] ?? [],
            error: $row->error,
            extracted: ExtractedFields::fromArray($row->extracted ?? [])->masked(),
        );
    }
}
