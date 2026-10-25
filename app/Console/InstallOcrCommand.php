<?php

namespace Modules\TransactionVerification\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Modules\TransactionVerification\Services\BinaryError;

/**
 * Installs the second engine: one archive, no Docker and no Python -- the frozen binary carries its own
 * interpreter. Tesseract and Poppler are deliberately not in it (the distro signs and patches those), so
 * this reports on them rather than installing them.
 */
class InstallOcrCommand extends Command
{
    private const PACKAGE = 'ergasti/transaction-verification';

    private const RELEASE = 'https://github.com/Ergasti/transaction-verification/releases/download';

    /** Strict: the tag goes into a URL, and a loose parse would fetch from somewhere else entirely. */
    private const TAG = '/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/';

    /** Written beside the engine so a later run can tell an upgrade from an already-correct install. */
    private const MARKER = 'INSTALLED_TAG';

    /** Only what CI builds. Anything else must fail rather than fetch an archive for the wrong machine. */
    /** Only what binary.yml builds. macOS is absent on purpose -- see the comment in that workflow. */
    private const PLATFORMS = ['Linux x86_64' => 'linux-x86_64'];

    protected $signature = 'transaction-verification:install-ocr
        {--force : Replace an archive that is already installed}
        {--release= : The engine release to install, e.g. v0.5.1. Defaults to this package\'s own version}';

    protected $description = 'Install the RapidOCR second engine (no Docker, no Python) and report the host binaries.';

    public function handle(): int
    {
        $target = storage_path('app/transaction-verification/ocr');
        $binary = $target.DIRECTORY_SEPARATOR.'tv-ocr';

        if (($tag = $this->releaseTag()) === null) {
            return self::FAILURE;
        }

        if (is_file($binary) && ! $this->option('force')) {
            $installed = is_file($marker = $target.DIRECTORY_SEPARATOR.self::MARKER)
                ? trim((string) file_get_contents($marker))
                : null;

            if ($installed === $tag) {
                $this->line("Already installed: {$binary} ({$tag})");
                $this->line('Run again with --force to replace it.');
            } else {
                $this->warn(sprintf('Installed engine is %s but this package wants %s.',
                    $installed ?? 'of an unknown version', $tag));
                $this->warn('Run again with --force to replace it.');
            }

            return $this->reportHostBinaries();
        }

        if (! $platform = self::PLATFORMS[PHP_OS_FAMILY.' '.php_uname('m')] ?? null) {
            $this->error(sprintf('There is no build for %s %s. Built platforms: %s.',
                PHP_OS_FAMILY, php_uname('m'), implode(', ', self::PLATFORMS)));

            return self::FAILURE;
        }

        $name = 'tv-ocr-'.$tag.'-'.$platform;
        // Beside the target, so moving it into place is a rename on one filesystem and never a half-copy.
        $staging = $target.'.installing-'.bin2hex(random_bytes(4));

        if (! File::ensureDirectoryExists($staging, 0700) && ! is_dir($staging)) {
            $this->error("Could not create {$staging}.");

            return self::FAILURE;
        }

        try {
            return $this->install($name, $tag, $staging, $target, $binary);
        } finally {
            File::deleteDirectory($staging);
        }
    }

    /**
     * The release tag to install: --version if given, else this package's own installed version. A dev
     * checkout has no matching release, so it must be explicit rather than guessed.
     */
    private function releaseTag(): ?string
    {
        // Not --version: Symfony owns that globally and would just print the application version.
        if (is_string($given = $this->option('release')) && $given !== '') {
            if (! preg_match(self::TAG, $given)) {
                $this->error('--release must look like a release tag, e.g. v0.5.1.');

                return null;
            }

            return 'v'.ltrim($given, 'vV');
        }

        $installed = rescue(fn () => InstalledVersions::getPrettyVersion(self::PACKAGE), null, report: false);

        if (! is_string($installed) || ! preg_match(self::TAG, $installed)) {
            $this->error(sprintf('Cannot tell which engine release to install: this package reports "%s". Pass --release=vX.Y.Z.',
                $installed ?? 'nothing'));

            return null;
        }

        return 'v'.ltrim($installed, 'vV');
    }

    private function install(string $name, string $tag, string $staging, string $target, string $binary): int
    {
        $archive = $staging.DIRECTORY_SEPARATOR.$name.'.tar.gz';
        $url = self::RELEASE.'/'.$tag.'/'.$name.'.tar.gz';

        $this->line("Downloading {$name}.tar.gz ...");

        // Long: it is ~220 MB, and a slow server should not fail halfway through a release.
        $download = Http::timeout(1800)->sink($archive)->get($url);

        if (! $download->successful() || ! is_file($archive)) {
            $this->error("Download failed ({$download->status()}): {$url}");

            return self::FAILURE;
        }

        // Verified before unpacking: a corrupt download must never leave anything behind that could be run.
        if (($expected = $this->expectedHash($url.'.sha256')) === null) {
            return self::FAILURE;
        }

        if (! hash_equals($expected, $actual = (string) hash_file('sha256', $archive))) {
            $this->error("Checksum mismatch. Expected {$expected}, got {$actual}. Nothing was installed.");

            return self::FAILURE;
        }

        $this->line('Checksum verified. Unpacking ...');

        $untar = Process::timeout(900)->run(['tar', '-xzf', $archive, '-C', $staging]);

        if (! $untar->successful() || ! is_dir($unpacked = $staging.DIRECTORY_SEPARATOR.$name)) {
            $this->error(BinaryError::explain('tar', $untar->exitCode(), 'Install it with your package manager.')
                ?? 'Unpacking failed: '.(Str::limit($untar->errorOutput(), 200) ?: 'the archive held no '.$name.' directory.'));

            return self::FAILURE;
        }

        chmod($unpacked.DIRECTORY_SEPARATOR.'tv-ocr', 0755);

        // The new tree is in place before the old one goes, so a failure here leaves the old engine working.
        $retired = is_dir($target) ? $target.'.replaced-'.bin2hex(random_bytes(4)) : null;

        if ($retired !== null && ! rename($target, $retired)) {
            $this->error("Could not move the installed engine aside from {$target}.");

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($target));

        if (! rename($unpacked, $target)) {
            $retired === null || rename($retired, $target);
            $this->error("Could not move the new engine into {$target}.");

            return self::FAILURE;
        }

        $retired === null || File::deleteDirectory($retired);
        file_put_contents($target.DIRECTORY_SEPARATOR.self::MARKER, $tag."\n");

        return $this->verify($binary);
    }

    /** The hash from the Release beside the archive. HTTPS to the host is the trust anchor; this catches corruption, not a tampered Release. */
    private function expectedHash(string $url): ?string
    {
        $response = Http::timeout(60)->get($url);

        if (! $response->successful() || ! preg_match('/\b([0-9a-f]{64})\b/i', $response->body(), $m)) {
            $this->error("Could not read a SHA256 from {$url}. Nothing was installed.");

            return null;
        }

        return strtolower($m[1]);
    }

    /** Running it is the only proof it will run: a noexec mount or a bad build both pass every other check. */
    private function verify(string $binary): int
    {
        $this->line('Checking the engine starts ...');

        $warm = Process::timeout(600)->run([$binary, '--warm']);

        if (! $warm->successful()) {
            $this->error(BinaryError::explain($binary, $warm->exitCode(), 'The archive unpacked but the binary is missing; try --force.')
                ?? 'The engine would not start: '.(Str::limit($warm->errorOutput(), 300) ?: 'exit code '.$warm->exitCode()));

            return self::FAILURE;
        }

        $this->info("Installed: {$binary}");

        return $this->reportHostBinaries();
    }

    /** Tesseract and Poppler come from the package manager, so say what is missing instead of installing it. */
    private function reportHostBinaries(): int
    {
        $tesseract = config('transaction-verification.tesseract.binary');
        $pdftoppm = config('transaction-verification.tesseract.pdftoppm_binary');

        $ok = $this->probe($tesseract, ['--version'], 'tesseract', true,
            'Required for every receipt: apt install tesseract-ocr tesseract-ocr-ara, or brew install tesseract tesseract-lang.');

        // Not fatal: only PDF receipts need it, and most receipts are images.
        $this->probe($pdftoppm, ['-v'], 'pdftoppm', false,
            'Only PDF receipts need it: apt install poppler-utils, or brew install poppler.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @param  list<string>  $arguments */
    private function probe(string $binary, array $arguments, string $label, bool $required, string $install): bool
    {
        if (Process::timeout(60)->run([$binary, ...$arguments])->successful()) {
            $this->line("  {$label}: found");

            return true;
        }

        $required
            ? $this->error("  {$label}: not found. {$install}")
            : $this->warn("  {$label}: not found. {$install}");

        return false;
    }
}
