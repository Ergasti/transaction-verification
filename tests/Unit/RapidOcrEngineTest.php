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

/** The sidecar is faked; RealReceiptOcrTest reads the real receipts through the real one. */
class RapidOcrEngineTest extends TestCase
{
    private string $jpeg;

    /** Records the images the engine had made, so only this test's files are checked (paratest shares /tmp). */
    private ImagePreprocessor $spy;

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
    }

    protected function tearDown(): void
    {
        @unlink($this->jpeg);

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

        Process::fake(['*' => function (PendingProcess $process) use (&$page) {
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

    private function assertMadeAndRemoved(int $count): void
    {
        $this->assertCount($count, $this->spy->made);

        foreach ($this->spy->made as $file) {
            $this->assertFileDoesNotExist($file);
        }
    }
}
