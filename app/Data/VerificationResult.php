<?php

namespace Modules\TransactionVerification\Data;

use Modules\TransactionVerification\Enums\VerdictEnum;
use Modules\TransactionVerification\Enums\VerificationStatusEnum;

/** The public read model of a verification. $checks is keyed by check name; $extracted is what was read, masked. */
final readonly class VerificationResult
{
    public function __construct(
        public string $uuid,
        public string $subjectType,
        public string $subjectId,
        public VerificationStatusEnum $status,
        public ?VerdictEnum $verdict,
        public array $checks,
        public array $duplicateOf,
        public ?string $error,
        public array $extracted = [],
    ) {}
}
