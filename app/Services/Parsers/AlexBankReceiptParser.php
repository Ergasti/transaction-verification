<?php

namespace Modules\TransactionVerification\Services\Parsers;

use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\RecognisesReceipts;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Enums\ReceiptFormatEnum;
use Modules\TransactionVerification\Services\AmountParser;
use Modules\TransactionVerification\Services\Digits;
use Modules\TransactionVerification\Services\Phone;

/**
 * Reads an AlexBank "Pay someone" confirmation (an A4 export) from the engine's passes and votes per field.
 * A split payout is one image with several confirmations stacked: the amounts are added up, and every one of them
 * must name the same wallet. Anything the parser can't account for in full is left unread, never half-summed.
 * The confirmation has no reference number; a reused one is still caught by its file hash.
 */
class AlexBankReceiptParser implements ReceiptParser, RecognisesReceipts
{
    use VotesAcrossPasses {
        parse as private votedParse;
    }

    // Printed labels, any one of them: the logo is an image and a bank may rename one label.
    private const SIGNALS = '/ALEXBANK|PAY\s+SOMEONE|BENEFICIARY\s+NAME|MOBILE\s+WALLET\s+NUMBER/i';

    // Labels that start a line once per confirmation, in any case: the stack's size is the most any of them (or the
    // amounts, or the wallets) counts, so one misread label can't hide a confirmation and leave a partial sum. At a line
    // start only: the sentence "Your order of Pay someone is confirmed" repeats one of them.
    private const PER_TRANSFER = ['/^PAY\s+SOMEONE\b/im', '/^OBJECT\b/im', '/^TRANSACTION\s+DETAILS\b/im', '/^BENEFICIARY\s+NAME\b/im'];

    // The AMOUNT | FEE label row, with RapidOCR's misreads ("AVOUNT") of the first: a line holding nothing but those
    // labels, both on one line (Tesseract) or one per line (RapidOCR). Upper case and alone: "Marketing Fees", the
    // footer's "fee" or a description typed as "REFERRAL FEE" is text, not the row.
    private const LABEL_ROW = '/^(?:A[A-Z]OUNT|FEE)(?:\s+(?:A[A-Z]OUNT|FEE))*$/';

    private const LABEL = '/A[A-Z]OUNT|FEE/';

    // A line of values right under the labels: numbers and currency codes only ("13,876.00 EGP 100.00 EGP").
    private const VALUE_LINE = '/^(?:\S*\d\S*|[A-Z]{2,4})(?:\s+(?:\S*\d\S*|[A-Z]{2,4}))*$/';

    // A wallet number. Tesseract often drops a zero of 0020 ("020…"), so that counts too.
    private const WALLET = '/(?<![\d+])(?:\+20|0020|020|0)1\d{9}(?!\d)/';

    // The wallet's own label: its number follows within a few lines (after the name, on RapidOCR), before PAYMENT TYPE.
    // Only there: a phone-shaped number elsewhere (the sender's block) is never the beneficiary's wallet. Upper case
    // only: the payment type below it reads "Mobile wallet".
    private const WALLET_LABEL = '/\bWALLET\s+NUMBER\b/';

    private const WALLET_END = '/PAYMENT\s+TYPE|PURPOSE\s+OF/i';

    // A whole number token, set off by spaces: in "I0,384.00" (a 1 read as I) no digits are taken, so a garbled
    // amount is unread rather than read short ("0,384.00"); a date ("17/02/2025") gives none either.
    private const NUMBER = '/(?<!\S)\d[\d.,]*(?!\S)/';

    // The amount is printed with its piastres ("10,384.00") and never starts with a zero: "0,384.00" is a 10,384.00 that
    // lost its 1, not 384. Anything else is unread, never read as a smaller amount.
    private const MONEY = '/^(?:[1-9]\d{0,2}(?:,\d{3})+|[1-9]\d*)\.\d{2}$/';

    // The fee next to it only has to look like money, misread separator and all ("100,00"): with its amount line lost,
    // the fee must not pair with some later number ("2025") and pass for the amount.
    private const FEE = '/^\d[\d.,]*[.,]\d{2}$/';

    public function __construct(
        private readonly AmountParser $amounts,
    ) {}

    // The most confirmations any pass of the text being parsed saw; set by parse() for read().
    private int $stack = 0;

    /**
     * A pass that saw fewer confirmations than another read only part of the stack: its sum and wallet count against
     * the others instead of competing with them, so a tie between passes can never pick a partial sum.
     */
    public function parse(string $text): ExtractedFields
    {
        $this->stack = 0;

        foreach (array_filter(explode("\f", $text), fn (string $pass) => trim($pass) !== '') as $pass) {
            $lines = $this->lines($pass);
            $this->stack = max($this->stack, $this->transfers($lines, $this->amounts($lines), $this->wallets($lines)));
        }

        return $this->votedParse($text);
    }

    public function recognises(string $text): bool
    {
        return preg_match(self::SIGNALS, $text) === 1;
    }

    public function format(): ReceiptFormatEnum
    {
        return ReceiptFormatEnum::ALEXBANK;
    }

    protected function currency(): string
    {
        return 'EGP';
    }

