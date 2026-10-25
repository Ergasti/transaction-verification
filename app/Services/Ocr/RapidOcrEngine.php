<?php

namespace Modules\TransactionVerification\Services\Ocr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Modules\TransactionVerification\Contracts\OcrEngine;
use Modules\TransactionVerification\Services\BinaryError;
use Modules\TransactionVerification\Services\Preprocess\ImagePreprocessor;
use Modules\TransactionVerification\Services\Preprocess\PdfPage;
use RuntimeException;

/**
 * The second engine: RapidOCR (PP-OCRv5) from ocr-sidecar/, as the installed binary or the ocr_rapid
 * sidecar over HTTP -- see fetch(). One read, no passes.
 * It gets the upright greyscale image, never the upload itself, so EXIF and PDFs are handled here once.
 */
class RapidOcrEngine implements OcrEngine
{
    private const INSTALL = 'Run php artisan transaction-verification:install-ocr.';

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
            $payload = $this->fetch($image, $config);
        } finally {
            if ($image !== null) {
                rescue(fn () => is_file($image) && unlink($image), report: false);
            }

            if ($page !== null) {
                $this->pdf->discard($page);
            }
        }

        $this->version = is_string($version = $payload['version'] ?? null) ? $version : 'unknown';

        return trim(implode("\n", array_filter((array) ($payload['lines'] ?? []), 'is_string')));
    }

    /**
     * One read by whichever transport is installed: the frozen binary if it is there, else the sidecar over
     * HTTP. Both answer the same JSON, so only the transport differs. Never returns '' for a failure --
     * secondRead() reads a throw as "read nothing" and sends the receipt to a person, where '' would pass
     * for a successful empty read.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function fetch(string $image, array $config): array
    {
        $binary = $config['binary'] ?? null;

        if (! is_string($binary) || $binary === '' || ! is_file($binary)) {
            if (($url = trim((string) ($config['url'] ?? ''))) === '') {
                throw new RuntimeException('The second engine is not installed. '.self::INSTALL);
            }

            return (array) Http::timeout((int) $config['timeout'])
                ->withBody((string) file_get_contents($image), 'image/png')
                ->post(rtrim($url, '/').'/read')
                ->throw()
                ->json();
        }

        $result = Process::timeout((int) $config['timeout'])->run([$binary, $image]);

        // It prints {"error": ...} on stdout and exits non-zero; only a crash leaves anything on stderr.
        if (! $result->successful()) {
            throw new RuntimeException(BinaryError::explain($binary, $result->exitCode(), self::INSTALL)
                ?? 'rapidocr failed: '.(Str::limit($result->errorOutput() ?: $result->output(), 200)
                    ?: 'exit code '.$result->exitCode()));
        }

        if (! is_array($payload = json_decode($result->output(), true))) {
            throw new RuntimeException('rapidocr failed: its answer was not JSON.');
        }

        return $payload;
    }

    public function name(): string
    {
        return 'rapidocr';
    }

    /** From the last read: either transport reports it with every answer. */
    public function version(): string
    {
        return $this->version ?? 'unknown';
    }
}
