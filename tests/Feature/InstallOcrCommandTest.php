<?php

namespace Modules\TransactionVerification\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Modules\TransactionVerification\Tests\TestCase;

/**
 * The download is faked: a real one needs a tagged Release that binary.yml has built. What is covered here is
 * everything around it -- platform mapping, the checksum gate, atomicity, and the host-binary report.
 */
class InstallOcrCommandTest extends TestCase
{
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = storage_path('app/transaction-verification/ocr');
        File::deleteDirectory($this->target);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->target);

        foreach (glob(dirname($this->target).'/ocr.*') ?: [] as $leftover) {
            File::deleteDirectory($leftover);
        }

        parent::tearDown();
    }

    /** An archive shaped like the real one: one directory holding a tv-ocr that answers --warm. */
    private function fakeRelease(string $name, ?string $hash = null, string $script = "#!/bin/sh\nexit 0\n"): void
    {
        $build = (string) tempnam(sys_get_temp_dir(), 'tv_rel_');
        unlink($build);
        File::ensureDirectoryExists($build.'/'.$name);
        file_put_contents($build.'/'.$name.'/tv-ocr', $script);
        chmod($build.'/'.$name.'/tv-ocr', 0755);
        exec(sprintf('tar -czf %s -C %s %s', escapeshellarg($build.'/a.tar.gz'), escapeshellarg($build), escapeshellarg($name)));
        $bytes = (string) file_get_contents($build.'/a.tar.gz');
        File::deleteDirectory($build);

        Http::fake([
            '*/'.$name.'.tar.gz' => Http::response($bytes),
            '*/'.$name.'.tar.gz.sha256' => Http::response(($hash ?? hash('sha256', $bytes))."  {$name}.tar.gz\n"),
        ]);
    }

    /** Skips where CI builds nothing, so the suite stays honest on an unsupported machine. */
    private function archiveName(): string
    {
        if (PHP_OS_FAMILY.' '.php_uname('m') !== 'Linux x86_64') {
            $this->markTestSkipped('No build for '.PHP_OS_FAMILY.' '.php_uname('m').'.');
        }

        return 'tv-ocr-v0.1.0-linux-x86_64';
    }

    public function test_it_installs_verifies_and_reports_the_host_binaries(): void
    {
        $this->fakeRelease($this->archiveName());
        // Only the host probes are faked; tar and the unpacked tv-ocr --warm run for real.
        Process::fake(["'tesseract'*" => Process::result(), "'pdftoppm'*" => Process::result()]);

        $this->artisan('transaction-verification:install-ocr', ['--release' => 'v0.1.0'])
            ->expectsOutputToContain('Checksum verified')
            ->expectsOutputToContain('tesseract: found')
            ->expectsOutputToContain('pdftoppm: found')
            ->assertSuccessful();

        $this->assertFileExists($this->target.'/tv-ocr');
        // Nothing half-installed left beside it.
        $this->assertSame([], glob(dirname($this->target).'/ocr.installing-*') ?: []);
    }

    public function test_a_bad_checksum_installs_nothing(): void
    {
        $this->fakeRelease($this->archiveName(), hash: str_repeat('a', 64));

        $this->artisan('transaction-verification:install-ocr', ['--release' => 'v0.1.0'])
            ->expectsOutputToContain('Checksum mismatch')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->target.'/tv-ocr');
        $this->assertSame([], glob(dirname($this->target).'/ocr.installing-*') ?: []);
    }

    public function test_a_release_without_a_readable_hash_installs_nothing(): void
    {
        $name = $this->archiveName();
        Http::fake([
            '*/'.$name.'.tar.gz' => Http::response('not really a tarball'),
            '*/'.$name.'.tar.gz.sha256' => Http::response('404: Not Found', 404),
        ]);

        $this->artisan('transaction-verification:install-ocr', ['--release' => 'v0.1.0'])
            ->expectsOutputToContain('Could not read a SHA256')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->target.'/tv-ocr');
    }

    public function test_a_failed_download_installs_nothing(): void
    {
        $this->archiveName();
        Http::fake(['*' => Http::response('no such release', 404)]);

        $this->artisan('transaction-verification:install-ocr', ['--release' => 'v0.1.0'])
            ->expectsOutputToContain('Download failed (404)')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->target.'/tv-ocr');
    }

    public function test_a_binary_that_will_not_start_fails_the_install(): void
    {
        // Exit 126 is what a noexec mount looks like, so the message must name that rather than blame the archive.
        $this->fakeRelease($this->archiveName(), script: "#!/bin/sh\nexit 126\n");

        $this->artisan('transaction-verification:install-ocr', ['--release' => 'v0.1.0'])
            ->expectsOutputToContain('noexec')
            ->assertFailed();
    }

    public function test_it_refuses_to_guess_a_release_on_a_dev_checkout(): void
    {
        // The package reports dev-main here, which matches no Release; guessing would fetch the wrong engine.
        $this->artisan('transaction-verification:install-ocr')
            ->expectsOutputToContain('Cannot tell which engine release to install')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_a_version_that_is_not_a_tag_is_refused(): void
    {
        // It is interpolated into a download URL, so a loose parse would fetch from somewhere else.
        foreach (['../../../etc/passwd', 'latest', 'v1.2.3 && curl evil', ''] as $bad) {
            $this->artisan('transaction-verification:install-ocr', ['--release' => $bad])->assertFailed();
        }

        Http::assertNothingSent();
    }

    public function test_it_notices_an_engine_from_a_different_release(): void
    {
        File::ensureDirectoryExists($this->target);
        file_put_contents($this->target.'/tv-ocr', "#!/bin/sh\nexit 0\n");
        file_put_contents($this->target.'/INSTALLED_TAG', "v0.0.9\n");
        Process::fake(["'tesseract'*" => Process::result(), "'pdftoppm'*" => Process::result()]);

        $this->artisan('transaction-verification:install-ocr', ['--release' => 'v0.1.0'])
            ->expectsOutputToContain('Installed engine is v0.0.9 but this package wants v0.1.0')
            ->assertSuccessful();
    }

    public function test_an_install_records_which_release_it_came_from(): void
    {
        $this->fakeRelease($this->archiveName());
        Process::fake(["'tesseract'*" => Process::result(), "'pdftoppm'*" => Process::result()]);

        $this->artisan('transaction-verification:install-ocr', ['--release' => 'v0.1.0'])->assertSuccessful();

        $this->assertSame('v0.1.0', trim((string) file_get_contents($this->target.'/INSTALLED_TAG')));
    }

    public function test_it_will_not_replace_an_installed_engine_without_force(): void
    {
        File::ensureDirectoryExists($this->target);
        file_put_contents($this->target.'/tv-ocr', "#!/bin/sh\nexit 0\n");
        file_put_contents($this->target.'/INSTALLED_TAG', "v0.1.0\n");
        Process::fake(["'tesseract'*" => Process::result(), "'pdftoppm'*" => Process::result()]);

        $this->artisan('transaction-verification:install-ocr', ['--release' => 'v0.1.0'])
            ->expectsOutputToContain('Already installed')
            ->expectsOutputToContain('--force')
            ->assertSuccessful();

        // No request was made at all: Http::preventStrayRequests() would have failed the test.
        Http::assertNothingSent();
    }

    public function test_a_missing_tesseract_fails_the_install_and_a_missing_pdftoppm_only_warns(): void
    {
        File::ensureDirectoryExists($this->target);
        file_put_contents($this->target.'/tv-ocr', "#!/bin/sh\nexit 0\n");
        file_put_contents($this->target.'/INSTALLED_TAG', "v0.1.0\n");

        // 127: not found. Tesseract reads every receipt, so that is fatal; pdftoppm only matters for PDFs.
        Process::fake(["'tesseract'*" => Process::result(exitCode: 127), "'pdftoppm'*" => Process::result()]);
        $this->artisan('transaction-verification:install-ocr', ['--release' => 'v0.1.0'])
            ->expectsOutputToContain('tesseract: not found')
            ->assertFailed();

        Process::fake(["'tesseract'*" => Process::result(), "'pdftoppm'*" => Process::result(exitCode: 127)]);
        $this->artisan('transaction-verification:install-ocr', ['--release' => 'v0.1.0'])
            ->expectsOutputToContain('pdftoppm: not found')
            ->assertSuccessful();
    }
}
