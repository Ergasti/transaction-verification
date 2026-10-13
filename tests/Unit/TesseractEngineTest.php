<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Modules\TransactionVerification\Services\Ocr\TesseractEngine;
use Modules\TransactionVerification\Services\Preprocess\ImagePreprocessor;
use Modules\TransactionVerification\Services\Preprocess\PdfPage;
use RuntimeException;
use Modules\TransactionVerification\Tests\TestCase;

/** The binary is faked; RealReceiptOcrTest runs the real one. */
class TesseractEngineTest extends TestCase
{
    private string $image;

    protected function setUp(): void
    {
        parent::setUp();

        config(['transaction-verification.tesseract' => [
            'binary' => 'tesseract', 'pdftoppm_binary' => 'pdftoppm', 'langs' => 'ara+eng', 'passes' => [[2, 0.75], [2, 0.80], [1.5, 0.80]], 'timeout' => 15,
        ]]);

        $this->image = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        $gd = imagecreatetruecolor(10, 10);
        imagepng($gd, $this->image);
    }

    protected function tearDown(): void
    {
        @unlink($this->image);

        parent::tearDown();
    }

    public function test_it_reads_once_per_pass_and_joins_the_passes(): void
    {
        Process::fake(['*' => Process::sequence()
            ->push(Process::result("first pass\n\f"))
            ->push(Process::result("second pass\f"))
            ->push(Process::result('third pass'))]);

        $text = $this->engine()->read($this->image);

        // Tesseract's own trailing form feed is stripped, so the parser sees exactly three passes.
        $this->assertSame("first pass\fsecond pass\fthird pass", $text);
        Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === 'tesseract'
            && array_slice($process->command, 2) === ['stdout', '-l', 'ara+eng', '--psm', '3']
            && $process->timeout === 15
            && $process->environment === ['OMP_THREAD_LIMIT' => '1'], 3);
    }

    public function test_passes_run_side_by_side_and_keep_their_order(): void
    {
        // A real stand-in for tesseract: one second per pass, printing which image it read.
        $binary = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        file_put_contents($binary, "#!/bin/sh\nsleep 1\necho \"\$1\"\n");
        chmod($binary, 0700);
        $this->beforeApplicationDestroyed(fn () => @unlink($binary));
        config(['transaction-verification.tesseract.binary' => $binary, 'transaction-verification.tesseract.parallel' => 3]);

        $spy = new class extends ImagePreprocessor
        {
            public array $made = [];

            public function binariseAll(string $path, array $passes): array
            {
                return $this->made = parent::binariseAll($path, $passes);
            }
        };

        $started = microtime(true);
        $text = (new TesseractEngine($spy, new PdfPage))->read($this->image);

        // One after another would take 3 s.
        $this->assertLessThan(2.5, microtime(true) - $started);
        $this->assertSame(implode("\f", $spy->made), $text);
        $this->assertSame([], array_filter($spy->made, 'is_file'));
    }

    public function test_meanwhile_runs_once_while_the_passes_run(): void
    {
        // Each pass takes a second; so does the work done meanwhile. Side by side, the whole read takes about one.
        $binary = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        file_put_contents($binary, "#!/bin/sh\nsleep 1\necho \"\$1\"\n");
        chmod($binary, 0700);
        $this->beforeApplicationDestroyed(fn () => @unlink($binary));
        config(['transaction-verification.tesseract.binary' => $binary, 'transaction-verification.tesseract.parallel' => 3]);
        $calls = 0;

        $started = microtime(true);
        $text = $this->engine()->read($this->image, function () use (&$calls) {
            $calls++;
            sleep(1);
        });

        $this->assertLessThan(1.8, microtime(true) - $started);
        $this->assertSame(1, $calls);
        $this->assertCount(3, explode("\f", $text));
    }

    public function test_meanwhile_runs_once_even_when_the_passes_run_in_chunks(): void
    {
        Process::fake(['*' => Process::result('text')]);
        config(['transaction-verification.tesseract.parallel' => 1]);
        $calls = 0;

        $this->assertSame("text\ftext\ftext", $this->engine()->read($this->image, function () use (&$calls) {
            $calls++;
        }));
        $this->assertSame(1, $calls);
    }

    public function test_only_the_passes_asked_for_are_read(): void
    {
        Process::fake(['*' => Process::result('text')]);

        $this->assertSame('text', $this->engine()->read($this->image, passes: [[2, 0.75]]));
        Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === 'tesseract', 1);
    }

    public function test_a_pdf_is_read_from_its_first_page_rendered_to_png(): void
    {
        $pdf = $this->pdf();
        $page = null;

        Process::fake(['*' => function (PendingProcess $process) use (&$page) {
            if ($process->command[0] !== 'pdftoppm') {
                return Process::result('text');
            }

            copy($this->image, $page = end($process->command).'.png');

            return Process::result();
        }]);

        $this->assertSame("text\ftext\ftext", $this->engine()->read($pdf));
        Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === 'pdftoppm'
            && array_slice($process->command, 1, 9) === ['-png', '-gray', '-f', '1', '-l', '1', '-scale-to', '2400', '-singlefile']
            && $process->command[10] === $pdf, 1);
        Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === 'tesseract', 3);
        $this->assertFileDoesNotExist($page);
    }

    public function test_a_pdf_that_does_not_render_throws_before_any_ocr(): void
    {
        Process::fake(['*' => Process::result('', 'Syntax Error: Couldn\'t find trailer dictionary', 1)]);

        try {
            $this->engine()->read($this->pdf());
            $this->fail('Expected the render to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('pdftoppm failed: Syntax Error', $e->getMessage());
        }

        Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === 'tesseract', 0);
    }

    public function test_nothing_read_in_any_pass_is_an_empty_string(): void
    {
        Process::fake(['*' => Process::result("\f")]);

        $this->assertSame('', $this->engine()->read($this->image));
    }

    public function test_a_failed_run_throws_with_the_error_output(): void
    {
        Process::fake(['*' => Process::result('', 'Error opening data file ara.traineddata', 1)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tesseract failed: Error opening data file ara.traineddata');

        $this->engine()->read($this->image);
    }

    public function test_preprocessed_images_are_deleted_whether_or_not_the_run_succeeds(): void
    {
        // A spy, not a temp-dir glob: parallel test workers create and delete tv_ocr_* files too.
        $spy = new class extends ImagePreprocessor
        {
            public array $made = [];

            public array $args = [];

            public function binariseAll(string $path, array $passes): array
            {
                $this->args[] = $passes;
                $made = parent::binariseAll($path, $passes);
                array_push($this->made, ...$made);

                return $made;
            }
        };
        $engine = new TesseractEngine($spy, new PdfPage);

        Process::fake(['*' => Process::result('text')]);
        $engine->read($this->image);

        // All passes are made up front (they share the resize), so a failed first pass still made all three.
        Process::fake(['*' => Process::result('', 'boom', 1)]);
        rescue(fn () => $engine->read($this->image), report: false);

        $this->assertSame(array_fill(0, 2, [[2, 0.75], [2, 0.80], [1.5, 0.80]]), $spy->args);
        $this->assertCount(6, $spy->made);
        $this->assertSame([], array_filter($spy->made, 'is_file'));
    }

    public function test_the_version_is_read_once_from_either_stream(): void
    {
        Process::fake(['*' => Process::result('', "tesseract 5.5.0\n leptonica-1.86.0")]);
        $engine = $this->engine();

        $this->assertSame('5.5.0', $engine->version());
        $this->assertSame('5.5.0', $engine->version());
        Process::assertRanTimes(fn (PendingProcess $process) => $process->command === ['tesseract', '--version'], 1);
    }

    public function test_an_unknown_version_never_throws(): void
    {
        Process::fake(['*' => Process::result('', 'not found', 127)]);

        $this->assertSame('unknown', $this->engine()->version());
        $this->assertSame('tesseract', $this->engine()->name());
    }

    public function test_the_page_is_rendered_privately_and_removed_on_every_path(): void
    {
        $garbage = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        file_put_contents($garbage, 'not an image');
        $this->beforeApplicationDestroyed(fn () => @unlink($garbage));

        // [what pdftoppm writes, what it then does, what tesseract does]
        $paths = [
            'render throws (timeout)' => [$this->image, 'throw', 'ok'],
            'render writes, then exits non-zero' => [$this->image, 'fail', 'ok'],
            'tesseract fails' => [$this->image, 'ok', 'fail'],
            'page is not a readable image' => [$garbage, 'ok', 'ok'],
            'success' => [$this->image, 'ok', 'ok'],
        ];

        foreach ($paths as $name => [$source, $render, $ocr]) {
            $page = null;
            $private = null;
            Process::fake(['*' => function (PendingProcess $process) use (&$page, &$private, $source, $render, $ocr) {
                if ($process->command[0] !== 'pdftoppm') {
                    return $ocr === 'ok' ? Process::result('text') : Process::result('', 'boom', 1);
                }

                copy($source, $page = end($process->command).'.png');
                $private = fileperms(dirname($page)) & 0777;

                return match ($render) {
                    'throw' => throw new RuntimeException('timed out'),
                    'fail' => Process::result('', 'Syntax Error', 1),
                    default => Process::result(),
                };
            }]);

            rescue(fn () => $this->engine()->read($this->pdf()), report: false);

            $this->assertSame(0700, $private, $name);
            $this->assertFileDoesNotExist($page, $name);
            $this->assertDirectoryDoesNotExist(dirname($page), $name);
        }
    }

    private function pdf(): string
    {
        $pdf = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        file_put_contents($pdf, "%PDF-1.4\n%fake\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($pdf));

        return $pdf;
    }

    private function engine(): TesseractEngine
    {
        return app(TesseractEngine::class);
    }
}
