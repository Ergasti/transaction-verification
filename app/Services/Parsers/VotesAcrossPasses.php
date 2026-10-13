<?php

namespace Modules\TransactionVerification\Services\Parsers;

use Modules\TransactionVerification\Data\ExtractedFields;

/**
 * The shared half of a parser: read() pulls the fields out of one OCR pass, and parse() votes per field across the
 * passes (joined by "\f"). Confidence is the share of the passes that read a field and agree.
 */
trait VotesAcrossPasses
{
    // ExtractedFields key => confidence key the Matcher reads.
    private const SCORED = ['amount_minor' => 'amount', 'phone' => 'phone', 'handle' => 'handle', 'account' => 'account', 'reference' => 'reference'];

    /** @return array<string, mixed> the fields one pass could read; false = two candidates, counts against the winner */
    abstract protected function read(string $pass): array;

    public function parse(string $text): ExtractedFields
    {
        $passes = array_filter(explode("\f", $text), fn (string $pass) => trim($pass) !== '');
        $reads = array_map(fn (string $pass) => $this->read($pass), array_values($passes));

        $fields = [];
        $confidence = [];

        foreach (['amount_minor', 'phone', 'handle', 'account', 'reference', 'occurred_at', 'status_text', 'note'] as $key) {
            // false = the pass saw two candidates: it counts against the winner but can't win.
            $values = array_values(array_filter(array_column($reads, $key), fn ($value) => $value !== null));
            [$fields[$key], $share] = $this->vote($values);

            if ($fields[$key] !== null && isset(self::SCORED[$key])) {
                $confidence[self::SCORED[$key]] = $share;
            }
        }

        return new ExtractedFields(
            amountMinor: $fields['amount_minor'],
            currency: $fields['amount_minor'] === null ? null : static::CURRENCY,
            phone: $fields['phone'],
            handle: $fields['handle'],
            account: $fields['account'],
            reference: $fields['reference'],
            occurredAt: $fields['occurred_at'],
            statusText: $fields['status_text'],
            note: $fields['note'],
            confidence: $confidence,
        );
    }

    /** @return array{mixed, float} the most common value (ties: the earliest pass) and its share of the passes that read one */
    private function vote(array $values): array
    {
        $tally = [];
        foreach (array_filter($values, fn ($value) => $value !== false) as $value) {
            $tally[(string) $value] ??= [$value, 0];
            $tally[(string) $value][1]++;
        }

        $best = null;
        foreach ($tally as $entry) {
            if ($best === null || $entry[1] > $best[1]) {
                $best = $entry;
            }
        }

        return $best === null ? [null, 0.0] : [$best[0], round($best[1] / count($values), 3)];
    }
}
