<?php

namespace Modules\TransactionVerification\Services;

/**
 * Egyptian phone normalising, ported from the app's normalizePhoneNumber / isValidEgyptianMobile helpers so the
 * module stands alone. Best-effort: it normalises, it doesn't validate. Its output feeds the blind index, so keep it exact.
 */
final class Phone
{
    public static function normalise(?string $phone): string
    {
        if ($phone === null) {
            return '';
        }

        // Fold Arabic/Persian digits first: \D below would delete them.
        $digits = (string) preg_replace('/\D+/', '', Digits::fold(trim($phone)));
        if ($digits === '') {
            return '';
        }

        // Strip the Egypt country code (20, 020, 0020), restore the trunk 0, collapse extra leading zeros.
        $digits = (string) preg_replace('/^(?:00)?0?20/', '', $digits);
        if (isset($digits[0]) && $digits[0] === '1') {
            $digits = '0'.$digits;
        }
        $digits = (string) preg_replace('/^0+1/', '01', $digits);

        // A mobile is 11 digits; anything else comes back as-is.
        if (substr($digits, 0, 2) === '01' && strlen($digits) > 11) {
            $digits = substr($digits, 0, 11);
        }

        return $digits;
    }

    /** 010/011/012/015 plus 8 digits, after normalising. */
    public static function isEgyptianMobile(?string $phone): bool
    {
        return preg_match('/^01[0125]\d{8}$/', self::normalise($phone)) === 1;
    }
}
