<?php

namespace Modules\TransactionVerification\Services\Ocr;

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Modules\TransactionVerification\Contracts\OcrEngine;
use Modules\TransactionVerification\Services\Preprocess\ImagePreprocessor;
use Modules\TransactionVerification\Services\Preprocess\PdfPage;
use RuntimeException;

/** Reads a receipt once per configured pass (scale and threshold); the parser votes across the passes. */
class TesseractEngine implements OcrEngine
{
    private ?string $version = null;

    public function __construct(
        private readonly ImagePreprocessor $preprocessor,
        private readonly PdfPage $pdf,
    ) {}

    /**
     * @param  ?callable  $meanwhile  runs once while the first passes run (never if preparing the images fails)
     * @param  ?list<array{0: float|int, 1: float|int}>  $passes  [scale, threshold] passes to read; the config's when null
     */
    public function read(string $localPath, ?callable $meanwhile = null, ?array $passes = null): string
    {
        $config = config('transaction-verification.tesseract');
        $page = $this->pdf->isPdf($localPath) ? $this->pdf->render($localPath) : null;
        $texts = [];
        $images = [];

        try {
            // All passes' images at once: passes of one scale share the decode and resize.
            $images = $this->preprocessor->binariseAll($page ?? $localPath, $passes ?? $config['passes']);

            // The passes are independent, so they run side by side, `parallel` at a time.
            foreach (array_chunk($images, max(1, (int) ($config['parallel'] ?? 1)), true) as $group) {
                // Each on one thread: same speed per pass, less than half the CPU (plan §2).
                $running = Process::pool(function (Pool $pool) use ($group, $config) {
                    foreach ($group as $i => $image) {
                        $pool->as((string) $i)->timeout((int) $config['timeout'])->env(['OMP_THREAD_LIMIT' => '1'])->command([
                            $config['binary'], $image, 'stdout', '-l', $config['langs'], '--psm', '3',
                        ]);
                    }
                })->start();

                try {
                    // The passes are separate processes, so the second engine can read while they do.
                    [$alongside, $meanwhile] = [$meanwhile, null];
                    $alongside && $alongside();
                } finally {
                    $results = $running->wait();
                }

                foreach (array_keys($group) as $i) {
                    $result = $results[(string) $i];

                    if (! $result->successful()) {
                        throw new RuntimeException('tesseract failed: '.Str::limit($result->errorOutput(), 200));
                    }

                    // Tesseract ends each page with a form feed; strip it so it isn't taken for an empty pass.
                    $texts[] = trim($result->output(), "\f\n\r\t ");
                }
            }
        } finally {
            foreach ($images as $image) {
                rescue(fn () => is_file($image) && unlink($image), report: false);
            }

            if ($page !== null) {
                $this->pdf->discard($page);
            }
        }

        return array_filter($texts, fn (string $text) => $text !== '') === [] ? '' : implode("\f", $texts);
    }

    public function name(): string
    {
        return 'tesseract';
    }

    /** Called after the verdict is decided, so it never throws. */
    public function version(): string
    {
        return $this->version ??= rescue(function () {
            $result = Process::timeout(10)->run([config('transaction-verification.tesseract.binary'), '--version']);

            // Some builds print the version to stderr.
            return preg_match('/tesseract\s+v?(\S+)/i', $result->output().$result->errorOutput(), $m) ? $m[1] : 'unknown';
        }, 'unknown', report: false);
    }
}
