<?php

namespace Modules\TransactionVerification\Services\Parsers;

use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\RecognisesReceipts;
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
    use VotesAcrossPasses;

    public const FORMAT = ReceiptFormatEnum::ALEXBANK;

    protected const CURRENCY = 'EGP';

    // Printed labels, any one of them: the logo is an image and a bank may rename one label.
    private const SIGNALS = '/ALEXBANK|PAY\s+SOMEONE|BENEFICIARY\s+NAME|MOBILE\s+WALLET\s+NUMBER/i';

    // One confirmation each. Upper case only: the sentence "Your order of Pay someone is confirmed" repeats it.
    private const TRANSFER = '/\bPAY SOMEONE\b/';

    // The AMOUNT | FEE label row, with RapidOCR's misreads ("AVOUNT") of the first. Upper case only: "Marketing Fees"
    // and the footer's "fee" are text. The values follow in label order, amount first, on one line (Tesseract) or
    // one per line (RapidOCR).
    private const AMOUNT_LABELS = '/\b(FEE|A[A-Z]OUNT)\b/';

    // A wallet number. Tesseract often drops a zero of 0020 ("020…"), so that counts too.
    private const WALLET = '/(?<![\d+])(?:\+20|0020|020|0)1\d{9}(?!\d)/';

    private const NUMBER = '/\d[\d.,]*\d|\d/';

    // The amount is printed with its piastres ("10,384.00"). A garbled one ("1O,384.00" read as "1") must not pass for
    // a smaller amount: anything else is unread. The fee only has to be there.
    private const MONEY = '/^(?:\d{1,3}(?:,\d{3})+|\d+)\.\d{2}$/';

    public function __construct(
        private readonly AmountParser $amounts,
    ) {}

    public function recognises(string $text): bool
    {
        return preg_match(self::SIGNALS, $text) === 1;
    }

    /** @return array<string, mixed> the fields one pass could read */
    protected function read(string $pass): array
    {
        $lines = array_values(array_filter(
            array_map(fn (string $line) => trim(Digits::fold((string) preg_replace('/\p{Cf}/u', '', $line))), explode("\n", $pass)),
            fn (string $line) => $line !== '',
        ));

        $amounts = $this->amounts($lines);
        preg_match_all(self::WALLET, implode("\n", $lines), $m);
        $wallets = array_map(fn (string $wallet) => Phone::normalise($wallet), $m[0]);
        $transfers = max(preg_match_all(self::TRANSFER, implode("\n", $lines)), count($amounts), count($wallets));

        return [
            // A missed or unreadable amount would make the sum wrong, not short: no amount then.
            'amount_minor' => $transfers === 0 ? null : ($transfers === count($amounts) && ! in_array(false, $amounts, true) ? array_sum($amounts) : false),
            // Every confirmation must name the wallet, the same one.
            'phone' => $transfers === 0 || $wallets === [] ? null : ($transfers === count($wallets) && count(array_unique($wallets)) === 1 ? $wallets[0] : false),
            'status_text' => preg_grep('/\bEXECUTED\b/', $lines) ? 'EXECUTED' : null,
        ];
    }

    /**
     * One entry per AMOUNT | FEE row: the first of the next two numbers, false when it isn't an amount or the row
     * doesn't have both (with only one, it could be the fee).
     *
     * @param  list<string>  $lines
     * @return list<int|false>
     */
    private function amounts(array $lines): array
    {
        $amounts = [];
        $numbers = null;

        foreach ($lines as $line) {
            if (preg_match(self::AMOUNT_LABELS, $line)) {
                $numbers ??= [];

                continue;
            }

            if ($numbers === null) {
                continue;
            }

            preg_match_all(self::NUMBER, $line, $m);
            array_push($numbers, ...$m[0]);

            // Both values read, or a label line with no number: the row is over.
            if (count($numbers) >= 2 || $m[0] === []) {
                $amounts[] = count($numbers) >= 2 && preg_match(self::MONEY, $numbers[0]) ? ($this->amounts->parse($numbers[0]) ?? false) : false;
                $numbers = null;
            }
        }

        if ($numbers !== null) {
            $amounts[] = false;
        }

        return $amounts;
    }
}
