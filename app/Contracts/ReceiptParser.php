<?php

namespace Modules\TransactionVerification\Contracts;

use Modules\TransactionVerification\Data\ExtractedFields;

/** Pulls the fields we match on out of OCR text. */
interface ReceiptParser
{
    public function parse(string $text): ExtractedFields;
}
