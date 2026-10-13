<?php

namespace Modules\TransactionVerification\Services\Parsers;

use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\RecognisesReceipts;
use Modules\TransactionVerification\Enums\ReceiptFormatEnum;
use Modules\TransactionVerification\Services\AmountParser;
use Modules\TransactionVerification\Services\DateParser;
use Modules\TransactionVerification\Services\Digits;

/**
 * Reads an InstaPay receipt (English or Arabic app UI) from the engine's passes and votes per field.
 * Verdict fields (amount, recipient) need their label; the reference and date are found by shape,
 * because Arabic labels come and go between passes while the values don't.
 * The sender writes the note, so nothing is ever taken from a note line or across two candidates.
 * Confidence is agreement between the passes, not OCR accuracy: the passes read one image, so an error
 * they all share still scores 1.0. The rules below refuse the shapes such errors take.
 */
class InstaPayReceiptParser implements ReceiptParser, RecognisesReceipts
{
    use VotesAcrossPasses;

    public const FORMAT = ReceiptFormatEnum::INSTAPAY;

    // InstaPay is EGP-only, and the orange currency never OCRs reliably.
    protected const CURRENCY = 'EGP';

    private const PHONE = '/(?<![\d+])(?:\+20|0020|0)1\d{9}(?!\d)/';

    // A whole address only: no piece of "alice@instapay.com" or "alice+shop@instapay".
    private const HANDLE = '/(?<![\w.+\-@])[\w.\-]+@instapay(?![\w.\-@+])/i';

    // '+' excluded: "+201099999999" is a phone, not a reference.
    private const REFERENCE = '/(?<![\d+])\d{12}(?!\d)/';

    // The label with the destination kind ("To Instapay", "To Mobile Wallet", "إلى انستاباي", "إلى المحفظة ...")
    // or alone (bank, card), after at most a few icon characters. Alone it takes nothing after it, so a note
    // that merely starts with "to" or "الى" doesn't pass for it.
    private const TO = '/^[^\p{L}]{0,4}(To\s*(Instapay|Mobile\s*Wallet)|(إلى|الى)\s*(\S*ستاب|المحفظة)|(To|إلى|الى)[^\p{L}\p{N}]*$)/iu';

    // A bank account (19 digits on the receipts seen) or card number (16): no phone or reference is that long.
    private const ACCOUNT = '/(?<!\d)\d{13,30}(?!\d)/';

    // Where the recipient block ends: a label of the details card or of a (reordered) sender block, in any
    // case and with or without a colon, or the footer.
    private const SECTION_END = '/^(Reference|Date|Note|More\s+Details)\b|المرجع|التاريخ|ملاحظة|POWERED\s+BY|(?<![\p{L}\p{N}])(From|من)(?![\p{L}\p{N}])/iu';

    private const NOTE = '/^Note\b|ملاحظة/iu';

    public function __construct(
        private readonly AmountParser $amounts,
        private readonly DateParser $dates,
    ) {}

    /** Its amount label, or an InstaPay address: either alone is enough. */
    public function recognises(string $text): bool
    {
        return preg_match('/Transfer\s+Amount|المحول/iu', $text) === 1 || preg_match(self::HANDLE, $text) === 1;
    }

    /** @return array<string, mixed> the fields one pass could read */
    protected function read(string $pass): array
    {
        $lines = array_values(array_filter(
            array_map(fn (string $line) => trim(Digits::fold((string) preg_replace('/\p{Cf}/u', '', $line))), explode("\n", $pass)),
            fn (string $line) => $line !== '',
        ));

        $read = [];
        $amounts = [];
        $notes = $this->noteLines($lines);

        foreach ($lines as $k => $line) {
            if (! isset($read['status_text']) && preg_match('/Transaction\s+(Successful|Details)|بنجاح/iu', $line, $m)) {
                $read['status_text'] = strcasecmp($m[1] ?? '', 'Details') === 0 ? 'Transaction Details' : 'Transaction Successful';
            }

            if ($k > 0 && ! isset($notes[$k]) && preg_match('/Transfer\s+Amount|المحول/iu', $line)) {
                $amounts[] = $this->amount($lines[$k - 1], isset($notes[$k - 1]));
            }

            if (! isset($read['occurred_at']) && ! isset($notes[$k])) {
                $read['occurred_at'] = $this->dates->parse($line);
            }

            if (! isset($read['note'])) {
                $read['note'] = $this->note($line);
            }
        }

        // A receipt has one amount label: two labelled amounts that disagree make the pass ambiguous.
        $amounts = array_filter($amounts, fn ($amount) => $amount !== null);
        if ($amounts !== []) {
            $read['amount_minor'] = in_array(false, $amounts, true) || count(array_unique($amounts)) > 1 ? false : reset($amounts);
        }

        return [...$read, 'reference' => $this->reference($lines, $notes), ...$this->recipient($lines, $notes)];
    }

