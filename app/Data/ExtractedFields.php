<?php

namespace Modules\TransactionVerification\Data;

use Modules\TransactionVerification\Enums\ReceiptFormatEnum;

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
        public ?ReceiptFormatEnum $format = null,
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

    /** No scored field was read: whatever layout it was tagged with, nothing came of it. */
    public function readNothing(): bool
    {
        $read = $this->toArray();

        return array_filter(self::SCORED, fn (string $field) => $read[$field] !== null) === [];
    }

    /**
     * Second engine on 'fallback': a scored field this reading missed is taken from it, and one read under
     * $threshold is confirmed when it reads the same. It then counts as certain. Anything else is left alone.
     */
    public function filledFrom(self $second, float $threshold): self
    {
        [$ours, $theirs] = [$this->toArray(), $second->toArray()];
        $empty = $this->readNothing();

        foreach (self::SCORED as $key => $field) {
            // A field without a score counts as certain, as in the Matcher.
            $sure = $ours[$field] !== null && ($ours['confidence'][$key] ?? 1.0) >= $threshold;

            if (! $sure && $theirs[$field] !== null && ($ours[$field] === null || $ours[$field] === $theirs[$field])) {
                $ours[$field] = $theirs[$field];
                $ours['confidence'][$key] = 1.0;
            }
        }

        $ours['currency'] ??= $ours['amount_minor'] === null ? null : $theirs['currency'];
        // Read as the second engine read it when ours knew no layout, or read nothing off the one it took: the format
        // says which parser the fields came from. Never its "unrecognised": that would relabel what ours did read.
        if (($empty || in_array($ours['format'], [null, ReceiptFormatEnum::UNRECOGNISED->value], true))
            && ! in_array($theirs['format'], [null, ReceiptFormatEnum::UNRECOGNISED->value], true)) {
            $ours['format'] = $theirs['format'];
        }

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
            // Readings stored before formats were recorded have none.
            format: ReceiptFormatEnum::tryFrom((string) ($data['format'] ?? '')),
        );
    }

    /** For callers: the values as they were read; the free text (note, status) and the per-field scores are left out. */
    public function revealed(): array
    {
        return [
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'phone' => $this->phone,
            'handle' => $this->handle,
            'account' => $this->account,
            'reference' => $this->reference,
            'occurred_at' => $this->occurredAt,
            'format' => $this->format?->value,
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
            'format' => $this->format?->value,
        ];
    }
}
