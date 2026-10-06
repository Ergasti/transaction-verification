<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Modules\TransactionVerification\Data\ExtractedFields;
use PHPUnit\Framework\TestCase;

/** How a second engine's reading changes the first's: 'always' (confirmedBy) and 'fallback' (filledFrom). */
class ExtractedFieldsTest extends TestCase
{
    public function test_always_a_field_read_differently_or_not_at_all_drops_to_zero_and_keeps_our_value(): void
    {
        $ours = new ExtractedFields(amountMinor: 1000, currency: 'EGP', phone: '01000000001', account: '1000000000000000010', reference: '100000000001',
            confidence: ['amount' => 1.0, 'phone' => 1.0, 'reference' => 1.0]);

        $combined = $ours->confirmedBy(new ExtractedFields(amountMinor: 1000, phone: '01000000009', reference: '100000000001'));

        $this->assertSame(['amount' => 1.0, 'phone' => 0.0, 'reference' => 1.0, 'account' => 0.0], $combined->confidence);
        $this->assertSame('01000000001', $combined->phone);
        $this->assertSame('1000000000000000010', $combined->account);
    }

    public function test_always_never_takes_a_value_only_the_second_engine_read(): void
    {
        // Filling it in would let one engine approve money on its own.
        $combined = (new ExtractedFields(amountMinor: 1000))->confirmedBy(new ExtractedFields(amountMinor: 1000, phone: '01000000001', account: '1000000000000000010', reference: '100000000001'));

        $this->assertNull($combined->phone);
        $this->assertNull($combined->account);
        $this->assertNull($combined->reference);
    }

    public function test_always_a_field_read_without_a_score_still_needs_the_second_engine(): void
    {
        // A score-less field counts as certain in the Matcher: it must not slip past the agreement check.
        $this->assertSame(['amount' => 0.0], (new ExtractedFields(amountMinor: 1000))->confirmedBy(new ExtractedFields)->confidence);
        $this->assertSame([], (new ExtractedFields(amountMinor: 1000))->confirmedBy(new ExtractedFields(amountMinor: 1000))->confidence);
    }

    public function test_fallback_fills_a_missed_field_and_confirms_an_unsure_one_it_reads_the_same(): void
    {
        $ours = new ExtractedFields(amountMinor: 1000, currency: 'EGP', reference: '100000000001', confidence: ['amount' => 0.75, 'reference' => 0.5]);

        $combined = $ours->filledFrom(new ExtractedFields(amountMinor: 1000, currency: 'EGP', phone: '01000000001', reference: '100000000009'), 0.90);

        $this->assertSame('01000000001', $combined->phone);
        $this->assertSame(['amount' => 1.0, 'reference' => 0.5, 'phone' => 1.0], $combined->confidence);
        // An unsure field the second engine reads differently is left alone (still under the threshold).
        $this->assertSame('100000000001', $combined->reference);
    }

    public function test_fallback_never_touches_a_sure_field_and_takes_the_currency_with_an_amount(): void
    {
        $sure = new ExtractedFields(amountMinor: 1000, currency: 'EGP', confidence: ['amount' => 1.0]);
        $this->assertSame(1000, $sure->filledFrom(new ExtractedFields(amountMinor: 2000, currency: 'EGP'), 0.90)->amountMinor);

        $filled = (new ExtractedFields)->filledFrom(new ExtractedFields(amountMinor: 2000, currency: 'EGP'), 0.90);
        $this->assertSame([2000, 'EGP'], [$filled->amountMinor, $filled->currency]);
    }
}
