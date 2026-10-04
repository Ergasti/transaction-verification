<?php

namespace Modules\TransactionVerification\Tests\Feature\Enclosure;

use Modules\TransactionVerification\Tests\TestCase;

/**
 * Pins the migration basenames. The migrations ledger keys on basename, so a rename or deletion
 * re-runs or orphans a migration on deployed environments. Only appending is legitimate.
 *
 * Regenerate (new migration only): REGENERATE_GOLDEN=1 vendor/bin/phpunit --filter MigrationManifestTest
 */
class MigrationManifestTest extends TestCase
{
    private const MIGRATIONS_DIR = __DIR__.'/../../../database/migrations';

    private const FIXTURE = __DIR__.'/fixtures/transactionverification-migrations.golden.txt';

    public function test_migration_basenames_match_golden_manifest(): void
    {
        $basenames = array_map('basename', glob(self::MIGRATIONS_DIR.'/*.php') ?: []);
        sort($basenames);

        $this->assertNotEmpty($basenames, 'No migrations found — MIGRATIONS_DIR is wrong.');

        $actual = implode("\n", $basenames)."\n";

        if (env('REGENERATE_GOLDEN')) {
            $this->assertFalse((bool) env('CI'), 'REGENERATE_GOLDEN must never be set in CI.');
            file_put_contents(self::FIXTURE, $actual);
            $this->markTestSkipped('Migration manifest regenerated — diff must be append-only.');
        }

        $this->assertFileExists(self::FIXTURE, 'Golden manifest missing — run once with REGENERATE_GOLDEN=1.');
        $this->assertSame(file_get_contents(self::FIXTURE), $actual, 'Migration basenames drifted. Only append-only diffs are legitimate.');
    }
}
