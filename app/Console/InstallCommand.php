<?php

namespace Modules\TransactionVerification\Console;

use Illuminate\Console\Command;

use function Laravel\Prompts\select;

/** Writes the app's config file with only the database connection; everything else keeps the package's defaults. */
class InstallCommand extends Command
{
    protected $signature = 'transaction-verification:install
        {--connection= : The database connection for the receipt tables (asked when left out)}
        {--force : Overwrite an existing config file}';

    protected $description = 'Create config/transaction-verification.php, pinning the database connection.';

    public function handle(): int
    {
        $file = config_path('transaction-verification.php');

        if (is_file($file) && ! $this->option('force')) {
            $this->components->error('config/transaction-verification.php already exists. Run again with --force to overwrite it.');

            return self::FAILURE;
        }

        $connections = array_keys((array) config('database.connections'));
        $default = (string) config('database.default');
        $connection = $this->option('connection') ?? ($this->input->isInteractive()
            ? select(
                label: 'Which database connection should the receipt tables use?',
                options: $connections,
                default: $default,
                hint: "If unsure, keep the default ({$default}): it's the database your app already uses.",
            )
            : $default);

        if (! in_array($connection, $connections, true)) {
            $this->components->error("No database connection [{$connection}] in config/database.php.");

            return self::FAILURE;
        }

        file_put_contents($file, <<<PHP
            <?php

            // Only what this app changes; everything else comes from ergasti/transaction-verification.
            return [
                'connection' => env('TRANSACTION_VERIFICATION_DB_CONNECTION', {$this->quoted($connection)}),
            ];

            PHP);

        $this->components->info("Created config/transaction-verification.php (connection: {$connection}).");
        $this->line('  Next:');
        $this->line('  1. php artisan migrate');
        $this->line('  2. Set TRANSACTION_VERIFICATION_HMAC_KEY in .env (a long random secret; never change it later)');
        $this->line('  3. Start the RapidOCR container (see the README)');

        return self::SUCCESS;
    }

    private function quoted(string $value): string
    {
        return var_export($value, true);
    }
}
