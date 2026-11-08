<?php

namespace Modules\TransactionVerification\Services\Preprocess;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Modules\TransactionVerification\Services\BinaryError;
use RuntimeException;

/** Page 1 of a PDF receipt as a temp PNG, for either engine. */
class PdfPage
{
    // Poppler is the host's, not ours: install-ocr does not and will not provide it.
    private const INSTALL = 'This receipt is a PDF, which needs Poppler: apt install poppler-utils, or brew install poppler.';

    /** By content, not mime: the engines only get a path. */
    public function isPdf(string $path): bool
    {
        return @file_get_contents($path, false, null, 0, 5) === '%PDF-';
    }

    /**
     * Scaled by **width**, not to a box: -scale-to caps the longest side, so a stacked A4 confirmation
     * (2479x7016) rendered 849 px wide and digits were misread -- the same defect server.py fixes with
     * slicing. -scale-to-y -1 keeps the aspect ratio from the width.
     *
     * A page of any shape stays bounded by the timeout below and by ImagePreprocessor's 30 M-pixel
     * pre-decode check, so no third cap is needed here.
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
                $config['pdftoppm_binary'], '-png', '-gray', '-f', '1', '-l', '1', '-scale-to-x', '2400', '-scale-to-y', '-1', '-singlefile', $pdf, $prefix,
            ]);
        } catch (\Throwable $e) {
            // A timeout throws, possibly after a partial page (personal data) was written.
            $this->discard($prefix.'.png');

            throw $e;
        }

        if (! $result->successful() || ! is_file($prefix.'.png')) {
            $this->discard($prefix.'.png');

            throw new RuntimeException(BinaryError::explain($config['pdftoppm_binary'], $result->exitCode(), self::INSTALL)
                ?? 'pdftoppm failed: '.(Str::limit($result->errorOutput(), 200) ?: 'no page rendered'));
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
