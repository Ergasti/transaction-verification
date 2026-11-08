<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Modules\TransactionVerification\Services\Ocr\RapidOcrEngine;
use Modules\TransactionVerification\Services\Preprocess\ImagePreprocessor;
use Modules\TransactionVerification\Tests\TestCase;
use RuntimeException;

/** The sidecar is faked; RealReceiptOcrTest reads the real receipts through the real one. */
class RapidOcrEngineTest extends TestCase
{
    private string $jpeg;

    /** Records the images the engine had made, so only this test's files are checked (paratest shares /tmp). */
    private ImagePreprocessor $spy;

    /** @var list<string> stub binaries to delete; the is_file() branch needs a real file (GOTCHA 2). */
    private array $stubs = [];

    /** Where a stub binary writes the path it was handed, so the argument can be asserted. */
    private string $argv;

    protected function setUp(): void
    {
        parent::setUp();

        config(['transaction-verification.second_engine' => ['mode' => 'always', 'url' => 'http://ocr.test/', 'timeout' => 60]]);
        Http::preventStrayRequests();

        $this->spy = new class extends ImagePreprocessor
        {
            /** @var list<string> */
            public array $made = [];

            public function upright(string $path): string
            {
                return $this->made[] = parent::upright($path);
            }
        };
        $this->app->instance(ImagePreprocessor::class, $this->spy);

        $this->jpeg = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        imagejpeg(imagecreatetruecolor(40, 20), $this->jpeg);
        $this->argv = (string) tempnam(sys_get_temp_dir(), 'tv_argv_');
    }

    /**
     * A stub binary that records its argument and prints `$stdout`. A real script, not a Process fake:
     * the point is to exercise Process::run() and the exit code the engine branches on.
     */
    private function useBinary(string $stdout, int $exit = 0): string
    {
        $binary = $this->stubs[] = (string) tempnam(sys_get_temp_dir(), 'tv_stub_');
        file_put_contents($binary, "#!/bin/sh\nprintf '%s' \"$1\" > {$this->argv}\ncat <<'JSON'\n{$stdout}\nJSON\nexit {$exit}\n");
        chmod($binary, 0700);

        config(['transaction-verification.second_engine.binary' => $binary]);

        return $binary;
    }

    protected function tearDown(): void
    {
        @unlink($this->jpeg);
        @unlink($this->argv);

        foreach ($this->stubs as $stub) {
            @unlink($stub);
        }

        parent::tearDown();
    }

    public function test_it_sends_an_upright_png_and_joins_the_lines(): void
    {
        Http::fake(['ocr.test/read' => Http::response(['lines' => ['To Instapay', ' 01000000001 ', 7], 'version' => 'rapidocr 3.9.2'])]);

        $engine = app(RapidOcrEngine::class);

        $this->assertSame("To Instapay\n 01000000001", $engine->read($this->jpeg));
        $this->assertSame('rapidocr 3.9.2', $engine->version());
        // Never the upload's own bytes: always a PNG made by the preprocessor.
        Http::assertSent(fn (Request $request) => $request->url() === 'http://ocr.test/read'
            && $request->header('Content-Type') === ['image/png']
            && str_starts_with($request->body(), "\x89PNG"));
        $this->assertMadeAndRemoved(1);
    }

    public function test_a_failing_or_unreachable_sidecar_throws_leaves_no_temp_file_and_forgets_the_version(): void
    {
        Http::fake(['ocr.test/read' => Http::sequence()
            ->push(['lines' => [], 'version' => 'rapidocr good'])
            ->push(['error' => 'RuntimeError'], 500)
            ->pushFailedConnection('Connection refused')]);

        $engine = app(RapidOcrEngine::class);
        $engine->read($this->jpeg);
        $this->assertSame('rapidocr good', $engine->version());

        foreach ([RequestException::class, ConnectionException::class] as $expected) {
            try {
                $engine->read($this->jpeg);
                $this->fail('A sidecar failure must throw.');
            } catch (RequestException|ConnectionException $e) {
                $this->assertInstanceOf($expected, $e);
            }

            // The same instance: a failed read is never stored under the last good read's version.
            $this->assertSame('unknown', $engine->version());
        }

        $this->assertMadeAndRemoved(3);
    }

