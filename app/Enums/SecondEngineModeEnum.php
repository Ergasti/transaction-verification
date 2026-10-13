<?php

namespace Modules\TransactionVerification\Enums;

/** When RapidOCR reads a receipt too (config second_engine.mode). */
enum SecondEngineModeEnum: string
{
    case ALWAYS = 'always';
    case FALLBACK = 'fallback';
    case OFF = 'off';

    /** Any value but 'off' or 'fallback' (a typo too) is 'always': a mistake must not switch the check off. */
    public static function current(): self
    {
        $mode = config('transaction-verification.second_engine.mode');

        return (is_string($mode) ? self::tryFrom($mode) : null) ?? self::ALWAYS;
    }
}
