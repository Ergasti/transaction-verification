<?php

namespace Modules\TransactionVerification\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Services\Ocr\RapidOcrEngine;
use Modules\TransactionVerification\Services\Ocr\TesseractEngine;
use Modules\TransactionVerification\Services\Parsers\InstaPayReceiptParser;
use PHPUnit\Framework\Attributes\Group;
use Modules\TransactionVerification\Tests\TestCase;

/**
 * The real binary on the real receipts. Both the screenshots and expected.json are git-ignored (personal data),
 * so this runs only where they exist and skips everywhere else, CI included.
 */
#[Group('ocr')]
class RealReceiptOcrTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/instapay';

    public function test_every_real_receipt_is_read_exactly(): void
    {
        $this->needs('tesseract');
        $wrong = [];

        $expected = $this->expected();

        foreach ($this->receipts() as $id => $path) {
            array_push($wrong, ...$this->wrong($id, $expected[$id], $this->read($path)));
        }

        $this->assertSame([], $wrong, 'Fields read wrong: '.implode(', ', $wrong));
    }

    public function test_a_real_receipt_saved_as_pdf_is_read_exactly(): void
    {
        $this->needs('tesseract', 'pdftoppm');
        [$id, $receipt] = $this->oneReceipt();
        $pdf = $this->pdfOf($receipt);

        try {
            $wrong = $this->wrong($id, $this->expected()[$id], $this->read($pdf));
        } finally {
            @unlink($pdf);
        }

        $this->assertSame([], $wrong, 'Fields read wrong: '.implode(', ', $wrong));
    }

    public function test_a_real_receipt_photographed_sideways_is_read_exactly(): void
    {
        $this->needs('tesseract');

        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('Needs ext-exif (the Docker image has it).');
        }

        [$id, $receipt] = $this->oneReceipt();
        $photo = $this->sidewaysPhotoOf($receipt);

        try {
            $wrong = $this->wrong($id, $this->expected()[$id], $this->read($photo));
        } finally {
            @unlink($photo);
        }

        $this->assertSame([], $wrong, 'Fields read wrong: '.implode(', ', $wrong));
    }

    public function test_both_engines_read_every_real_receipt_the_same(): void
    {
        $this->needs('tesseract');

        // Either way of reaching RapidOCR counts: the installed binary first, since that is what a host now
        // runs, and a sidecar URL second. Only with neither is there nothing to cross-confirm against.
        if (! is_file((string) config('transaction-verification.second_engine.binary'))) {
            $url = (string) env('TRANSACTION_VERIFICATION_RAPIDOCR_URL');

            if ($url === '' || ! rescue(fn () => Http::timeout(3)->get(rtrim($url, '/').'/health')->successful(), false, report: false)) {
                $this->markTestSkipped('Needs the engine: run transaction-verification:install-ocr, or point TRANSACTION_VERIFICATION_RAPIDOCR_URL at a running sidecar.');
            }

            config(['transaction-verification.second_engine.binary' => null, 'transaction-verification.second_engine.url' => $url]);
        }

        $wrong = [];

        $expected = $this->expected();

        foreach ($this->receipts() as $id => $path) {
            $second = app(InstaPayReceiptParser::class)->parse(app(RapidOcrEngine::class)->read($path));
            // confirmedBy() drops any field the two read differently to 0, which wrong() reports.
            array_push($wrong, ...$this->wrong($id, $expected[$id], $this->read($path)->confirmedBy($second)));
        }

        $this->assertSame([], $wrong, 'Fields read wrong: '.implode(', ', $wrong));
    }

    private function needs(string ...$binaries): void
    {
        foreach ($binaries as $binary) {
            if (! is_file(self::FIXTURES.'/expected.json') || ! Process::run([$binary, '-v'])->successful()) {
                $this->markTestSkipped("Needs {$binary} and the git-ignored fixtures with expected.json.");
            }
        }
    }

    /**
     * Every receipt expected.json names, paired with the file holding it. The id is whatever the ground truth
     * calls it and the extension is whatever was saved, so neither is assumed here.
     *
     * @return array<string, string>
     */
    private function receipts(): array
    {
        $paths = [];

        foreach (array_keys($this->expected()) as $id) {
            if (! $path = (glob(self::FIXTURES."/{$id}.*") ?: [null])[0]) {
                $this->markTestSkipped("expected.json names {$id}, but the fixtures hold no {$id}.* to read.");
            }

            $paths[(string) $id] = $path;
        }

        if ($paths === []) {
            $this->markTestSkipped('expected.json names no receipts.');
        }

        return $paths;
    }

    /**
     * One receipt, for the cases that re-save a single file rather than read them all.
     *
     * @return array{string, string} id and path
     */
    private function oneReceipt(): array
    {
        $receipts = $this->receipts();

        return [(string) array_key_first($receipts), reset($receipts)];
    }

    private function expected(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES.'/expected.json'), true);
    }

    private function read(string $path): ExtractedFields
    {
        config(['transaction-verification.tesseract.binary' => 'tesseract', 'transaction-verification.tesseract.pdftoppm_binary' => 'pdftoppm']);

        return app(InstaPayReceiptParser::class)->parse(app(TesseractEngine::class)->read($path));
    }

    /** @return list<string> field names only, never the values: the failure message must not print personal data */
    private function wrong(string $id, array $expected, ExtractedFields $fields): array
    {
        $read = [
            'amount_minor' => $fields->amountMinor,
            'phone' => $fields->phone,
            'account' => $fields->account,
            'reference' => $fields->reference,
            'date' => $fields->occurredAt === null ? null : CarbonImmutable::parse($fields->occurredAt)->tz('Africa/Cairo')->format('Y-m-d H:i'),
        ];

        $wrong = array_map(fn (string $field) => "{$id}.{$field}", array_keys(array_diff_assoc(array_map('strval', $expected), array_map('strval', $read))));

        // Every pass agrees on the real receipts, so none of them lands in review.
        foreach (array_keys(array_filter($fields->confidence, fn (float $share) => $share < 1.0)) as $field) {
            $wrong[] = "{$id}.{$field} (passes disagree)";
        }

        return $wrong;
    }

    /** The screenshot stored sideways as a phone camera does: pixels turned left, EXIF Orientation 6 ("turn right to view"). */
    private function sidewaysPhotoOf(string $receipt): string
    {
        $source = imagecreatefromstring((string) file_get_contents($receipt));
        $flat = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
        ob_start();
        imagejpeg(imagerotate($flat, 90, 0), null, 90);
        $jpeg = (string) ob_get_clean();

        $tiff = "II*\0".pack('V', 8).pack('v', 1).pack('vvV', 0x0112, 3, 1).pack('v', 6)."\0\0".pack('V', 0);
        $photo = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        file_put_contents($photo, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\0\0".$tiff.substr($jpeg, 2));

        return $photo;
    }

    /** A one-page PDF holding the screenshot as a JPEG at 150 dpi, the way a "save as PDF" tool writes it. */
    private function pdfOf(string $receipt): string
    {
        $source = imagecreatefromstring((string) file_get_contents($receipt));
        [$w, $h] = [imagesx($source), imagesy($source)];
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $source, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($flat, null, 75);
        $jpeg = (string) ob_get_clean();

        [$pw, $ph] = [round($w * 72 / 150, 2), round($h * 72 / 150, 2)];
        $draw = "q {$pw} 0 0 {$ph} 0 0 cm /Im1 Do Q";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$ph}] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>",
            "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>\nstream\n{$jpeg}\nendstream",
            '<< /Length '.strlen($draw)." >>\nstream\n{$draw}\nendstream",
        ];

        $body = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($body);
            $body .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($body);
        $body .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $body .= sprintf("%010d 00000 n \n", $offset);
        }
        $body .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        $pdf = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        file_put_contents($pdf, $body);

        return $pdf;
    }
}
