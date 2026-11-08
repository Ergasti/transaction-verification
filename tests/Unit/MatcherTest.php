<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Modules\TransactionVerification\Data\ExpectedDestination;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Enums\CheckOutcomeEnum;
use Modules\TransactionVerification\Enums\DestinationTypeEnum;
use Modules\TransactionVerification\Enums\VerdictEnum;
use Modules\TransactionVerification\Services\Matcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Modules\TransactionVerification\Tests\TestCase;

/** Every rule of the verdict order and every destination branch, one row each. Synthetic numbers only. */
class MatcherTest extends TestCase
{
    private const AMOUNT = 205000;

    private const PHONE = '01000000001';

    /** @return array<string, array{ExpectedDestination, ExtractedFields, VerdictEnum}> */
    public static function cases(): array
    {
        $phone = new ExpectedDestination(DestinationTypeEnum::PHONE, self::PHONE);
        $handle = new ExpectedDestination(DestinationTypeEnum::INSTAPAY_HANDLE, 'Creator@InstaPay');
        $bank = new ExpectedDestination(DestinationTypeEnum::BANK, '1000 0000 0000-0000 010');
        $account = '1000000000000000010';

        return [
            'amount and phone match' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE, reference: '100000000001'), VerdictEnum::MATCH],
            'phone in +20 form' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: '+20 100 000 0001'), VerdictEnum::MATCH],
            'no reference still matches' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE), VerdictEnum::MATCH],
            'amount differs' => [$phone, new ExtractedFields(amountMinor: 205001, phone: self::PHONE), VerdictEnum::MISMATCH],
            'phone differs' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: '01000000002'), VerdictEnum::MISMATCH],
            'garbled phone with an extra digit' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: '010000000019'), VerdictEnum::MISMATCH],
            'garbled +20 phone with an extra digit' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: '+2010000000019'), VerdictEnum::MISMATCH],
            'garbled 0020 phone with an extra digit' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: '002010000000019'), VerdictEnum::MISMATCH],
            'right shape but not a mobile prefix' => [new ExpectedDestination(DestinationTypeEnum::PHONE, '01300000000'), new ExtractedFields(amountMinor: self::AMOUNT, phone: '01300000000'), VerdictEnum::MISMATCH],
            'landline is not a wallet phone' => [new ExpectedDestination(DestinationTypeEnum::PHONE, '02000000001'), new ExtractedFields(amountMinor: self::AMOUNT, phone: '02000000001'), VerdictEnum::MISMATCH],
            'malformed expected phone' => [new ExpectedDestination(DestinationTypeEnum::PHONE, '010000000019'), new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE), VerdictEnum::MISMATCH],
            'phone in 0020 form' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: '00201000000001'), VerdictEnum::MATCH],
            'phone in arabic-indic digits' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: '٠١٠٠٠٠٠٠٠٠١'), VerdictEnum::MATCH],
            'not an egyptian mobile' => [new ExpectedDestination(DestinationTypeEnum::PHONE, '12345'), new ExtractedFields(amountMinor: self::AMOUNT, phone: '12345'), VerdictEnum::MISMATCH],
            'amount unreadable' => [$phone, new ExtractedFields(phone: self::PHONE), VerdictEnum::UNREADABLE],
            'phone unreadable' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT), VerdictEnum::UNREADABLE],
            'low amount confidence' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE, confidence: ['amount' => 0.6]), VerdictEnum::NEEDS_REVIEW],
            'confidence exactly at threshold' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE, confidence: ['amount' => 0.8, 'phone' => 0.95]), VerdictEnum::MATCH],
            'handle matches ignoring case' => [$handle, new ExtractedFields(amountMinor: self::AMOUNT, handle: 'creator@instapay'), VerdictEnum::MATCH],
            'handle differs' => [$handle, new ExtractedFields(amountMinor: self::AMOUNT, handle: 'someone@instapay'), VerdictEnum::MISMATCH],
            'handle expected, receipt shows only a phone' => [$handle, new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE), VerdictEnum::NEEDS_REVIEW],
            'handle expected, nothing read' => [$handle, new ExtractedFields(amountMinor: self::AMOUNT), VerdictEnum::UNREADABLE],
            'bank account matches, digits only' => [$bank, new ExtractedFields(amountMinor: self::AMOUNT, account: $account), VerdictEnum::MATCH],
            'bank account differs goes to a person' => [$bank, new ExtractedFields(amountMinor: self::AMOUNT, account: '1000000000000000011'), VerdictEnum::NEEDS_REVIEW],
            'bank account saved as an IBAN goes to a person' => [new ExpectedDestination(DestinationTypeEnum::BANK, 'EG38 0019 0005 0000 0000 0000 0010'), new ExtractedFields(amountMinor: self::AMOUNT, account: $account), VerdictEnum::NEEDS_REVIEW],
            'bank account unread goes to a person' => [$bank, new ExtractedFields(amountMinor: self::AMOUNT), VerdictEnum::NEEDS_REVIEW],
            'low account confidence' => [$bank, new ExtractedFields(amountMinor: self::AMOUNT, account: $account, confidence: ['account' => 0.5, 'phone' => 1.0]), VerdictEnum::NEEDS_REVIEW],
            'phone expected, receipt shows only a handle' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, handle: 'creator@instapay'), VerdictEnum::UNREADABLE],

            // Precedence: each row triggers two rules, so reordering the arms fails a row.
            'unreadable wins over low confidence' => [$phone, new ExtractedFields(phone: self::PHONE, confidence: ['phone' => 0.1]), VerdictEnum::UNREADABLE],
            'unreadable wins over mismatch' => [$phone, new ExtractedFields(phone: '01000000002'), VerdictEnum::UNREADABLE],
            'mismatch wins over not-applicable' => [$bank, new ExtractedFields(amountMinor: 1), VerdictEnum::MISMATCH],
            'mismatch wins over handle-shows-phone' => [$handle, new ExtractedFields(amountMinor: 1, phone: self::PHONE), VerdictEnum::MISMATCH],
            // A failed check counts as a mismatch only when that field was read surely; an unsure one goes to a person.
            'an unsure failed amount goes to review' => [$phone, new ExtractedFields(amountMinor: 1, phone: self::PHONE, confidence: ['amount' => 0.1]), VerdictEnum::NEEDS_REVIEW],
            'amount differs but the passes split' => [$phone, new ExtractedFields(amountMinor: 205001, phone: self::PHONE, confidence: ['amount' => 0.5]), VerdictEnum::NEEDS_REVIEW],
            'phone differs but the engines disagreed' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: '01000000002', confidence: ['amount' => 1.0, 'phone' => 0.0]), VerdictEnum::NEEDS_REVIEW],
            'handle differs but the engines disagreed' => [$handle, new ExtractedFields(amountMinor: self::AMOUNT, handle: 'creatoro@instapay', confidence: ['amount' => 1.0, 'handle' => 0.0]), VerdictEnum::NEEDS_REVIEW],
            'a sure failed amount is a mismatch even with the phone unsure' => [$phone, new ExtractedFields(amountMinor: 205001, phone: self::PHONE, confidence: ['amount' => 1.0, 'phone' => 0.0]), VerdictEnum::MISMATCH],
            'a surely different phone is a mismatch' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: '01000000002', confidence: ['amount' => 1.0, 'phone' => 1.0]), VerdictEnum::MISMATCH],
            'low phone confidence' => [$phone, new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE, confidence: ['amount' => 0.99, 'phone' => 0.5]), VerdictEnum::NEEDS_REVIEW],
            'low handle confidence is used for a handle' => [$handle, new ExtractedFields(amountMinor: self::AMOUNT, handle: 'creator@instapay', confidence: ['handle' => 0.5, 'phone' => 0.99]), VerdictEnum::NEEDS_REVIEW],
        ];
    }

    #[DataProvider('cases')]
    public function test_verdict(ExpectedDestination $expected, ExtractedFields $found, VerdictEnum $verdict): void
    {
        $this->assertSame($verdict, (new Matcher)->decide(self::AMOUNT, [$expected], $found, 0.80)['verdict']);
    }

    public function test_missing_reference_is_recorded_but_does_not_change_the_verdict(): void
    {
        $decision = (new Matcher)->decide(
            self::AMOUNT,
            [new ExpectedDestination(DestinationTypeEnum::PHONE, self::PHONE)],
            new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE),
            0.80,
        );

        $this->assertSame(VerdictEnum::MATCH, $decision['verdict']);
        $this->assertSame(CheckOutcomeEnum::MISSING, $decision['checks']['reference']->outcome);
    }

    public function test_a_blank_reference_counts_as_missing(): void
    {
        $matcher = new Matcher;

        foreach ([' ', "\n\t", "\u{00A0}"] as $blank) {
            $this->assertNull($matcher->normaliseReference($blank));
        }
        $this->assertSame('ABC123', $matcher->normaliseReference(" abc 123\u{00A0}"));
    }

    public function test_checks_never_carry_the_phone_or_handle(): void
    {
        foreach ([
            [new ExpectedDestination(DestinationTypeEnum::PHONE, self::PHONE), new ExtractedFields(amountMinor: 1, phone: '01000000002')],
            [new ExpectedDestination(DestinationTypeEnum::INSTAPAY_HANDLE, 'creator@instapay'), new ExtractedFields(amountMinor: 1, handle: 'other@instapay')],
            [new ExpectedDestination(DestinationTypeEnum::BANK, '1000000000000000010'), new ExtractedFields(amountMinor: 1, account: '1000000000000000011')],
        ] as [$expected, $found]) {
            $checks = (new Matcher)->decide(self::AMOUNT, [$expected], $found, 0.80)['checks'];
            $json = json_encode(array_map(fn ($check) => $check->toArray(), $checks));

            $this->assertStringNotContainsString('0100000000', $json);
            $this->assertStringNotContainsString('instapay', $json);
            $this->assertStringNotContainsString('000000000000', $json);
        }
    }

    /** @return array<string, array{list<ExpectedDestination>, ExtractedFields, VerdictEnum}> */
    public static function anyOf(): array
    {
        $phone = new ExpectedDestination(DestinationTypeEnum::PHONE, self::PHONE);
        $handle = new ExpectedDestination(DestinationTypeEnum::INSTAPAY_HANDLE, 'creator@instapay');
        $iban = new ExpectedDestination(DestinationTypeEnum::BANK, 'EG38 0019 0005 0000 0000 0000 0010');

        return [
            'bank paid by instapay to its phone' => [[$iban, $phone], new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE), VerdictEnum::MATCH],
            'phone differs from the method phone' => [[$iban, $phone], new ExtractedFields(amountMinor: self::AMOUNT, phone: '01000000002'), VerdictEnum::MISMATCH],
            'nothing read goes to a person' => [[$iban, $phone], new ExtractedFields(amountMinor: self::AMOUNT), VerdictEnum::NEEDS_REVIEW],
            'handle method paid to its phone' => [[$handle, $phone], new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE), VerdictEnum::MATCH],
            'handle differs, no phone shown' => [[$handle, $phone], new ExtractedFields(amountMinor: self::AMOUNT, handle: 'someone@instapay'), VerdictEnum::MISMATCH],
            'primary matches, alternative differs' => [[$phone, new ExpectedDestination(DestinationTypeEnum::PHONE, '01000000002')], new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE), VerdictEnum::MATCH],
            'confidence of the matched phone decides' => [[$iban, $phone], new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE, confidence: ['phone' => 0.5, 'account' => 1.0]), VerdictEnum::NEEDS_REVIEW],
        ];
    }

    #[DataProvider('anyOf')]
    public function test_any_of_the_destinations_decides(array $expected, ExtractedFields $found, VerdictEnum $verdict): void
    {
        $this->assertSame($verdict, (new Matcher)->decide(self::AMOUNT, $expected, $found, 0.80)['verdict']);
    }

    public function test_the_confidence_comes_from_the_destination_that_matched(): void
    {
        $decision = (new Matcher)->decide(
            self::AMOUNT,
            [new ExpectedDestination(DestinationTypeEnum::BANK, 'EG38 0019 0005 0000 0000 0000 0010'), new ExpectedDestination(DestinationTypeEnum::PHONE, self::PHONE)],
            new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE, confidence: ['phone' => 0.5, 'account' => 1.0]),
            0.80,
        );

        // Only the phone's score: the account was never read, so its 1.0 must not count.
        $this->assertSame(0.5, $decision['confidence']);
        $this->assertSame(VerdictEnum::NEEDS_REVIEW, $decision['verdict']);
    }

    public function test_the_primary_decides_a_tie(): void
    {
        $both = new ExtractedFields(amountMinor: self::AMOUNT, phone: self::PHONE, handle: 'creator@instapay');
        $phone = new ExpectedDestination(DestinationTypeEnum::PHONE, self::PHONE);
        $handle = new ExpectedDestination(DestinationTypeEnum::INSTAPAY_HANDLE, 'creator@instapay');

        $this->assertSame(['amount', 'handle'], (new Matcher)->decidingKeys([$handle, $phone], $both));
        $this->assertSame(['amount', 'phone'], (new Matcher)->decidingKeys([$phone, $handle], $both));
    }

    /** @return array<string, array{int, int, CheckOutcomeEnum}> */
    public static function amounts(): array
    {
        return [
            'exact' => [1790659, 1790659, CheckOutcomeEnum::PASS],
            'piastres dropped' => [1790659, 1790600, CheckOutcomeEnum::PASS],
            'rounded up' => [1790659, 1790700, CheckOutcomeEnum::FAIL],
            'piastres partly dropped' => [1790659, 1790650, CheckOutcomeEnum::FAIL],
            'whole pounds exact' => [1790600, 1790600, CheckOutcomeEnum::PASS],
            'whole pounds, one short' => [1790600, 1790500, CheckOutcomeEnum::FAIL],
        ];
    }

    #[DataProvider('amounts')]
    public function test_amount(int $expected, int $found, CheckOutcomeEnum $outcome): void
    {
        $checks = (new Matcher)->decide(
            $expected,
            [new ExpectedDestination(DestinationTypeEnum::PHONE, self::PHONE)],
            new ExtractedFields(amountMinor: $found, phone: self::PHONE),
            0.80,
        )['checks'];

        $this->assertSame($outcome, $checks['amount']->outcome);
    }

    public function test_checks_never_carry_an_alternative_destination(): void
    {
        $checks = (new Matcher)->decide(
            self::AMOUNT,
            [new ExpectedDestination(DestinationTypeEnum::INSTAPAY_HANDLE, 'creator@instapay'), new ExpectedDestination(DestinationTypeEnum::PHONE, self::PHONE)],
            new ExtractedFields(amountMinor: 1, phone: '01000000002', handle: 'other@instapay'),
            0.80,
        )['checks'];
        $json = json_encode(array_map(fn ($check) => $check->toArray(), $checks));

        $this->assertStringNotContainsString('0100000000', $json);
        $this->assertStringNotContainsString('instapay', $json);
    }
}
