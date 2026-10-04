<?php

namespace Modules\TransactionVerification\Data;

/** Where the money should have gone: type is phone, instapay_handle or bank (value: the account number). */
final readonly class ExpectedDestination
{
    public const PHONE = 'phone';

    public const INSTAPAY_HANDLE = 'instapay_handle';

    public const BANK = 'bank';

    public function __construct(
        public string $type,
        public string $value,
    ) {
        // A typo ("bank_account") would otherwise fall through to not_applicable without a word.
        if (! in_array($type, [self::PHONE, self::INSTAPAY_HANDLE, self::BANK], true)) {
            throw new \InvalidArgumentException("Unknown destination type [{$type}].");
        }

        if (trim($value) === '') {
            throw new \InvalidArgumentException('A destination needs a value.');
        }
    }
}
