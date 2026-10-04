<?php

namespace Modules\TransactionVerification\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a verdict is stored. Carries no phone, handle, image or OCR text.
 * Can fire again for the same uuid (a later copy re-checked into 'duplicate'): the latest one is current.
 */
class TransactionVerificationCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $uuid,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $verdict,
        public readonly array $checks,
    ) {}
}
