<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Carbon\CarbonImmutable;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Services\Parsers\InstaPayReceiptParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Modules\TransactionVerification\Tests\TestCase;

/** Recorded Tesseract output of the real fixtures (3 passes each), kept out of git: those tests skip without the files. */
class InstaPayReceiptParserTest extends TestCase
{
    /** @return array<string, array{string, int, string, ?string, ?string, ?string}> */
    public static function receipts(): array
    {
        return [
            '01 details' => ['01', 307000, '01000000001', '100000000001', '2026-03-14 15:17', 'Transaction Details'],
            '02 details' => ['02', 123000, '01000000002', '100000000002', '2026-03-14 13:26', 'Transaction Details'],
            '03 details' => ['03', 148000, '01000000003', '100000000003', '2026-03-14 10:42', 'Transaction Details'],
            '04 same transfer as 02' => ['04', 123000, '01000000002', '100000000002', '2026-03-14 13:26', 'Transaction Details'],
            '05 successful, piastres' => ['05', 3040, '01100000005', '100000000005', '2026-03-17 11:08', 'Transaction Successful'],
            '06 raw screenshot, details collapsed' => ['06', 3040, '01100000005', null, null, 'Transaction Successful'],
            '07 arabic UI, same transfer as 05' => ['07', 3040, '01100000005', '100000000005', '2026-03-17 11:08', null],
            '08 arabic UI, same transfer as 01' => ['08', 307000, '01000000001', '100000000001', '2026-03-14 15:17', null],
            '09 arabic UI, whole amount' => ['09', 1500, '01100000005', '100000000009', '2026-03-17 16:51', null],
        ];
    }

    #[DataProvider('receipts')]
    public function test_it_reads_every_recorded_receipt(string $id, int $amount, string $phone, ?string $reference, ?string $date, ?string $status): void
    {
        $fields = $this->parse($this->recorded($id));

        $this->assertSame($amount, $fields->amountMinor);
        $this->assertSame('EGP', $fields->currency);
        $this->assertSame($phone, $fields->phone);
        $this->assertNull($fields->handle);
        $this->assertSame($reference, $fields->reference);
        $this->assertSame($date === null ? null : CarbonImmutable::parse($date, 'Africa/Cairo')->toIso8601String(), $fields->occurredAt);
        $this->assertSame($status, $fields->statusText);
        // Every pass agreed on every field it read.
        $this->assertSame(array_fill_keys($reference === null ? ['amount', 'phone'] : ['amount', 'phone', 'reference'], 1.0), $fields->confidence);
    }

    /** @return array<string, array{string, ?string, ?string, string, string}> */
    public static function otherDestinations(): array
    {
        return [
            '10 bank account' => ['10', null, '1000000000000000010', '100000000010', '2026-03-18 14:10'],
            '11 card' => ['11', null, '4000000000000011', '100000000011', '2026-03-18 14:11'],
            '12 mobile wallet' => ['12', '01000000012', null, '100000000012', '2026-03-18 14:12'],
            '13 arabic UI, bank account, one pass lost the faint number' => ['13', null, '1000000000000000010', '100000000010', '2026-03-18 14:10'],
            '14 arabic UI, card' => ['14', null, '4000000000000011', '100000000011', '2026-03-18 14:11'],
            '15 arabic UI, mobile wallet' => ['15', '01000000012', null, '100000000012', '2026-03-18 14:12'],
        ];
    }

    #[DataProvider('otherDestinations')]
    public function test_it_reads_the_bank_card_and_wallet_receipts(string $id, ?string $phone, ?string $account, string $reference, string $date): void
    {
        $fields = $this->parse($this->recorded($id));

        $this->assertSame(2500, $fields->amountMinor);
        $this->assertSame($phone, $fields->phone);
        $this->assertSame($account, $fields->account);
        $this->assertNull($fields->handle);
        $this->assertSame($reference, $fields->reference);
        $this->assertSame(CarbonImmutable::parse($date, 'Africa/Cairo')->toIso8601String(), $fields->occurredAt);
        $this->assertSame(array_fill_keys(['amount', $phone === null ? 'account' : 'phone', 'reference'], 1.0), $fields->confidence);
    }

    public function test_the_to_label_alone_or_with_its_destination_kind(): void
    {
        foreach (['To', '(=) To', '1 إلى', 'إلى —', 'ToInstapay', 'To Mobile Wallet', 'إلى المحفظة الالكترونية', 'الى انستاباي'] as $label) {
            $this->assertSame('01000000001', $this->parse("{$label}\nX*** Y***\n01000000001")->phone, $label);
        }

        // Alone, the label takes nothing after it: these are the start of a sender's note.
        foreach (['to someone', 'To 01000000001', 'إلى صديقي'] as $line) {
            $this->assertNull($this->parse("{$line}\n01000000001")->phone, $line);
        }
    }

