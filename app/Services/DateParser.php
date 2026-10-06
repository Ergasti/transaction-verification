<?php

namespace Modules\TransactionVerification\Services;

use Carbon\CarbonImmutable;

/** Finds a receipt date ("26 Sep 2026 07:29 PM") in a line and returns it as ISO-8601 in Cairo time, or null. */
class DateParser
{
    private const MONTH = '(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*';

    public function parse(?string $raw): ?string
    {
        $text = Digits::fold((string) preg_replace('/\p{Cf}/u', '', (string) $raw));
        $time = '(\d{4})\s+(\d{1,2}):(\d{2})\s*([AP]M)';

        if (preg_match('/(?<!\d)(\d{1,2})\s+'.self::MONTH.'\s+'.$time.'/i', $text, $m)) {
            [, $day, $month, $year, $hour, $minute, $meridiem] = $m;
        } elseif (preg_match('/'.self::MONTH.'\s+'.$time.'\s+(\d{1,2})(?!\d)/i', $text, $m)) {
            // Arabic receipts: the bidi reordering moves the day to the end ("Sep 2026 12:05 PM 29").
            [, $month, $year, $hour, $minute, $meridiem, $day] = $m;
        } else {
            return null;
        }

        $formatted = sprintf('%d %s %s %d:%s %s', $day, ucfirst(strtolower($month)), $year, $hour, $minute, strtoupper($meridiem));

        return rescue(function () use ($formatted) {
            $date = CarbonImmutable::createFromFormat('!j M Y g:i A', $formatted, 'Africa/Cairo');

            // createFromFormat rolls "31 Feb" over into March; a real receipt date round-trips.
            return $date && $date->format('j M Y g:i A') === $formatted ? $date->toIso8601String() : null;
        }, null, report: false);
    }
}