    public function test_a_pdf_is_rendered_first_and_the_page_removed(): void
    {
        $pdf = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        file_put_contents($pdf, "%PDF-1.4\n");
        $page = null;

        // Narrowed to the renderer: '*' would also swallow a configured binary's read and go green for it.
        Process::fake(["'pdftoppm'*" => function (PendingProcess $process) use (&$page) {
            $page = end($process->command).'.png';
            imagepng(imagecreatetruecolor(30, 30), $page);

            return Process::result();
        }]);
        Http::fake(['ocr.test/read' => Http::response(['lines' => ['page one'], 'version' => 'v'])]);

        try {
            $this->assertSame('page one', app(RapidOcrEngine::class)->read($pdf));
        } finally {
            @unlink($pdf);
        }

        Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'pdftoppm');
        $this->assertFileDoesNotExist($page);
        $this->assertDirectoryDoesNotExist(dirname($page));
        $this->assertMadeAndRemoved(1);
    }

    public function test_an_installed_binary_reads_the_image_instead_of_the_sidecar(): void
    {
        $this->useBinary('{"lines":["To Instapay"," 01000000001 ",7],"version":"rapidocr 3.9.2"}');

        $engine = app(RapidOcrEngine::class);

        $this->assertSame("To Instapay\n 01000000001", $engine->read($this->jpeg));
        $this->assertSame('rapidocr 3.9.2', $engine->version());
        // The binary wins over a configured URL, and gets the preprocessor's PNG, never the upload.
        Http::assertNothingSent();
        $this->assertSame($this->spy->made[0], file_get_contents($this->argv));
        $this->assertMadeAndRemoved(1);
    }

    public function test_a_failing_binary_throws_leaves_no_temp_file_and_forgets_the_version(): void
    {
        $this->useBinary('{"lines":[],"version":"rapidocr good"}');
        $engine = app(RapidOcrEngine::class);
        $engine->read($this->jpeg);
        $this->assertSame('rapidocr good', $engine->version());

        // Its error body goes to stdout, not stderr, so the message has to come from there.
        $this->useBinary('{"error":"not an image"}', 3);

        try {
            $engine->read($this->jpeg);
            $this->fail('A failing binary must throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not an image', $e->getMessage());
        }

        $this->assertSame('unknown', $engine->version());
        $this->assertMadeAndRemoved(2);
    }

    public function test_an_answer_that_is_not_json_throws_rather_than_reading_nothing(): void
    {
        // '' would pass for a successful empty read; secondRead() must see a throw and ask a person.
        $this->useBinary('Traceback (most recent call last): boom');

        $engine = app(RapidOcrEngine::class);

        $this->expectException(RuntimeException::class);

        try {
            $engine->read($this->jpeg);
        } finally {
            $this->assertSame('unknown', $engine->version());
            $this->assertMadeAndRemoved(1);
        }
    }

    public function test_a_binary_that_is_not_installed_falls_back_to_the_sidecar(): void
    {
        config(['transaction-verification.second_engine.binary' => '/nonexistent/tv-ocr']);
        Http::fake(['ocr.test/read' => Http::response(['lines' => ['over http'], 'version' => 'v'])]);

        $this->assertSame('over http', app(RapidOcrEngine::class)->read($this->jpeg));
        $this->assertMadeAndRemoved(1);
    }

    public function test_a_pdf_is_rendered_then_handed_to_the_binary(): void
    {
        $pdf = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        file_put_contents($pdf, "%PDF-1.4\n");
        $page = null;

        // Only the renderer is faked; the stub binary below runs for real.
        Process::fake(["'pdftoppm'*" => function (PendingProcess $process) use (&$page) {
            $page = end($process->command).'.png';
            imagepng(imagecreatetruecolor(30, 30), $page);

            return Process::result();
        }]);
        $this->useBinary('{"lines":["page one"],"version":"v"}');

        try {
            $this->assertSame('page one', app(RapidOcrEngine::class)->read($pdf));
        } finally {
            @unlink($pdf);
        }

        Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'pdftoppm');
        Http::assertNothingSent();
        $this->assertFileDoesNotExist($page);
        $this->assertDirectoryDoesNotExist(dirname($page));
        $this->assertMadeAndRemoved(1);
    }

    /** Neither installed nor configured: the error names the command instead of a request to nowhere. */
    public function test_it_says_the_engine_is_not_installed_when_there_is_no_binary_and_no_url(): void
    {
        config(['transaction-verification.second_engine.binary' => null, 'transaction-verification.second_engine.url' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The second engine is not installed. Run php artisan transaction-verification:install-ocr.');

        app(RapidOcrEngine::class)->read($this->jpeg);
    }

    private function assertMadeAndRemoved(int $count): void
    {
        $this->assertCount($count, $this->spy->made);

        foreach ($this->spy->made as $file) {
            $this->assertFileDoesNotExist($file);
        }
    }
}
