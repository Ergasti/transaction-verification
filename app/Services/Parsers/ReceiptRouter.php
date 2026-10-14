<?php

namespace Modules\TransactionVerification\Services\Parsers;

use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\RecognisesReceipts;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Enums\ReceiptFormatEnum;

/**
 * The default parser: hands the text to the first configured parser that recognises it, and tags the format.
 * Text none recognises reads nothing (format unrecognised), so it comes back unreadable and a person looks.
 */
class ReceiptRouter implements ReceiptParser
{
    public function parse(string $text): ExtractedFields
    {
        foreach ($this->parsers() as $parser) {
            if ($parser->recognises($text)) {
                return $this->read($parser, $text);
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