    public function test_the_long_number_under_to_is_the_account_even_split_at_a_space(): void
    {
        $this->assertSame('1000000000000000010', $this->parse("To\nX*** Y***\n10000000 00000000010\nReference 100000000001")->account);
        // A phone or a reference is never an account; two different accounts are never guessed between.
        $this->assertNull($this->parse("To\n01000000001\n100000000001")->account);
        $this->assertNull($this->parse("To\n1000000000000000010\n1000000000000000011")->account);
        // Outside the "To" block (sender, note) a long number is nobody's account.
        $this->assertNull($this->parse("From\n1000000000000000010\nTo\nX*** Y***")->account);
        $this->assertNull($this->parse("To\nX*** Y***\nNote 1000000000000000010")->account);
    }

    public function test_the_majority_wins_and_the_disagreement_lowers_confidence(): void
    {
        $pass = fn (string $phone) => "20.10\nTransfer Amount\nTo Instapay\n{$phone}";

        $fields = $this->parse(implode("\f", [$pass('01000000001'), $pass('01000000009'), $pass('01000000001')]));

        $this->assertSame('01000000001', $fields->phone);
        $this->assertSame(0.667, $fields->confidence['phone']);
        $this->assertSame(1.0, $fields->confidence['amount']);
    }

    public function test_one_pass_of_four_keeping_a_lost_decimal_point_drops_confidence_under_the_threshold(): void
    {
        $pass = fn (string $amount) => "{$amount} EGP\nTransfer Amount";

        $fields = $this->parse(implode("\f", [$pass('150'), $pass('150'), $pass('150'), $pass('1.50')]));

        $this->assertSame(15000, $fields->amountMinor);
        $this->assertSame(0.75, $fields->confidence['amount']);
        $this->assertLessThan(config('transaction-verification.confidence_threshold'), $fields->confidence['amount']);
    }

    public function test_a_tie_goes_to_the_earliest_pass(): void
    {
        $fields = $this->parse("To Instapay\n01000000001\fTo Instapay\n01000000009");

        $this->assertSame('01000000001', $fields->phone);
        $this->assertSame(0.5, $fields->confidence['phone']);
    }

    public function test_a_pass_that_reads_nothing_does_not_count_against_the_others(): void
    {
        $fields = $this->parse("To Instapay\n01000000001\fnoise only\fTo Instapay\n01000000001");

        $this->assertSame(1.0, $fields->confidence['phone']);
    }

    public function test_empty_text_reads_nothing(): void
    {
        $fields = $this->parse('');

        $this->assertNull($fields->amountMinor);
        $this->assertNull($fields->currency);
        $this->assertNull($fields->phone);
        $this->assertSame([], $fields->confidence);
    }

    public function test_the_recipient_comes_from_the_to_section_never_from(): void
    {
        $fields = $this->parse("From\nsender@instapay\n01000000009\nTo Instapay\nrecipient@instapay\n01000000001");

        $this->assertSame('01000000001', $fields->phone);
        $this->assertSame('recipient@instapay', $fields->handle);
    }

    public function test_a_sender_handle_alone_is_not_the_recipient_handle(): void
    {
        $fields = $this->parse("From\nsender@instapay\nTo Instapay\n01000000001");

        $this->assertNull($fields->handle);
    }

    public function test_a_pass_without_a_to_line_never_reads_the_recipient(): void
    {
        // Without its label nothing tells the recipient's phone from the sender's or a note: never guessed.
        $this->assertNull($this->parse("masked name\n01000000001")->phone);
        $this->assertNull($this->parse("01000000009\nmasked name\n01000000001")->phone);
    }

    public function test_the_amount_needs_its_label_and_a_single_number(): void
    {
        $this->assertNull($this->parse("2,050 EGP\nsomething else")->amountMinor);
        $this->assertNull($this->parse("2,050 1,550\nTransfer Amount")->amountMinor);
        $this->assertSame(205000, $this->parse("2,050 EGP\nالمبلغ المحول")->amountMinor);
    }

