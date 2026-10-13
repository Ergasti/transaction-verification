<?php

namespace Modules\TransactionVerification\Services\Preprocess;

use RuntimeException;

/**
 * Turns a receipt into black text on white, so Tesseract can read the grey labels and phone; or, for RapidOCR,
 * which does its own thresholding, into an upright greyscale copy at its own size.
 */
class ImagePreprocessor
{
    // The spike measured a fixed 2×; the cap only keeps a wide upload from blowing a worker's memory.
    // ponytail: scale and threshold are tuned on 9 receipts from one phone; retune on shadow-mode receipts.
    private const SCALE = 2;

    private const MAX_WIDTH = 2200;

    // Checked before decoding: a truecolor pixel costs 4 bytes, so source + output stay under ~64 MB,
    // whatever the worker's memory_limit. A 1440x3200 phone screenshot is 4.6 M pixels.
    private const MAX_SOURCE_PIXELS = 8_000_000;

    private const MAX_OUTPUT_PIXELS = 8_000_000;

    /** @return string a temp PNG path; the caller deletes it */
    public function binarise(string $path, float $threshold, float $scale = self::SCALE): string
    {
        return $this->binariseAll($path, [[$scale, $threshold]])[0];
    }

    /**
     * One PNG per [scale, threshold] pass, in pass order; the caller deletes them. Passes of one scale share the
     * decode, resize and quantise; each only thresholds the palette, so the bytes match binarise() one at a time.
     *
     * @param  list<array{0: float|int, 1: float|int}>  $passes
     * @return list<string>
     */
    public function binariseAll(string $path, array $passes): array
    {
        $source = $this->source($path);
        $byScale = [];
        foreach ($passes as $i => [$scale, $threshold]) {
            $byScale[(string) (float) $scale][$i] = (float) $threshold;
        }

        $out = [];

        try {
            foreach ($byScale as $scale => $thresholds) {
                // The caps shrink every pass by the same factor, so a 1.5x pass stays smaller than the 2x ones.
                $image = $this->scaled($source, fn (float $limit) => (float) $scale * min(1, $limit / self::SCALE));

                // Threshold the (at most 256) palette entries, not every pixel; each pass starts from the greys.
                imagetruecolortopalette($image, false, 256);
                $greys = array_map(fn (int $i) => imagecolorsforindex($image, $i)['red'], range(0, imagecolorstotal($image) - 1));

                foreach ($thresholds as $i => $threshold) {
                    $cut = (int) round($threshold * 255);
                    foreach ($greys as $index => $grey) {
                        $value = $grey < $cut ? 0 : 255;
                        imagecolorset($image, $index, $value, $value, $value);
                    }
                    $out[$i] = $this->save($image);
                }

                // Released before the next scale: source + one output, as the pixel caps assume.
                unset($image);
            }
        } catch (\Throwable $e) {
            foreach ($out as $file) {
                @unlink($file);
            }

            throw $e;
        }

        ksort($out);

        return array_values($out);
    }

    /** @return string a temp PNG path, upright and greyscale, never enlarged; the caller deletes it */
    public function upright(string $path): string
    {
        $image = $this->scaled($this->source($path), fn (float $limit) => min(1, $limit));
        // 8-bit greys, not RGB: a third of the bytes, so a noisy 8 MP photo stays well under the sidecar's 10 MB.
        imagetruecolortopalette($image, false, 256);

        return $this->save($image);
    }

    /** Decoded and turned upright, after the size checks. */
    private function source(string $path): \GdImage
    {
        // Bytes first: a tiny image padded with junk passes the pixel check but would still be read whole.
        if (! is_file($path) || filesize($path) > (int) config('transaction-verification.max_file_bytes')) {
            throw new RuntimeException('Image file too large to read.');
        }

        $size = @getimagesize($path);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new RuntimeException('Not a readable image.');
        }

        if ($size[0] * $size[1] > self::MAX_SOURCE_PIXELS) {
            throw new RuntimeException('Image too large to read.');
        }

        $source = @imagecreatefromstring((string) @file_get_contents($path));

        if ($source === false) {
            throw new RuntimeException('Not a readable image.');
        }

        // Phone photos store "rotate me" in EXIF, which GD ignores; imagerotate turns counter-clockwise.
        // ponytail: 3/6/8 only; the mirrored 2/4/5/7 (front camera) would be unreadable text anyway.
        $orientation = $size[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')
            ? (int) (@exif_read_data($path)['Orientation'] ?? 1)
            : 1;
        $angle = [3 => 180, 6 => 270, 8 => 90][$orientation] ?? 0;

        if ($angle !== 0) {
            $source = imagerotate($source, $angle, 0) ?: throw new RuntimeException('Could not rotate the image.');
        }

        return $source;
    }

    /**
     * A white-backed greyscale copy at the scale $scaleFor picks.
     *
     * @param  callable(float): float  $scaleFor  the scale, given the largest one the caps allow
     */
    private function scaled(\GdImage $source, callable $scaleFor): \GdImage
    {
        [$width, $height] = [imagesx($source), imagesy($source)];
        $limit = min(max(1, self::MAX_WIDTH / $width), sqrt(self::MAX_OUTPUT_PIXELS / ($width * $height)));
        $scale = min($scaleFor($limit), sqrt(self::MAX_OUTPUT_PIXELS / ($width * $height)));
        // Rounded down: rounding up could step over the pixel budget.
        [$newWidth, $newHeight] = [max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale))];

        // A white canvas flattens transparency; thresholding a transparent PNG otherwise blanks the page.
        $image = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagefilter($image, IMG_FILTER_GRAYSCALE);

        return $image;
    }

    private function save(\GdImage $image): string
    {
        $out = tempnam(sys_get_temp_dir(), 'tv_ocr_');

        if ($out === false) {
            throw new RuntimeException('Could not create a temp file for the preprocessed image.');
        }

        // A full disk can make imagepng() warn (an exception under Laravel) instead of returning false.
        try {
            if (! imagepng($image, $out)) {
                throw new RuntimeException('Could not write the preprocessed image.');
            }
        } catch (\Throwable $e) {
            @unlink($out);

            throw $e;
        }

        return $out;
    }
}
