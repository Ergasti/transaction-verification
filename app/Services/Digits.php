<?php

namespace Modules\TransactionVerification\Services;

/**
 * Folds Arabic-Indic (٠-٩) and Persian (۰-۹) digits to ASCII.
 * Phone::normalise() uses it and feeds the blind index: changing the map changes stored hashes.
 */
final class Digits
{
    private const MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function fold(string $text): string
    {
        return strtr($text, self::MAP);
    }
}
