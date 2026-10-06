<?php

namespace Modules\TransactionVerification\Enums;

/** The checks a verification runs; their values key the stored checks. */
enum CheckEnum: string
{
    case AMOUNT = 'amount';
    case DESTINATION = 'destination';
    case DUPLICATE = 'duplicate';
    case REFERENCE = 'reference';
}