    /**
     * The whole line goes to AmountParser, so "-20.10", ".50" or two numbers are refused. InstaPay groups
     * thousands ("2,050"), so four digits in a row are a dropped separator ("20.10" read as "2010").
     * A zero is an icon or a lost amount, never a payout.
     */
    private function amount(string $line, bool $isNote): int|false|null
    {
        if (! preg_match('/\d/', $line)) {
            return null;
        }

        // Digits that can't be the amount count against the passes that read one.
        if ($isNote || preg_match('/\d{4}/', $line)) {
            return false;
        }

        return $this->amounts->parse($line) ?: false;
    }

    /** @return array<int, true> note lines: the labelled line, and the next one when OCR put the value there */
    private function noteLines(array $lines): array
    {
        $notes = [];

        foreach ($lines as $k => $line) {
            if (preg_match(self::NOTE, $line)) {
                $notes[$k] = true;

                if ($this->note($line) === null && isset($lines[$k + 1])) {
                    $notes[$k + 1] = true;
                }
            }
        }

        return $notes;
    }

    /** The labelled reference, else the only 12-digit number outside the note; two candidates are never guessed. */
    private function reference(array $lines, array $notes): ?string
    {
        $candidates = [];

        foreach ($lines as $k => $line) {
            if (isset($notes[$k])) {
                continue;
            }

            preg_match_all(self::REFERENCE, $line, $r);

            if (count($r[0]) === 1 && preg_match('/Reference|المرجع/iu', $line)) {
                return $r[0][0];
            }

            array_push($candidates, ...$r[0]);
        }

        $candidates = array_values(array_unique($candidates));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * The recipient's phone, handle and account number, from the block under "To" only. The block ends at the details card
     * (a label, a reference-shaped number or a date: Arabic OCR often loses the labels) or a sender block,
     * so the sender-written note below it never counts. A pass that lost the "To" label doesn't vote.
     */
    private function recipient(array $lines, array $notes): array
    {
        // "To" always comes before the note, so nothing from the note label on passes for it, however the note wraps.
        $firstNote = $notes === [] ? PHP_INT_MAX : min(array_keys($notes));
        $toLines = array_filter(preg_grep(self::TO, $lines), fn (int $k) => $k < $firstNote, ARRAY_FILTER_USE_KEY);

        // Two "To" lines, or one carrying an identifier itself: a note is posing as the label. Counts against.
        if (count($toLines) > 1 || preg_grep(self::PHONE, $toLines) || preg_grep(self::HANDLE, $toLines) || preg_grep(self::ACCOUNT, $toLines)) {
            return ['phone' => false, 'handle' => false, 'account' => false];
        }

        if ($toLines === []) {
            return [];
        }

        $k = array_key_first($toLines);
        $section = [$toLines[$k]];
        foreach (array_slice($lines, $k + 1) as $next) {
            if (preg_match(self::SECTION_END, $next) || preg_match(self::REFERENCE, $next) || $this->dates->parse($next) !== null) {
                break;
            }
            $section[] = $next;
        }

        $handle = $this->only(self::HANDLE, $section);

        return [
            'phone' => $this->only(self::PHONE, $section),
            'handle' => is_string($handle) ? strtolower($handle) : $handle,
            // OCR splits a long number at a space ("12950003 39573700011").
            'account' => $this->only(self::ACCOUNT, preg_replace('/(?<=\d) (?=\d)/', '', $section)),
        ];
    }

    /** The one distinct match of $pattern in $lines; null for none, false for two or more (never guessed). */
    private function only(string $pattern, array $lines): string|false|null
    {
        preg_match_all($pattern, implode("\n", $lines), $m);
        $distinct = array_values(array_unique(array_map('strtolower', $m[0])));

        return match (count($distinct)) {
            0 => null,
            1 => $m[0][0],
            default => false,
        };
    }

    /** Informational. Right-to-left lines can put the Arabic label on either side of the value. */
    private function note(string $line): ?string
    {
        $note = match (true) {
            (bool) preg_match('/^Note\s+(.+)$/iu', $line, $m) => $m[1],
            str_contains($line, 'ملاحظة') => str_replace('ملاحظة', '', $line),
            default => '',
        };

        return trim($note) === '' ? null : trim($note);
    }
}
