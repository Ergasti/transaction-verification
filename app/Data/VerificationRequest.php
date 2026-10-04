<?php

namespace Modules\TransactionVerification\Data;

use Illuminate\Http\UploadedFile;

/** What a caller submits: the receipt plus the values it should show. */
final readonly class VerificationRequest
{
    public function __construct(
        public string $subjectType,
        public string|int $subjectId,
        public int $expectedAmountMinor,
        public ExpectedDestination $expectedDestination,
        public UploadedFile $file,
        public string $idempotencyKey,
        public ?int $merchantId = null,
        public string $currency = 'EGP',
        public array $context = [],
    ) {
        // Column widths: a non-strict MySQL connection would silently truncate, and truncated keys collide.
        foreach (['subjectType' => [$subjectType, 64], 'subjectId' => [(string) $subjectId, 64], 'idempotencyKey' => [$idempotencyKey, 255]] as $name => [$value, $max]) {
            if ($value === '' || mb_strlen($value) > $max) {
                throw new \InvalidArgumentException("{$name} must be 1-{$max} characters.");
            }
        }

        if ($expectedAmountMinor <= 0 || ! preg_match('/^[A-Z]{3}\z/', $currency)) {
            throw new \InvalidArgumentException('expectedAmountMinor must be positive and currency a 3-letter code.');
        }
    }
}
