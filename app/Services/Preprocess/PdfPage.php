<?php

namespace Modules\TransactionVerification\Services\Preprocess;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/** Page 1 of a PDF receipt as a temp PNG, for either engine. */
class PdfPage
{
    /** By content, not mime: the engines only get a path. */
    public function isPdf(string $path): bool
    {
        return @file_get_contents($path, false, null, 0, 5) === '%PDF-';
    }

    /**
     * -scale-to bounds the pixels whatever the page size (a 200-inch page renders 2400x2400 in 0.2 s).
     * ponytail: 2400 tuned on screenshots saved as PDF; retune on real PDF receipts.
     *
     * @return string the PNG path; the caller passes it to discard()
     */
    public function render(string $pdf): string
    {
        $config = config('transaction-verification.tesseract');
        // A private directory: pdftoppm creates its PNG with the umask (world-readable), and the name must not collide.
        $dir = sys_get_temp_dir().'/tv_pdf_'.bin2hex(random_bytes(8));

        if (! @mkdir($dir, 0700)) {
            throw new RuntimeException('Could not create a temp directory for the PDF page.');
        }

        $prefix = $dir.'/page';

        try {
            // -gray: both engines grey it anyway, and the PNG comes out about 3x smaller.
            $result = Process::timeout((int) $config['timeout'])->run([
                $config['pdftoppm_binary'], '-png', '-gray', '-f', '1', '-l', '1', '-scale-to', '2400', '-singlefile', $pdf, $prefix,
            ]);
        } catch (\Throwable $e) {
            // A timeout throws, possibly after a partial page (personal data) was written.
            $this->discard($prefix.'.png');

            throw $e;
        }

        if (! $result->successful() || ! is_file($prefix.'.png')) {
            $this->discard($prefix.'.png');

            throw new RuntimeException('pdftoppm failed: '.(Str::limit($result->errorOutput(), 200) ?: 'no page rendered'));
        }

        return $prefix.'.png';
    }

    /** The rendered page and its private directory. Never throws. */
    public function discard(string $page): void
    {
        rescue(function () use ($page) {
            is_file($page) && unlink($page);
            is_dir(dirname($page)) && rmdir(dirname($page));
        }, report: false);
    }
}
