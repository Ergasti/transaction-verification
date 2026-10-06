<?php

namespace Modules\TransactionVerification\Services;

use Modules\TransactionVerification\Data\CheckResult;
use Modules\TransactionVerification\Data\ExpectedDestination;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Enums\CheckEnum as Check;
use Modules\TransactionVerification\Enums\CheckOutcomeEnum as Outcome;
use Modules\TransactionVerification\Enums\VerdictEnum;

/** Deterministic comparisons and the verdict. No model decides anything here. */
class Matcher
{
    /**
     * @param  list<array{id: int, uuid: string, method: string}>  $duplicates
     * @return array{verdict: VerdictEnum, checks: array<string, CheckResult>, confidence: ?float}
     */
    public function decide(int $expectedAmountMinor, ExpectedDestination $expected, ExtractedFields $found, array $duplicates, float $threshold): array
    {
        $checks = [
            Check::AMOUNT->value => $this->amount($expectedAmountMinor, $found->amountMinor),
            Check::DESTINATION->value => $this->destination($expected, $found),
            Check::DUPLICATE->value => $duplicates === []
                ? new CheckResult(Outcome::PASS)
                : new CheckResult(Outcome::FAIL, [
                    'of' => array_values(array_unique(array_column($duplicates, 'uuid'))),
                    'by' => array_values(array_unique(array_column($duplicates, 'method'))),
                ]),
            // Informational in v1: a raw screenshot often has no reference. Never part of the verdict.
            Check::REFERENCE->value => new CheckResult($this->normaliseReference($found->reference) === null ? Outcome::MISSING : Outcome::PASS),
        ];

        $confidence = $this->decidingConfidence($expected, $found);

        return [
            'verdict' => $this->verdict($checks, $found->confidence, $this->destinationKey($expected, $found), $confidence, $threshold),
            'checks' => $checks,
            'confidence' => $confidence,
        ];
    }

    /** The comparable form of a destination, also used for its blind index. */
    public function normaliseDestination(ExpectedDestination $destination): string
    {
        return match ($destination->type) {
            ExpectedDestination::PHONE => Phone::normalise($destination->value),
            ExpectedDestination::INSTAPAY_HANDLE => mb_strtolower(trim($destination->value)),
            default => trim($destination->value),
        };
    }

    /** Transfer reference without whitespace (incl. NBSP), upper-cased; null when nothing is left. */
    public function normaliseReference(?string $reference): ?string
    {
        $reference = strtoupper((string) preg_replace('/[\s\x{00A0}]+/u', '', (string) $reference));

        return $reference === '' ? null : $reference;
    }

    /**
     * @param  array<string, CheckResult>  $checks
     * @param  array<string, float>  $scores  per-field confidence; a field without one counts as certain
     */
    private function verdict(array $checks, array $scores, string $destinationKey, ?float $confidence, float $threshold): VerdictEnum
    {
        $amount = $checks[Check::AMOUNT->value]->outcome;
        $destination = $checks[Check::DESTINATION->value]->outcome;
        $sure = fn (string $field) => ($scores[$field] ?? 1.0) >= $threshold;

        return match (true) {
            $checks[Check::DUPLICATE->value]->outcome === Outcome::FAIL => VerdictEnum::DUPLICATE,
            $amount === Outcome::MISSING || $destination === Outcome::MISSING => VerdictEnum::UNREADABLE,
            // A failed check is a mismatch only when that field was read surely; an unsure one goes to a person.
            ($amount === Outcome::FAIL && $sure('amount')) || ($destination === Outcome::FAIL && $sure($destinationKey)) => VerdictEnum::MISMATCH,
            $amount === Outcome::FAIL || $destination === Outcome::FAIL => VerdictEnum::NEEDS_REVIEW,
            $destination === Outcome::NOT_APPLICABLE,
            $confidence !== null && $confidence < $threshold => VerdictEnum::NEEDS_REVIEW,
            default => VerdictEnum::MATCH,
        };
    }

