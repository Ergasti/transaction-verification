<?php

namespace Modules\TransactionVerification\Enums;

/** Processing lifecycle of one verification row. */
enum VerificationStatusEnum: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
