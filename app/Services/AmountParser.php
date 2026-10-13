<?php

namespace Modules\TransactionVerification\Services;

/** Turns a receipt amount ("2,050", "20.10", "٢١٨٫٢٧ ج.م") into integer piastres, or null. No floats. */
class AmountParser
{
    // A sign before or after the number (RTL apps print "20.10-"), any dash, or accounting parentheses.
    private const NEGATIVE = '/[-\x{2212}\x{2013}\x{2014}][\s\p{Cf}]*[\d.,]|[\d.,][\s\p{Cf}]*[-\x{2212}\x{2013}\x{2014}]|\(\s*[\d.,]/u';

    public function parse(?string $raw): ?int
    {
        $text = strtr(Digits::fold((string) $raw), ['٫' => '.', '٬' => ',']);

        // Exactly one run of digits, dots and commas, not negative: two amounts or a debit is not an amount.
        if (preg_match_all('/[\d.,]*\d[\d.,]*/', $text, $m) !== 1 || preg_match(self::NEGATIVE, $text)) {
            return null;
        }

        // Commas only as thousands groups ("20,10" is an OCR'd "20.10", not 2,010); no leading dot
        // (".50" lost its zero); exactly two decimals ("20.1" lost a digit); at most 12 whole digits.
        // At most one trailing separator is sentence punctuation; "20.." is garbled.
        $number = (string) preg_replace('/[.,]$/', '', $m[0][0]);
        if (! preg_match('/^(\d{1,3}(?:,\d{3})+|\d{1,12})(?:\.(\d{2}))?$/', $number, $parts)) {
            return null;
        }

        $whole = str_replace(',', '', $parts[1]);

        if (strlen($whole) > 12) {
            return null;
        }

        return (int) $whole * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }
}
