<?php

namespace Modules\TransactionVerification\Enums;

/** Which layout a receipt was read as. UNRECOGNISED: no parser knew it, so nothing was read (a person looks). */
enum ReceiptFormatEnum: string
{
    case INSTAPAY = 'instapay';
    case ALEXBANK = 'alexbank';
    case UNRECOGNISED = 'unrecognised';

    /** Whether the layout prints a transfer reference. AlexBank's "Pay someone" confirmation has none. */
    public function printsReference(): bool
    {
        return $this !== self::ALEXBANK;
    }
}
