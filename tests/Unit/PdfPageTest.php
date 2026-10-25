<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Modules\TransactionVerification\Services\Preprocess\PdfPage;
use Modules\TransactionVerification\Tests\TestCase;
use RuntimeException;

/** The PDF seam both engines share. pdftoppm is faked; RealReceiptOcrTest renders through the real one. */
class PdfPageTest extends TestCase
{
    private PdfPage $pdf;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        config(['transaction-verification.tesseract.pdftoppm_binary' => 'pdftoppm',
            'transaction-verification.tesseract.timeout' => 15]);

        $this->pdf = new PdfPage;
        $this->file = (string) tempnam(sys_get_temp_dir(), 'tv_pdf_');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /** Fakes the renderer; `$writePage` decides whether a page appears, as a real run's would. */
    private function fakeRenderer(bool $writePage = true, ?string $error = null): void
    {
        Process::fake(["'pdftoppm'*" => function (PendingProcess $process) use ($writePage, $error) {
            if ($writePage) {
                imagepng(imagecreatetruecolor(30, 30), end($process->command).'.png');
            }

            return $error === null ? Process::result() : Process::result(errorOutput: $error, exitCode: 1);
        }]);
    }

    public function test_a_pdf_is_recognised_by_its_bytes_not_its_name(): void
    {
        // The engines only get a path, and an upload's name is the uploader's to choose.
        file_put_contents($this->file, "%PDF-1.4\nnot really");
        $this->assertTrue($this->pdf->isPdf($this->file));

        imagepng(imagecreatetruecolor(10, 10), $this->file);
        $this->assertFalse($this->pdf->isPdf($this->file));

        $this->assertFalse($this->pdf->isPdf('/nonexistent/receipt.pdf'));
    }

    public function test_only_page_one_is_rendered_greyscale_and_bounded(): void
    {
        file_put_contents($this->file, "%PDF-1.4\n");
        $this->fakeRenderer();

        $page = $this->pdf->render($this->file);

        try {
            Process::assertRan(fn (PendingProcess $process) => $process->command === [
                'pdftoppm', '-png', '-gray', '-f', '1', '-l', '1', '-scale-to', '2400', '-singlefile',
                $this->file, dirname($page).'/page',
            ]);
            $this->assertFileExists($page);
        } finally {
            $this->pdf->discard($page);
        }
    }

    public function test_the_page_is_written_to_a_private_directory(): void
    {
        // pdftoppm creates its PNG with the umask; the receipt must not be world-readable while it exists.
        file_put_contents($this->file, "%PDF-1.4\n");
        $this->fakeRenderer();

        $page = $this->pdf->render($this->file);

        try {
            $this->assertSame(0700, fileperms(dirname($page)) & 0777);
        } finally {
            $this->pdf->discard($page);
        }
    }

    public function test_discard_removes_the_page_and_its_directory_and_never_throws(): void
    {
        file_put_contents($this->file, "%PDF-1.4\n");
        $this->fakeRenderer();
        $page = $this->pdf->render($this->file);

        $this->pdf->discard($page);

        $this->assertFileDoesNotExist($page);
        $this->assertDirectoryDoesNotExist(dirname($page));
        // Called twice (the engines' finally blocks can overlap a failure) it must stay quiet.
        $this->pdf->discard($page);
    }

    public function test_a_renderer_failure_throws_a_truncated_message_and_leaves_nothing_behind(): void
    {
        file_put_contents($this->file, "%PDF-1.4\n");
        $this->fakeRenderer(writePage: true, error: 'Syntax Error: '.str_repeat('x', 400));
        $directories = glob(sys_get_temp_dir().'/tv_pdf_*');

        try {
            $this->pdf->render($this->file);
            $this->fail('A renderer failure must throw.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('pdftoppm failed: Syntax Error', $e->getMessage());
            $this->assertLessThan(260, strlen($e->getMessage()));
        }

        // Even a partial page is personal data: the failure path discards it.
        $this->assertSame($directories, glob(sys_get_temp_dir().'/tv_pdf_*'));
    }

    public function test_a_renderer_that_writes_no_page_throws(): void
    {
        file_put_contents($this->file, "%PDF-1.4\n");
        $this->fakeRenderer(writePage: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pdftoppm failed: no page rendered');

        $this->pdf->render($this->file);
    }
}
