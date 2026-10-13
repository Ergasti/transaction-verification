<?php

namespace Modules\TransactionVerification\Enums;

/** Where the money should have gone; a bank destination's value is the account number. */
enum DestinationTypeEnum: string
{
    case PHONE = 'phone';
    case INSTAPAY_HANDLE = 'instapay_handle';
    case BANK = 'bank';

    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
