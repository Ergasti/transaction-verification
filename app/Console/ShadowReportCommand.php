<?php

namespace Modules\TransactionVerification\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\TransactionVerification\Models\TransactionVerification;

/** Shadow mode: one CSV line per payout check, to label by hand against the accuracy gate. Outcomes only, no values. */
class ShadowReportCommand extends Command
{
    protected $signature = 'transaction-verification:shadow-report
        {--since= : Y-m-d, default 14 days ago}';

    protected $description = 'List payout receipt checks as CSV, with an empty label column.';

    public function handle(): int
    {
        $sinceOption = $this->option('since');

        // Strict: a loose parse would quietly turn "last tuesday" or a typo into some other window.
        if ($sinceOption !== null && ! (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $sinceOption, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
            $this->error('--since must be a date as Y-m-d.');

            return self::FAILURE;
        }

        $since = $sinceOption === null ? now()->subDays(14) : Carbon::createFromFormat('!Y-m-d', $sinceOption);

        $this->csv(['uuid', 'subject_id', 'created_at', 'status', 'verdict', 'confidence', 'amount', 'destination', 'duplicate', 'label']);

        // Only what the report prints, in batches.
        $rows = TransactionVerification::where('subject_type', 'affiliate_payout')->where('created_at', '>=', $since->startOfDay())
            ->select(['id', 'uuid', 'subject_id', 'created_at', 'status', 'verdict', 'confidence', 'checks']);

        foreach ($rows->lazyById(500) as $row) {
            $this->csv([
                $row->uuid,
                $row->subject_id,
                $row->created_at?->toDateTimeString(),
                $row->status->value,
                $row->verdict?->value,
                $row->confidence,
                ...array_map(fn (string $check) => $row->checks[$check]['outcome'] ?? '', ['amount', 'destination', 'duplicate']),
                '',
            ]);
        }

        return self::SUCCESS;
    }

    /** @param  list<mixed>  $fields */
    private function csv(array $fields): void
    {
        // A cell a spreadsheet would run as a formula (another service names the subject) is kept as text; full-width
        // signs count too, as some locales read them.
        $fields = array_map(fn ($field) => is_string($field) && preg_match('/^[=+\-@\t\r\n\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $field) ? "'{$field}" : $field, $fields);

        $handle = fopen('php://memory', 'r+');
        fputcsv($handle, $fields, escape: '');
        rewind($handle);
        $this->line(rtrim((string) stream_get_contents($handle), "\n"));
        fclose($handle);
    }
}