    public function test_arabic_digits_and_bidi_marks_are_read(): void
    {
        // Real Arabic lines start with a direction mark: unstripped, "إلى" is no longer at the line start,
        // the To section is lost and the sender's phone makes the recipient ambiguous.
        $fields = $this->parse("من\n01000000009\n\u{200F}إلى انستاباي\u{200E}\nrecipient@instapay\n\u{200F}٠١٠٠٠٠٠٠٠٠١\u{200E}\nالمرجع \u{200E}١٠٠٠٠٠٠٠٠٠٠١");

        $this->assertSame('01000000001', $fields->phone);
        $this->assertSame('recipient@instapay', $fields->handle);
        $this->assertSame('100000000001', $fields->reference);
    }

    public function test_the_reference_is_the_only_twelve_digit_number_on_a_line(): void
    {
        $this->assertSame('100000000001', $this->parse('100000000001')->reference);
        $this->assertNull($this->parse('100000000001 100000000002')->reference);
        $this->assertNull($this->parse('1000000000011')->reference);
    }

    public function test_nothing_in_the_senders_note_can_become_the_recipient(): void
    {
        // Paid to one phone, the note names the expected handle: must not match the handle.
        $toPhone = $this->parse("To Instapay\nX*** Y***\n01099999999\nReference 100000000001\nNote creator@instapay");
        $this->assertSame('01099999999', $toPhone->phone);
        $this->assertNull($toPhone->handle);

        // Paid to a handle, the note names the expected phone: must not match the phone.
        $toHandle = $this->parse("To Instapay\nsomeone@instapay\nملاحظة 01000000001");
        $this->assertSame('someone@instapay', $toHandle->handle);
        $this->assertNull($toHandle->phone);
    }

    public function test_a_sender_block_reordered_after_to_ends_the_recipient_block(): void
    {
        $fields = $this->parse("To Instapay\n01000000001\n[=] From\nsender@instapay");

        $this->assertSame('01000000001', $fields->phone);
        $this->assertNull($fields->handle);
    }

    public function test_two_different_phones_under_to_are_never_guessed(): void
    {
        $this->assertNull($this->parse("To Instapay\n01000000009\n01000000001")->phone);
    }

    public function test_a_sender_label_ends_the_recipient_block_in_any_case_and_with_a_colon(): void
    {
        foreach (['from', 'FROM', 'From:', '(>) From', 'من'] as $label) {
            $fields = $this->parse("To Instapay\nrecipient@instapay\n{$label}\n01000000009\nsender@instapay");

            $this->assertNull($fields->phone, $label);
            $this->assertSame('recipient@instapay', $fields->handle, $label);
        }
    }

    public function test_the_arabic_note_is_outside_the_recipient_block_even_when_every_label_is_lost(): void
    {
        // The shape of fixture 07, passes 2-3: no المرجع / التاريخ / ملاحظة. The reference or the date ends the block.
        $pass = fn (string $note) => "إلى انستاباى\nت*** ت***\n01099999999\n100000000005\n29 Sep 2026 09:36 AM\n{$note}\nPOWERED BY";
        $byReference = $this->parse("إلى انستاباى\nت*** ت***\n01099999999\n100000000005\ncreator@instapay");
        $byDate = $this->parse("إلى انستاباى\nت*** ت***\nother@instapay\n29 Sep 2026 09:36 AM\n01000000001");

        $this->assertNull($this->parse(implode("\f", [$pass('creator@instapay'), $pass('creator@instapay'), $pass('creator@instapay')]))->handle);
        $this->assertNull($byReference->handle);
        $this->assertSame('01099999999', $byReference->phone);
        $this->assertNull($byDate->phone);
        $this->assertSame('other@instapay', $byDate->handle);
    }

    public function test_a_pass_that_saw_two_candidates_counts_against_the_winner(): void
    {
        $fields = $this->parse("To Instapay\n01000000001\fTo Instapay\n01000000001\n01000000009\fTo Instapay\n01000000001");

        $this->assertSame('01000000001', $fields->phone);
        $this->assertSame(0.667, $fields->confidence['phone']);
    }

    public function test_an_amount_the_amount_parser_refuses_is_not_read(): void
    {
        $this->assertNull($this->parse(".50 EGP\nTransfer Amount")->amountMinor);
        $this->assertNull($this->parse("-20.10 EGP\nTransfer Amount")->amountMinor);
        $this->assertNull($this->parse("0\nTransfer Amount")->amountMinor);
        // InstaPay groups thousands: "2010" is "20.10" with the point lost, not 2,010 EGP.
        $this->assertNull($this->parse("2010 EGP\nTransfer Amount")->amountMinor);
        $this->assertNull($this->parse("Note 99\nTransfer Amount")->amountMinor);
        $this->assertSame(1000, $this->parse("10 EGP\nTransfer Amount")->amountMinor);
    }

