<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Modules\TransactionVerification\Services\AmountParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Modules\TransactionVerification\Tests\TestCase;

class AmountParserTest extends TestCase
{
    /** @return array<string, array{?string, ?int}> */
    public static function amounts(): array
    {
        return [
            'whole amount, no decimals (fixture 01)' => ['2,050', 205000],
            'with currency' => ['1,550 EGP', 155000],
            'piastres (fixture 05)' => ['20.10', 2010],
            'one decimal digit lost a digit' => ['20.1', null],
            'thousands and piastres' => ['5,042.06', 504206],
            'arabic-indic digits and separator' => ['٢١٨٫٢٧ ج.م', 21827],
            'persian digits' => ['۱۲۳', 12300],
            'empty' => ['', null],
            'null' => [null, null],
            'no digits' => ['EGP', null],
            'three decimals' => ['1.234', null],
            'two dots' => ['12.3.4', null],
            'decimal comma is not a thousands separator' => ['20,10', null],
            'short comma group' => ['1,55', null],
            'lost leading zero' => ['.50 EGP', null],
            'too many digits to be an amount' => ['123456789012345678', null],
            'trailing punctuation' => ['2,050.', 205000],
            'two amounts' => ['20.10 and 30.00', null],
            'negative' => ['-20.10', null],
            'trailing minus (RTL)' => ['٢٠٫١٠-', null],
            'unicode minus' => ["\u{2212}20.10", null],
            'en dash' => ["\u{2013}20.10", null],
            'accounting parentheses' => ['(20.10)', null],
            'direction mark between sign and number' => ["-\u{200F}20.10", null],
            'direction mark then space before a trailing minus' => ["20.10\u{200F} -", null],
            'doubled trailing separator' => ['20..', null],
        ];
    }

    #[DataProvider('amounts')]
    public function test_parses_receipt_amounts_into_piastres(?string $raw, ?int $expected): void
    {
        $this->assertSame($expected, (new AmountParser)->parse($raw));
    }
}
