<?php

namespace Modules\TransactionVerification\Enums;

/** The outcome of a completed verification. Advisory only: nothing is ever blocked on it. */
enum VerdictEnum: string
{
    case MATCH = 'match';
    case MISMATCH = 'mismatch';
    case DUPLICATE = 'duplicate';
    case NEEDS_REVIEW = 'needs_review';
    case UNREADABLE = 'unreadable';

    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
