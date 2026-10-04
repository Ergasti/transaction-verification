<?php

namespace Modules\TransactionVerification\Console;

use Illuminate\Console\Command;
use Modules\TransactionVerification\Contracts\TransactionVerifier;

/** Re-reads receipts with the current engines, e.g. after an engine upgrade. */
class ReprocessVerificationsCommand extends Command
{
    protected $signature = 'transaction-verification:reprocess {uuid* : Verification uuids}';

    protected $description = 'Read the given receipts again and decide again.';

    public function handle(TransactionVerifier $verifier): int
    {
        $missing = false;

        foreach ($this->argument('uuid') as $uuid) {
            $result = $verifier->reprocess($uuid);

            if ($result === null) {
                $missing = true;
                $this->error("{$uuid}: not found");

                continue;
            }

            $this->line("{$uuid}: {$result->status->value}".($result->verdict ? ", {$result->verdict->value}" : ''));
        }

        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
