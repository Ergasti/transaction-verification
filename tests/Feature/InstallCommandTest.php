<?php

namespace Modules\TransactionVerification\Tests\Feature;

use Modules\TransactionVerification\Tests\TestCase;

/** `transaction-verification:install` writes the app's small config file: the connection, nothing else. */
class InstallCommandTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench's own skeleton app: removed again after each test.
        $this->file = config_path('transaction-verification.php');
        @unlink($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_it_writes_the_connection_picked_from_the_apps_list(): void
    {
        $this->artisan('transaction-verification:install')
            ->expectsChoice('Which database connection should the receipt tables use?', 'mysql', array_keys(config('database.connections')))
            ->expectsOutputToContain('php artisan migrate')
            ->assertSuccessful();

        $this->assertSame(['connection' => 'mysql'], require $this->file);
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_DB_CONNECTION', 'mysql')", file_get_contents($this->file));
    }

    public function test_without_questions_it_keeps_the_apps_default(): void
    {
        $this->artisan('transaction-verification:install', ['--no-interaction' => true])->assertSuccessful();

        $this->assertSame(['connection' => config('database.default')], require $this->file);
    }

    public function test_a_script_names_the_connection(): void
    {
        $this->artisan('transaction-verification:install', ['--connection' => 'pgsql', '--no-interaction' => true])->assertSuccessful();

        $this->assertSame(['connection' => 'pgsql'], require $this->file);
    }

    public function test_an_unknown_connection_is_refused_and_nothing_is_written(): void
    {
        $this->artisan('transaction-verification:install', ['--connection' => 'nope', '--no-interaction' => true])
            ->expectsOutputToContain('No database connection [nope]')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->file);
    }

    public function test_an_existing_file_is_kept_unless_forced(): void
    {
        file_put_contents($this->file, "<?php\n\nreturn ['connection' => 'mine'];\n");

        $this->artisan('transaction-verification:install', ['--no-interaction' => true])
            ->expectsOutputToContain('--force')
            ->assertFailed();
        $this->assertSame(['connection' => 'mine'], require $this->file);

        $this->artisan('transaction-verification:install', ['--connection' => 'sqlite', '--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertSame(['connection' => 'sqlite'], require $this->file);
    }
}
