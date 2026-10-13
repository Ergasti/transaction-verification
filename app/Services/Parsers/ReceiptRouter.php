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
        foreach (config('transaction-verification.parsers') as $class) {
            $parser = app($class);

            // A configuration error, not a receipt: said loudly, so a parser that can never be asked is noticed.
            if (! $parser instanceof RecognisesReceipts) {
                throw new \InvalidArgumentException("{$class} in transaction-verification.parsers must implement RecognisesReceipts.");
            }

            if ($parser->recognises($text)) {
                return ExtractedFields::fromArray(['format' => $parser->format()->value] + $parser->parse($text)->toArray());
            }
        }

        return new ExtractedFields(format: ReceiptFormatEnum::UNRECOGNISED);
    }
}
