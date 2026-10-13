<?php

namespace Modules\TransactionVerification\Services\Ocr;

use Illuminate\Support\Facades\Http;
use Modules\TransactionVerification\Contracts\OcrEngine;
use Modules\TransactionVerification\Services\Preprocess\ImagePreprocessor;
use Modules\TransactionVerification\Services\Preprocess\PdfPage;

/**
 * The second engine: RapidOCR (PP-OCRv5) in the ocr_rapid sidecar (ocr-sidecar/). One read, no passes.
 * It gets the upright greyscale image, never the upload itself, so EXIF and PDFs are handled here once.
 */
class RapidOcrEngine implements OcrEngine
{
    private ?string $version = null;

    public function __construct(
        private readonly ImagePreprocessor $preprocessor,
        private readonly PdfPage $pdf,
    ) {}

    public function read(string $localPath): string
    {
        $config = config('transaction-verification.second_engine');
        // A failed read must not be recorded under the last good read's version.
        $this->version = null;
        $page = $this->pdf->isPdf($localPath) ? $this->pdf->render($localPath) : null;
        $image = null;

        try {
            $image = $this->preprocessor->upright($page ?? $localPath);
            $response = Http::timeout((int) $config['timeout'])
                ->withBody((string) file_get_contents($image), 'image/png')
                ->post(rtrim($config['url'], '/').'/read')
                ->throw();
        } finally {
            if ($image !== null) {
                rescue(fn () => is_file($image) && unlink($image), report: false);
            }

            if ($page !== null) {
                $this->pdf->discard($page);
            }
        }

        $this->version = is_string($version = $response->json('version')) ? $version : 'unknown';

        return trim(implode("\n", array_filter((array) $response->json('lines'), 'is_string')));
    }

    public function name(): string
    {
        return 'rapidocr';
    }

    /** From the last read: the sidecar reports it with every answer. */
    public function version(): string
    {
        return $this->version ?? 'unknown';
    }
}