    private function amount(int $expected, ?int $found): CheckResult
    {
        if ($found === null) {
            return new CheckResult(Outcome::MISSING, ['expected' => $expected]);
        }

        return new CheckResult($found === $expected ? Outcome::PASS : Outcome::FAIL, ['expected' => $expected, 'found' => $found]);
    }

    private function destination(ExpectedDestination $expected, ExtractedFields $found): CheckResult
    {
        return match ($expected->type) {
            ExpectedDestination::PHONE => $this->phone($expected->value, $found->phone),
            ExpectedDestination::INSTAPAY_HANDLE => match (true) {
                filled($found->handle) => new CheckResult(
                    mb_strtolower(trim($found->handle)) === $this->normaliseDestination($expected) ? Outcome::PASS : Outcome::FAIL
                ),
                // The receipt shows a phone, the method holds only a handle: nothing to compare.
                filled($found->phone) => new CheckResult(Outcome::NOT_APPLICABLE),
                default => new CheckResult(Outcome::MISSING),
            },
            default => $this->account($expected->value, $found->account),
        };
    }

    /**
     * Digits only: the saved account number is free text ("1234-5678 90"). Anything but the same digits goes to a
     * person, never to a mismatch: an IBAN typed in its place never equals the receipt's number, yet the money arrived.
     */
    private function account(string $expected, ?string $found): CheckResult
    {
        $digits = fn (string $value) => (string) preg_replace('/\D+/', '', Digits::fold($value));

        return new CheckResult(filled($found) && $digits($found) === $digits($expected) ? Outcome::PASS : Outcome::NOT_APPLICABLE);
    }

    private function phone(string $expected, ?string $found): CheckResult
    {
        if (blank($found)) {
            return new CheckResult(Outcome::MISSING);
        }

        // Both sides: a saved payment method can be malformed too.
        if (! $this->isPhoneShaped($found) || ! $this->isPhoneShaped($expected)) {
            return new CheckResult(Outcome::FAIL);
        }

        $found = Phone::normalise($found);

        return new CheckResult(
            $found === Phone::normalise($expected) && Phone::isEgyptianMobile($found) ? Outcome::PASS : Outcome::FAIL
        );
    }

    /**
     * Phone::normalise() trims extra digits, so "010000000019" or "+2010000000019" would pass.
     * After the +20/0020 prefix a real mobile is exactly 1XXXXXXXXX or 01XXXXXXXXX; anything else is garbled.
     */
    private function isPhoneShaped(string $phone): bool
    {
        $digits = (string) preg_replace('/^(?:00)?0?20/', '', (string) preg_replace('/\D+/', '', Digits::fold($phone)));

        return preg_match('/^0?1\d{9}$/', $digits) === 1;
    }

    /** Fallback mode asks the second engine unless the amount and the destination are read, at or above $threshold. */
    public function sure(ExpectedDestination $expected, ExtractedFields $found, float $threshold): bool
    {
        $destination = ['phone' => $found->phone, 'handle' => $found->handle, 'account' => $found->account][$this->destinationKey($expected, $found)];
        $confidence = $this->decidingConfidence($expected, $found);

        return $found->amountMinor !== null && filled($destination) && ($confidence === null || $confidence >= $threshold);
    }

    /** @return list<string> the confidence keys of the fields that decide the verdict: the amount and the destination */
    public function decidingKeys(ExpectedDestination $expected, ExtractedFields $found): array
    {
        return ['amount', $this->destinationKey($expected, $found)];
    }

    /** Lowest confidence among the fields that decide the verdict; a field without a score counts as certain. */
    private function decidingConfidence(ExpectedDestination $expected, ExtractedFields $found): ?float
    {
        $scores = array_intersect_key($found->confidence, array_flip(['amount', $this->destinationKey($expected, $found)]));

        return $scores === [] ? null : (float) min($scores);
    }

    private function destinationKey(ExpectedDestination $expected, ExtractedFields $found): string
    {
        return match (true) {
            $expected->type === ExpectedDestination::BANK => 'account',
            $expected->type === ExpectedDestination::INSTAPAY_HANDLE && filled($found->handle) => 'handle',
            default => 'phone',
        };
    }
}
