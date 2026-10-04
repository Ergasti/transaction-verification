<?php

namespace Modules\TransactionVerification\Contracts;

use Modules\TransactionVerification\Data\VerificationRequest;
use Modules\TransactionVerification\Data\VerificationResult;

/** The module's public entry point for callers in the same process. */
interface TransactionVerifier
{
    /**
     * Stores the file, creates a pending row, queues processing. Idempotent on idempotencyKey.
     * Call it after your own transaction commits: rolling back a transaction on the module's connection would drop the
     * row but not the stored file, and rolling back one on another connection would discard the queued job.
     * Throws when the file can't be stored or the job can't be queued; resubmitting with the same key is safe,
     * and the scheduled recovery re-queues a row whose job was lost anyway.
     */
    public function submit(VerificationRequest $request): VerificationResult;

    public function latestFor(string $subjectType, string|int $subjectId): ?VerificationResult;

    public function find(string $uuid): ?VerificationResult;

    /** Reads a finished receipt again with the current engines and decides again; null for an unknown uuid. */
    public function reprocess(string $uuid): ?VerificationResult;

    /** A link to the stored receipt that expires after 1–60 minutes; null for an unknown uuid. */
    public function temporaryFileUrl(string $uuid, int $minutes = 5): ?string;
}
