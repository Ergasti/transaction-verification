<?php

namespace Modules\TransactionVerification\Enums;

/** The result of one check (amount, destination, duplicate, reference). */
enum CheckOutcomeEnum: string
{
    case PASS = 'pass';
    case FAIL = 'fail';
    case MISSING = 'missing';
    case NOT_APPLICABLE = 'not_applicable';

    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
