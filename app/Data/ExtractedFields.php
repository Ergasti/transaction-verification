<?php

namespace Modules\TransactionVerification\Data;

/** What a parser read off a receipt. $confidence is 0–1 per field name (amount, phone, handle, account, reference). */
final readonly class ExtractedFields
{
    public function __construct(
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $phone = null,
        public ?string $handle = null,
        // Bank account or card number the money went to.
        public ?string $account = null,
        public ?string $reference = null,
        public ?string $occurredAt = null,
        public ?string $statusText = null,
        public ?string $note = null,
        public array $confidence = [],
    ) {}

    // Confidence key => toArray() key.
    private const SCORED = ['amount' => 'amount_minor', 'phone' => 'phone', 'handle' => 'handle', 'account' => 'account', 'reference' => 'reference'];

    /**
     * Second engine on 'always': a scored field it read differently, or not at all, drops to 0, so a match on it
     * goes to a person. The values stay this reading's.
     */
    public function confirmedBy(self $second): self
    {
        [$ours, $theirs] = [$this->toArray(), $second->toArray()];

        foreach (self::SCORED as $key => $field) {
            if ($ours[$field] !== null && $ours[$field] !== $theirs[$field]) {
                $ours['confidence'][$key] = 0.0;
            }
        }

        return self::fromArray($ours);
    }

    /**
     * Second engine on 'fallback': a scored field this reading missed is taken from it, and one read under
     * $threshold is confirmed when it reads the same. It then counts as certain. Anything else is left alone.
     */
    public function filledFrom(self $second, float $threshold): self
    {
        [$ours, $theirs] = [$this->toArray(), $second->toArray()];

        foreach (self::SCORED as $key => $field) {
            // A field without a score counts as certain, as in the Matcher.
            $sure = $ours[$field] !== null && ($ours['confidence'][$key] ?? 1.0) >= $threshold;

            if (! $sure && $theirs[$field] !== null && ($ours[$field] === null || $ours[$field] === $theirs[$field])) {
                $ours[$field] = $theirs[$field];
                $ours['confidence'][$key] = 1.0;
            }
        }

        $ours['currency'] ??= $ours['amount_minor'] === null ? null : $theirs['currency'];

        return self::fromArray($ours);
    }

    /** The inverse of toArray(), for re-deciding from a stored reading. */
    public static function fromArray(array $data): self
    {
        return new self(
            amountMinor: $data['amount_minor'] ?? null,
            currency: $data['currency'] ?? null,
            phone: $data['phone'] ?? null,
            handle: $data['handle'] ?? null,
            account: $data['account'] ?? null,
            reference: $data['reference'] ?? null,
            occurredAt: $data['occurred_at'] ?? null,
            statusText: $data['status_text'] ?? null,
            note: $data['note'] ?? null,
            confidence: $data['confidence'] ?? [],
        );
    }

    /** For callers: the phone, handle and account are masked; the free text (note, status) is left out. */
    public function masked(): array
    {
        // A part is shown only when at least as much stays hidden: a short value is masked whole.
        $shown = fn (string $value, int $keep) => mb_strlen($value) >= 2 * $keep;
        $tail = fn (string $value, int $keep) => $shown($value, $keep) ? mb_substr($value, -$keep) : '';
        [$name, $domain] = array_pad(explode('@', (string) $this->handle, 2), 2, null);

        return [
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'phone' => $this->phone === null ? null : str_repeat('*', mb_strlen($this->phone) - mb_strlen($tail($this->phone, 3))).$tail($this->phone, 3),
            'handle' => $this->handle === null ? null : ($shown($name, 2) ? mb_substr($name, 0, 2) : '').'***'.($domain === null ? '' : '@'.$domain),
            'account' => $this->account === null ? null : '****'.$tail($this->account, 4),
            'reference' => $this->reference,
            'occurred_at' => $this->occurredAt,
        ];
    }

    public function toArray(): array
    {
        return [
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'phone' => $this->phone,
            'handle' => $this->handle,
            'account' => $this->account,
            'reference' => $this->reference,
            'occurred_at' => $this->occurredAt,
            'status_text' => $this->statusText,
            'note' => $this->note,
            'confidence' => $this->confidence,
        ];
    }
}
