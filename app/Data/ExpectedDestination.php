<?php

namespace Modules\TransactionVerification\Data;

use Modules\TransactionVerification\Enums\DestinationTypeEnum;

/** Where the money should have gone: its type and the plain value. */
final readonly class ExpectedDestination
{
    public function __construct(
        public DestinationTypeEnum $type,
        public string $value,
    ) {
        if (trim($value) === '') {
            throw new \InvalidArgumentException('A destination needs a value.');
        }
    }
}
