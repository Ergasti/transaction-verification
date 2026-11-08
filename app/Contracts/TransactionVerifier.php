<?php

namespace Modules\TransactionVerification\Contracts;

use Modules\TransactionVerification\Data\VerificationRequest;
use Modules\TransactionVerification\Data\VerificationResult;

/** The module's public entry point for callers in the same process. */
interface TransactionVerifier
{
    /**
     * Checks the receipt now, reading the uploaded file (never stored), and returns the finished row: completed with
     * a verdict, or failed. Idempotent on idempotencyKey. Takes ~1-3 s, so in a web request call it after the response
     * (app()->terminating) and after your own transaction commits. Throws only when the row can't be created.
     */
    public function submit(VerificationRequest $request): VerificationResult;

    public function latestFor(string $subjectType, string|int $subjectId): ?VerificationResult;

    public function find(string $uuid): ?VerificationResult;
}
