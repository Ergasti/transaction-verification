<?php

namespace Modules\TransactionVerification\Services\Parsers;

use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\RecognisesReceipts;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Enums\ReceiptFormatEnum;

/**
 * The default parser: hands the text to the first configured parser that recognises it, and tags the format.
 * Text none recognises, or none reads anything off, reads nothing (format unrecognised) but its reference, so it comes back unreadable and a
 * person looks.
 */
class ReceiptRouter implements ReceiptParser
{
    public function parse(string $text): ExtractedFields
    {
        foreach ($this->parsers() as $parser) {
            // One that recognises the text but reads nothing off it was picked by a stray word ("ALEXBANK", a sender's
            // bank on an InstaPay screenshot): the text counts as unrecognised.
            if ($parser->recognises($text) && ! ($fields = $this->read($parser, $text))->readNothing()) {
                return $fields;
            }
        }

        // The reference still, as the first parser to find one reads it (InstaPay's goes by shape when its labels are
        // lost): the verdict is 'unreadable' either way, but the person who looks at the receipt gets its number.
        foreach ($this->parsers() as $parser) {
            $read = $parser->parse($text);

            if ($read->reference !== null) {
                return new ExtractedFields(
                    reference: $read->reference,
                    confidence: array_intersect_key($read->confidence, ['reference' => true]),
                    format: ReceiptFormatEnum::UNRECOGNISED,
                );
            }
        }

        return new ExtractedFields(format: ReceiptFormatEnum::UNRECOGNISED);
    }

    /**
     * Reads the text with the parser for a format already known, recognised or not: the second engine's reading of a
     * receipt the first engine recognised may have lost the very labels that tell it.
     */
    public function parseAs(string $text, ReceiptFormatEnum $format): ExtractedFields
    {
        foreach ($this->parsers() as $parser) {
            if ($parser->format() === $format) {
                return $this->read($parser, $text);
            }
        }

        return new ExtractedFields(format: ReceiptFormatEnum::UNRECOGNISED);
    }

    /** @return iterable<ReceiptParser&RecognisesReceipts> */
    private function parsers(): iterable
    {
        foreach (config('transaction-verification.parsers') as $class) {
            $parser = app($class);

            // A configuration error, not a receipt: said loudly, so a parser that can never be asked is noticed.
            if (! $parser instanceof RecognisesReceipts) {
                throw new \InvalidArgumentException("{$class} in transaction-verification.parsers must implement RecognisesReceipts.");
            }

            yield $parser;
        }
    }

    private function read(ReceiptParser&RecognisesReceipts $parser, string $text): ExtractedFields
    {
        return ExtractedFields::fromArray(['format' => $parser->format()->value] + $parser->parse($text)->toArray());
    }
}
