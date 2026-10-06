<?php

namespace Modules\TransactionVerification\Data;

use Modules\TransactionVerification\Enums\CheckOutcomeEnum;

/** One check's outcome. $detail never holds a plain phone or handle. */
final readonly class CheckResult
{
    public function __construct(
        public CheckOutcomeEnum $outcome,
        public array $detail = [],
    ) {}

    public function toArray(): array
    {
        return ['outcome' => $this->outcome->value] + $this->detail;
    }
}
