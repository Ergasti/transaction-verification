<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Enums\ReceiptFormatEnum;
use Modules\TransactionVerification\Services\Parsers\AlexBankReceiptParser;
use Modules\TransactionVerification\Services\Parsers\ReceiptRouter;
use Modules\TransactionVerification\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Recorded output of real AlexBank confirmations (Tesseract's 4 passes, and RapidOCR), with the phones and the sender
 * account made up, kept out of git: those tests skip without the files. The rest is synthetic text in both layouts.
 */
class AlexBankReceiptParserTest extends TestCase
{
    /** @return array<string, array{string, string, int, string}> */
    public static function recordings(): array
    {
        return [
            '01 two stacked, tesseract' => ['01', 'tesseract', 6038400, '01000000101'],
            // RapidOCR shrinks the 7016 px stack to 1600 px and misreads one digit: the engines disagree, a person looks.
            '01 two stacked, rapidocr' => ['01', 'rapid', 6038400, '01000000191'],
            '02 tesseract' => ['02', 'tesseract', 1387600, '01200000102'],
            '02 rapidocr' => ['02', 'rapid', 1387600, '01200000102'],
            '03 tesseract' => ['03', 'tesseract', 877600, '01500000103'],
            '03 rapidocr' => ['03', 'rapid', 877600, '01500000103'],
        ];
    }

    #[DataProvider('recordings')]
    public function test_it_reads_every_recorded_confirmation(string $id, string $engine, int $amount, string $phone): void
    {
        $text = $this->recorded("alexbank-{$id}.{$engine}");
        $fields = $this->parse($text);

        // The real text reaches this parser through the router too.
        $this->assertSame(ReceiptFormatEnum::ALEXBANK, (new ReceiptRouter)->parse($text)->format);

        $this->assertSame([$amount, 'EGP', $phone, 'EXECUTED'], [$fields->amountMinor, $fields->currency, $fields->phone, $fields->statusText]);
        $this->assertNull($fields->reference);
    }

    public function test_a_wallet_one_pass_garbled_lowers_its_confidence(): void
    {
        // Tesseract's second pass read the stack's second wallet as "2228": that pass can't vouch for every transfer.
        $fields = $this->parse($this->recorded('alexbank-01.tesseract'));

        $this->assertSame(['amount' => 1.0, 'phone' => 0.75], $fields->confidence);
    }

    public function test_it_reads_tesseracts_layout(): void
    {
        $fields = $this->parse(self::confirmation('3,070.00', '00201000000001'));

        $this->assertSame([307000, 'EGP', '01000000001', 'EXECUTED'], [$fields->amountMinor, $fields->currency, $fields->phone, $fields->statusText]);
        $this->assertSame(['amount' => 1.0, 'phone' => 1.0], $fields->confidence);
    }

    public function test_it_reads_rapidocrs_layout_with_a_misread_label(): void
    {
        $text = "ALEXBANK\nOBJECT\nPAY SOMEONE\nBENEFICIARY NAME\nMOBILE WALLET NUMBER\n00201000000001\nAVOUNT\nFEE\n3,070.00 EGP\n100.00 EGP\nEXECUTION DATE\n17/02/2025";

        $fields = $this->parse($text);

        $this->assertSame([307000, '01000000001'], [$fields->amountMinor, $fields->phone]);
    }

    public function test_a_split_payout_is_added_up(): void
    {
        $fields = $this->parse(self::confirmation('50,000.00', '00201000000001', '250.00').self::confirmation('10,384.00', '00201000000001'));

        $this->assertSame([6038400, '01000000001'], [$fields->amountMinor, $fields->phone]);
    }

    public function test_the_fee_never_counts(): void
    {
        $this->assertSame(307000, $this->parse(self::confirmation('3,070.00', '00201000000001', '250.00'))->amountMinor);
    }

    public function test_an_amount_no_more_than_its_fee_is_unread(): void
    {
        // The values swapped (or the amount lost and another number in its place): the fee must not pass as the amount.
        $swapped = "PAY SOMEONE\nMOBILE WALLET NUMBER\n00201000000001\nAMOUNT\nFEE\n100.00 EGP\n3,070.00 EGP\nEXECUTION DATE";

        $this->assertNull($this->parse($swapped)->amountMinor);
        $this->assertNull($this->parse(self::confirmation('100.00', '00201000000001', '250.00'))->amountMinor);
    }

    public function test_a_value_further_down_never_stands_in_for_a_lost_one(): void
    {
        // The amount garbled and the label line under the values lost: the description's number is not the fee.
        $text = str_replace("EXECUTION DATE DESCRIPTION\n17/02/2025 Marketing Fees", '17/02/2025 Bonus 2.50', self::confirmation('1O,384.00', '00201000000001', '5.00'));

        $this->assertNull($this->parse($text)->amountMinor);
    }

    /** @return array<string, array{string}> */
    public static function descriptions(): array
    {
        return [
            'an upper-case fee' => ['17/02/2025 REFERRAL FEE'],
            'an upper-case amount' => ['17/02/2025 BONUS AMOUNT'],
        ];
    }

    #[DataProvider('descriptions')]
    public function test_labels_typed_in_the_description_are_text(string $description): void
    {
        $fields = $this->parse(str_replace('17/02/2025 Marketing Fees', $description, self::confirmation('3,070.00', '00201000000001')));

        $this->assertSame([307000, '01000000001'], [$fields->amountMinor, $fields->phone]);
    }

    public function test_a_wallet_with_a_dropped_zero_is_read(): void
    {
        // Tesseract often reads "0020…" as "020…".
        $this->assertSame('01000000001', $this->parse(self::confirmation('3,070.00', '0201000000001'))->phone);
    }

    public function test_the_senders_account_is_never_the_wallet(): void
    {
        $fields = $this->parse(str_replace('00201000000001', '', self::confirmation('3,070.00', '00201000000001')));

        $this->assertNull($fields->phone);
        $this->assertSame(307000, $fields->amountMinor);
    }

    public function test_stacked_confirmations_to_two_wallets_read_no_wallet(): void
    {
        $fields = $this->parse(self::confirmation('50,000.00', '00201000000001').self::confirmation('10,384.00', '00201000000002'));

        $this->assertNull($fields->phone);
        $this->assertSame(6038400, $fields->amountMinor);
    }

    public function test_a_stacked_confirmation_without_its_wallet_reads_no_wallet(): void
    {
        $fields = $this->parse(self::confirmation('50,000.00', '00201000000001').self::confirmation('10,384.00', '2228'));

        $this->assertNull($fields->phone);
    }

    /** @return array<string, array{string}> */
    public static function garbledAmounts(): array
    {
        return [
            'a letter for a digit' => ['1O,384.00'],
            // A leading 1 read as I, l or |: the digits after it must not pass for 384.00.
            'a leading 1 read as I' => ['I0,384.00'],
            'a leading 1 read as l' => ['l0,384.00'],
            'a leading 1 read as a bar' => ['|0,384.00'],
            'a zero' => ['0.00'],
            // The 1 lost, or split off as its own token: never 384.00.
            'the leading 1 lost' => ['0,384.00'],
            'the leading 1 split off as I' => ['I 0,384.00'],
            'the leading 1 split off as a bar' => ['| 0,384.00'],
            'a leading zero, no comma' => ['0384.00'],
            // A garbled first group split off by a space: what is left of it is not the amount.
            'a garbled group split off' => ['1O, 384.00'],
            'a garbled group split off, no comma' => ['1O 384.00'],
            'a leading 1 read as I, split off' => ['I0, 384.00'],
            'no piastres' => ['10,384'],
            'a misread separator' => ['10,384,00'],
        ];
    }

    #[DataProvider('garbledAmounts')]
    public function test_a_garbled_amount_in_a_stack_reads_no_sum_never_a_smaller_one(string $garbled): void
    {
        $fields = $this->parse(self::confirmation('50,000.00', '00201000000001').self::confirmation($garbled, '00201000000001'));

        $this->assertNull($fields->amountMinor);
    }

    public function test_an_amount_row_missing_its_fee_reads_no_amount(): void
    {
        // With one number only, it could be the fee.
        $text = "PAY SOMEONE\nMOBILE WALLET NUMBER\n00201000000001\nAMOUNT FEE\n100.00 EGP\nEXECUTION DATE";

        $this->assertNull($this->parse($text)->amountMinor);
    }

    public function test_a_fee_followed_by_a_date_is_never_the_amount(): void
    {
        // RapidOCR's layout with the amount line and the next label lost: what is left is the fee, then the date.
        $text = "PAY SOMEONE\nMOBILE WALLET NUMBER\n00201000000001\nAMOUNT\nFEE\n100.00 EGP\n17/02/2025";

        $this->assertNull($this->parse($text)->amountMinor);
    }

    public function test_a_fee_followed_by_a_later_number_is_never_the_amount(): void
    {
        $text = "PAY SOMEONE\nMOBILE WALLET NUMBER\n00201000000001\nAMOUNT\nFEE\n100.00 EGP\nINV 2025";

        $this->assertNull($this->parse($text)->amountMinor);
    }

    public function test_a_fee_followed_by_smaller_money_is_never_the_amount(): void
    {
        // The amount line and what follows it lost, a money-shaped description under the fee: it pairs with the fee.
        foreach ([
            "AMOUNT\nFEE\n100.00 EGP\n50.00 EGP",
            "AMOUNT FEE\n100.00 EGP\n50.00",
            "AMOUNT\nFEE\n100.00 EGP\n50.00 EGP\nMarketing Fees",
            "AMOUNT\nFEE\n100.00 EGP\n50.00 EGP\n17/02/2025",
        ] as $rows) {
            $this->assertNull($this->parse("PAY SOMEONE\nMOBILE WALLET NUMBER\n00201000000001\n{$rows}")->amountMinor, $rows);
        }
    }

    public function test_a_misread_fee_still_lets_the_amount_through(): void
    {
        // A real pass read the fee "100.00" as "100,00": the amount next to it is still right.
        $this->assertSame(307000, $this->parse(self::confirmation('3,070.00', '00201000000001', '100,00'))->amountMinor);
    }

    public function test_a_confirmation_counted_by_any_of_its_labels_keeps_a_partial_stack_unread(): void
    {
        // The second confirmation's marker read in title case, its amount and wallet lost: the first's alone is no sum.
        $text = "ALEXBANK\nPay Someone 17/02/2025\nSTATUS\nEXECUTED\nBENEFICIARY NAME MOBILE WALLET NUMBER\nPAYEE 00201000000001\n"
            ."AMOUNT FEE\n50,000.00 EGP 100.00 EGP\nPay Someone 17/02/2025\nSTATUS\nEXECUTED\n";

        $fields = $this->parse($text);

        $this->assertSame([null, null], [$fields->amountMinor, $fields->phone]);
    }

    public function test_the_sentence_repeating_pay_someone_is_not_a_confirmation(): void
    {
        // confirmation() holds both "PAY SOMEONE" and "Your order of Pay someone is confirmed": one transfer, not two.
        $this->assertSame(307000, $this->parse(self::confirmation('3,070.00', '00201000000001'))->amountMinor);
    }

    public function test_a_phone_shaped_number_outside_the_wallet_field_is_never_the_wallet(): void
    {
        $sender = str_replace('900000000001', '01000000002', self::confirmation('3,070.00', '00201000000001'));

        $this->assertSame('01000000001', $this->parse($sender)->phone);
        $this->assertNull($this->parse(str_replace('00201000000001', '', $sender))->phone);
    }

    public function test_a_confirmation_missed_whole_reads_no_sum(): void
    {
        // Two confirmations, but the second's amount row is gone: the sum would be short.
        $second = str_replace("AMOUNT FEE\n10,384.00 EGP 100.00 EGP\n", '', self::confirmation('10,384.00', '00201000000001'));

        $this->assertNull($this->parse(self::confirmation('50,000.00', '00201000000001').$second)->amountMinor);
    }

    public function test_a_pass_that_saw_part_of_the_stack_never_wins_with_its_partial_sum(): void
    {
        $one = self::confirmation('50,000.00', '00201000000001');
        $both = $one.self::confirmation('10,384.00', '00201000000001');

        // Either order: on a tie the earlier pass would win, so the partial pass must not be a candidate at all.
        foreach ([[$one, $both], [$both, $one]] as $passes) {
            $fields = $this->parse(implode("\f", $passes));

            $this->assertSame([6038400, 0.5], [$fields->amountMinor, $fields->confidence['amount']]);
        }
    }

    public function test_most_passes_win_and_one_disagreeing_lowers_confidence(): void
    {
        $good = self::confirmation('3,070.00', '00201000000001');
        $fields = $this->parse(implode("\f", [$good, $good, $good, self::confirmation('3,070.00', '00201000000002')]));

        $this->assertSame('01000000001', $fields->phone);
        $this->assertSame(0.75, $fields->confidence['phone']);
    }

    /** @return array<string, array{string, bool}> */
    public static function signals(): array
    {
        return [
            'the bank name' => ['ALEXBANK', true],
            'the transfer kind' => ['PAY SOMEONE', true],
            'the beneficiary label' => ['BENEFICIARY NAME', true],
            'the wallet label' => ['MOBILE WALLET NUMBER', true],
            'an InstaPay receipt' => ["3,070 EGP\nTransfer Amount\nTo Instapay\n01000000001", false],
            'nothing' => ['', false],
        ];
    }

    #[DataProvider('signals')]
    public function test_any_one_signal_recognises_it(string $text, bool $recognised): void
    {
        $this->assertSame($recognised, app(AlexBankReceiptParser::class)->recognises($text));
    }

    /** One confirmation as Tesseract lays it out: label rows with their values on the next line. Synthetic values. */
    public static function confirmation(string $amount, string $wallet, string $fee = '100.00'): string
    {
        return "ALEXBANK\nOBJECT DATE AND HOUR\nPAY SOMEONE 17/02/2025 19:53:17\nSTATUS\nEXECUTED\n"
            ."Your order of Pay someone is confirmed as follows:\nCUSTOMER NAME ACCOUNT\n900000000001 SENDER CO\n"
            ."TRANSACTION DETAILS\nBENEFICIARY NAME MOBILE WALLET NUMBER\nPAYEE NAME {$wallet}\n"
            ."PAYMENT TYPE PURPOSE OF TRANSFER\nMobile wallet CASH TRANSFER\nAMOUNT FEE\n{$amount} EGP {$fee} EGP\n"
            ."EXECUTION DATE DESCRIPTION\n17/02/2025 Marketing Fees\n"
            ."The fee for the transfer will be debited in accordance with the prevailing list of conditions.\n";
    }

    private function parse(string $text): ExtractedFields
    {
        return app(AlexBankReceiptParser::class)->parse($text);
    }

    /** Recorded output of real receipts: local only, like the screenshots (tests/fixtures/ocr/ is git-ignored). */
    private function recorded(string $name): string
    {
        $path = __DIR__."/../fixtures/ocr/{$name}.txt";

        if (! is_file($path)) {
            $this->markTestSkipped('Needs the recorded receipts in tests/fixtures/ocr/ (local only, not in git).');
        }

        return (string) file_get_contents($path);
    }
}
