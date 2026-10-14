<?php

namespace Modules\TransactionVerification\Contracts;

use Modules\TransactionVerification\Enums\ReceiptFormatEnum;

/** A parser the router can pick: it says whether OCR text looks like its layout. */
interface RecognisesReceipts
{
    /** Any one signal is enough, so one renamed label doesn't lose the receipt. Called on the whole text, every pass. */
    public function recognises(string $text): bool;

    /** The format a reading by this parser is tagged with. */
    public function format(): ReceiptFormatEnum;
}