    public function test_the_reference_ignores_the_note_and_a_plus_twenty_phone(): void
    {
        $this->assertSame('100000000001', $this->parse("Note 123456789012\nReference 100000000001")->reference);
        $this->assertSame('100000000001', $this->parse("123456789012\nReference 100000000001")->reference);
        // OCR put the note's value on the line below its label.
        $this->assertSame('100000000001', $this->parse("Note\n123456789012\n100000000001")->reference);
        $this->assertNull($this->parse('+201099999999')->reference);
        // Unlabelled and two candidates: never guessed.
        $this->assertNull($this->parse("123456789012\n100000000001")->reference);
    }

    public function test_a_note_that_starts_with_to_never_passes_for_the_to_line(): void
    {
        // Fixture 01, passes 2-3: OCR puts the note's value above "To Instapay".
        foreach (['to 01000000009', 'To Instapay 01000000009', 'to creator@instapay', 'الى انستاباي creator@instapay'] as $note) {
            $fields = $this->parse("From\nTEST SENDER\n{$note}\nVv\nTo Instapay\nت*** ت***\n01000000001\nReference 100000000001");

            $this->assertNotSame('01000000009', $fields->phone, $note);
            $this->assertNotSame('creator@instapay', $fields->handle, $note);
        }
    }

    public function test_a_note_wrapped_onto_a_to_instapay_line_never_gives_the_recipient_or_the_date(): void
    {
        // The real "To" label lost; the note's value, on the line under its label, reads like one.
        $this->assertNull($this->parse("X*** Y***\nNote\nTo Instapay\n01000000001")->phone);
        $this->assertNull($this->parse('Note 29 Sep 2026 09:36 AM')->occurredAt);
        // A note with a value on its label line, wrapping onto lines that read like the "To" block.
        $this->assertNull($this->parse("20.10\nTransfer Amount\n01000000009\nNote payout\nTo Instapay\n01000000001")->phone);
        $this->assertNull($this->parse("ملاحظة :\nإلى انستاباي\n01000000001")->phone);
    }

    public function test_a_to_line_carrying_an_identifier_counts_against_the_recipient(): void
    {
        $fields = $this->parse("To Instapay\n01000000001\fTo Instapay 01000000009\n01000000001\fTo Instapay\n01000000001");

        $this->assertSame('01000000001', $fields->phone);
        $this->assertSame(0.667, $fields->confidence['phone']);
    }

    public function test_an_amount_line_with_digits_that_cannot_be_the_amount_counts_against_it(): void
    {
        $fields = $this->parse("20.10\nTransfer Amount\f20.10 30.10\nTransfer Amount\f20.10 30.10\nTransfer Amount");

        $this->assertSame(2010, $fields->amountMinor);
        $this->assertSame(0.333, $fields->confidence['amount']);
    }

    public function test_two_labelled_amounts_that_disagree_are_never_guessed_between(): void
    {
        $this->assertNull($this->parse("20.10\nTransfer Amount\n30.10\nTransfer Amount")->amountMinor);
        $this->assertSame(2010, $this->parse("20.10\nTransfer Amount\n20.10\nالمبلغ المحول")->amountMinor);
        // A note reading like the label is not a second one.
        $this->assertSame(2010, $this->parse("20.10\nTransfer Amount\nNote Transfer Amount 30.10")->amountMinor);
    }

    public function test_a_handle_is_a_whole_address_never_a_piece_of_one(): void
    {
        $this->assertNull($this->parse("To Instapay\nalice@instapay.com")->handle);
        $this->assertNull($this->parse("To Instapay\nalice+shop@instapay")->handle);
        $this->assertSame('alice.shop@instapay', $this->parse("To Instapay\nAlice.Shop@instapay")->handle);
    }

    public function test_notes_in_both_languages(): void
    {
        $this->assertSame('Living Expenses', $this->parse('Note Living Expenses')->note);
        $this->assertSame('نفقات المعيشة', $this->parse('نفقات المعيشة ملاحظة')->note);
    }

    private function parse(string $text): ExtractedFields
    {
        return app(InstaPayReceiptParser::class)->parse($text);
    }

    /** Recorded output of real receipts: local only, like the screenshots (tests/fixtures/ocr/ is git-ignored). */
    private function recorded(string $id): string
    {
        $path = __DIR__."/../fixtures/ocr/instapay-{$id}.txt";

        if (! is_file($path)) {
            $this->markTestSkipped('Needs the recorded receipts in tests/fixtures/ocr/ (local only, not in git).');
        }

        return (string) file_get_contents($path);
    }
}