    /** @return array<string, mixed> the fields one pass could read */
    protected function read(string $pass): array
    {
        $lines = $this->lines($pass);

        $amounts = $this->amounts($lines);
        $wallets = $this->wallets($lines);
        $transfers = max($this->stack, $this->transfers($lines, $amounts, $wallets));

        return [
            // A missed or unreadable amount would make the sum wrong, not short: no amount then.
            'amount_minor' => $transfers === 0 ? null : ($transfers === count($amounts) && ! in_array(false, $amounts, true) ? array_sum($amounts) : false),
            // Every confirmation must name the wallet, the same one.
            'phone' => $transfers === 0 || $wallets === [] ? null : ($transfers === count($wallets) && ! in_array(false, $wallets, true) && count(array_unique($wallets)) === 1 ? $wallets[0] : false),
            'status_text' => preg_grep('/\bEXECUTED\b/', $lines) ? 'EXECUTED' : null,
        ];
    }

    /** @return list<string> */
    private function lines(string $pass): array
    {
        return array_values(array_filter(
            array_map(fn (string $line) => trim(Digits::fold((string) preg_replace('/\p{Cf}/u', '', $line))), explode("\n", $pass)),
            fn (string $line) => $line !== '',
        ));
    }

    /**
     * How many confirmations one pass saw: the most any per-confirmation label, amount row or wallet label counts.
     *
     * @param  list<string>  $lines
     * @param  list<int|false>  $amounts
     * @param  list<string|false>  $wallets
     */
    private function transfers(array $lines, array $amounts, array $wallets): int
    {
        $text = implode("\n", $lines);

        return max(count($amounts), count($wallets), ...array_map(fn (string $label) => preg_match_all($label, $text), self::PER_TRANSFER));
    }

    /**
     * One entry per AMOUNT | FEE row: the value under AMOUNT, or false unless the row reads whole. The values must sit
     * on the lines right under the labels, one per label, so nothing from further down the page can stand in for a
     * lost one. The amount must be money ("10,384.00") and more than the fee beside it: a fee never passes for it.
     *
     * @param  list<string>  $lines
     * @return list<int|false>
     */
    private function amounts(array $lines): array
    {
        $amounts = [];
        $count = count($lines);

        for ($k = 0; $k < $count; $k++) {
            if (! preg_match(self::LABEL_ROW, $lines[$k])) {
                continue;
            }

            $labels = [];
            for (; $k < $count && preg_match(self::LABEL_ROW, $lines[$k]); $k++) {
                preg_match_all(self::LABEL, $lines[$k], $m);
                array_push($labels, ...$m[0]);
            }

            $numbers = [];
            $garbled = false;
            for (; $k < $count && count($numbers) < count($labels) && preg_match(self::VALUE_LINE, $lines[$k]); $k++) {
                preg_match_all(self::NUMBER, $lines[$k], $m);
                array_push($numbers, ...$m[0]);
                // A token with digits that isn't a whole number ("1O," of "1O, 384.00") spoils the row: what is left
                // of it ("384.00") is not the amount.
                $garbled = $garbled || preg_match_all('/\S*\d\S*/', $lines[$k]) !== count($m[0]);
            }
            $k--;

            $amounts[] = $garbled ? false : $this->amount($labels, $numbers);
        }

        return $amounts;
    }

    /**
     * @param  list<string>  $labels  the row's labels in order
     * @param  list<string>  $numbers  the values under them in order
     */
    private function amount(array $labels, array $numbers): int|false
    {
        $fee = array_search('FEE', $labels, true);
        $amount = array_key_first(array_filter($labels, fn (string $label) => $label !== 'FEE'));

        if (count($labels) !== 2 || count($numbers) !== 2 || $fee === false || $amount === null
            || ! preg_match(self::MONEY, $numbers[$amount]) || ! preg_match(self::FEE, $numbers[$fee])) {
            return false;
        }

        // The fee always has two decimals ("100.00", or misread "100,00"), so its digits are its piastres.
        // ?: a zero is a lost amount, never a payout.
        $minor = $this->amounts->parse($numbers[$amount]) ?: false;

        return $minor !== false && $minor > (int) preg_replace('/\D/', '', $numbers[$fee]) ? $minor : false;
    }

    /**
     * One entry per wallet label: the one wallet number in the lines under it, false when there is none or two.
     *
     * @param  list<string>  $lines
     * @return list<string|false>
     */
    private function wallets(array $lines): array
    {
        $wallets = [];

        foreach ($lines as $k => $line) {
            if (! preg_match(self::WALLET_LABEL, $line)) {
                continue;
            }

            $found = [];
            foreach (array_slice($lines, $k + 1, 3) as $next) {
                if (preg_match(self::WALLET_END, $next) || preg_match(self::WALLET_LABEL, $next)) {
                    break;
                }
                preg_match_all(self::WALLET, $next, $m);
                array_push($found, ...array_map(fn (string $wallet) => Phone::normalise($wallet), $m[0]));
            }

            $wallets[] = count(array_unique($found)) === 1 ? $found[0] : false;
        }

        return $wallets;
    }
}
