<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Carbon\CarbonImmutable;
use Modules\TransactionVerification\Services\DateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Modules\TransactionVerification\Tests\TestCase;

class DateParserTest extends TestCase
{
    /** @return array<string, array{?string, ?string}> input => Cairo wall time */
    public static function dates(): array
    {
        return [
            'receipt format (fixture 01)' => ['26 Sep 2026 07:29 PM', '2026-09-26 19:29'],
            'after the english label' => ['Date: 26 Sep 2026 12:37 PM', '2026-09-26 12:37'],
            'noon' => ['29 Sep 2026 12:05 PM', '2026-09-29 12:05'],
            'midnight' => ['29 Sep 2026 12:05 AM', '2026-09-29 00:05'],
            'arabic UI moves the day to the end' => ['التاريخ: Sep 2026 12:05 PM 29', '2026-09-29 12:05'],
            'bidi marks and arabic-indic digits' => ["\u{200F}التاريخ:\u{200E} ٢٩ Sep ٢٠٢٦ ٠٩:٣٦ AM", '2026-09-29 09:36'],
            'winter, no DST' => ['5 Jan 2026 10:00 AM', '2026-01-05 10:00'],
            'impossible day rolls over, so it is rejected' => ['31 Feb 2026 10:00 AM', null],
            'no time' => ['26 Sep 2026', null],
            'garbage' => ['POWERED BY', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('dates')]
    public function test_it_parses_receipt_dates_in_cairo_time(?string $raw, ?string $expected): void
    {
        $this->assertSame(
            $expected === null ? null : CarbonImmutable::parse($expected, 'Africa/Cairo')->toIso8601String(),
            (new DateParser)->parse($raw),
        );
    }
}
