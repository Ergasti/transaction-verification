<?php

namespace Modules\TransactionVerification\Tests\Unit;

use GdImage;
use Modules\TransactionVerification\Services\Preprocess\ImagePreprocessor;
use RuntimeException;
use Modules\TransactionVerification\Tests\TestCase;

class ImagePreprocessorTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map(fn (string $file) => @unlink($file), $this->files);

        parent::tearDown();
    }

    public function test_it_doubles_a_screenshot_and_returns_a_png(): void
    {
        $out = $this->binarise($this->png(100, 50));

        $this->assertSame([200, 100, IMAGETYPE_PNG], array_slice(getimagesize($out), 0, 3));
    }

    public function test_the_scale_can_be_chosen_per_pass(): void
    {
        $this->assertSame([150, 75], array_slice(getimagesize($this->files[] = (new ImagePreprocessor)->binarise($this->png(100, 50), 0.80, 1.5)), 0, 2));
    }

    public function test_all_passes_come_back_in_pass_order_at_their_own_sizes(): void
    {
        $out = (new ImagePreprocessor)->binariseAll($this->png(100, 50), [[2, 0.75], [1.5, 0.80], [2, 0.85]]);
        array_push($this->files, ...$out);

        $this->assertSame([[200, 100], [150, 75], [200, 100]], array_map(fn (string $f) => array_slice(getimagesize($f), 0, 2), $out));
    }

    public function test_passes_sharing_a_scale_are_the_same_bytes_as_one_at_a_time(): void
    {
        // A grey ramp, so each threshold cuts it at a different place.
        $source = $this->png(256, 4, function (GdImage $image) {
            for ($x = 0; $x < 256; $x++) {
                imageline($image, $x, 0, $x, 3, imagecolorallocate($image, $x, $x, $x));
            }
        });
        $passes = [[2, 0.30], [1.5, 0.50], [2, 0.80]];

        $together = (new ImagePreprocessor)->binariseAll($source, $passes);
        $alone = array_map(fn (array $pass) => (new ImagePreprocessor)->binarise($source, $pass[1], $pass[0]), $passes);
        array_push($this->files, ...$together, ...$alone);

        $this->assertSame(array_map('sha1_file', $alone), array_map('sha1_file', $together));
        // Reusing one palette must not carry the first cut into the third.
        $this->assertNotSame(sha1_file($together[0]), sha1_file($together[2]));
    }

    public function test_a_wide_upload_is_capped(): void
    {
        $this->assertSame(2200, getimagesize($this->binarise($this->png(1500, 10)))[0]);
    }

    public function test_an_upload_wider_than_the_cap_is_never_shrunk_at_2x(): void
    {
        $this->assertSame(2400, getimagesize($this->binarise($this->png(2400, 10)))[0]);
    }

    public function test_a_tall_upload_stays_inside_the_pixel_budget(): void
    {
        [$width, $height] = getimagesize($this->binarise($this->png(1000, 6000)));

        $this->assertLessThanOrEqual(8_000_000, $width * $height);
        $this->assertGreaterThan(1000, $width);
    }

    public function test_a_capped_screenshot_keeps_its_passes_at_different_sizes(): void
    {
        // A native 1440x3200 screenshot hits the pixel budget at 2x; the 1.5x pass must still come out smaller.
        $source = $this->png(1440, 3200);
        [$large, $largeHeight] = getimagesize($this->binarise($source, 0.80));
        $small = getimagesize($this->files[] = (new ImagePreprocessor)->binarise($source, 0.80, 1.5))[0];

        $this->assertLessThanOrEqual(8_000_000, $large * $largeHeight);
        $this->assertEqualsWithDelta($large * 0.75, $small, 1);
    }

    public function test_an_image_too_large_is_refused_before_it_is_decoded(): void
    {
        $this->expectExceptionMessage('Image too large to read.');

        $this->binarise($this->png(4000, 2600));
    }

    public function test_a_file_over_the_byte_limit_is_refused_before_it_is_read(): void
    {
        config(['transaction-verification.max_file_bytes' => 100]);

        $this->expectExceptionMessage('Image file too large to read.');

        $this->binarise($this->png(100, 100));
    }

    public function test_grey_text_turns_black_and_the_pale_background_white(): void
    {
        // Left half: the grey of the phone and labels. Right half: the card background.
        $out = $this->binarise($this->png(20, 10, function (GdImage $image) {
            imagefilledrectangle($image, 0, 0, 9, 9, imagecolorallocate($image, 0xAA, 0xAA, 0xAA));
            imagefilledrectangle($image, 10, 0, 19, 9, imagecolorallocate($image, 0xF5, 0xF5, 0xF5));
        }), 0.80);

        $this->assertSame(0, $this->grey($out, 5, 10));
        $this->assertSame(255, $this->grey($out, 34, 10));
    }

    public function test_transparency_becomes_white_not_black(): void
    {
        $out = $this->binarise($this->png(10, 10, function (GdImage $image) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefilledrectangle($image, 0, 0, 9, 9, imagecolorallocatealpha($image, 0, 0, 0, 127));
        }));

        $this->assertSame(255, $this->grey($out, 10, 10));
    }

    public function test_a_photo_tagged_rotate_90_clockwise_is_turned_upright(): void
    {
        // Landscape 100x50 with the left half black; turned clockwise, the black ends up on top.
        $out = $this->binarise($this->jpeg(orientation: 6));

        $this->assertSame([100, 200], array_slice(getimagesize($out), 0, 2));
        $this->assertSame(0, $this->grey($out, 50, 20));
        $this->assertSame(255, $this->grey($out, 50, 180));
    }

    public function test_a_photo_tagged_rotate_90_counter_clockwise_is_turned_upright(): void
    {
        $out = $this->binarise($this->jpeg(orientation: 8));

        $this->assertSame([100, 200], array_slice(getimagesize($out), 0, 2));
        $this->assertSame(255, $this->grey($out, 50, 20));
        $this->assertSame(0, $this->grey($out, 50, 180));
    }

    public function test_a_photo_tagged_upside_down_is_turned_upright(): void
    {
        $out = $this->binarise($this->jpeg(orientation: 3));

        $this->assertSame([200, 100], array_slice(getimagesize($out), 0, 2));
        $this->assertSame(255, $this->grey($out, 50, 50));
        $this->assertSame(0, $this->grey($out, 150, 50));
    }

    public function test_a_wide_sideways_photo_is_turned_before_it_is_scaled(): void
    {
        // Stored 3000x1300 sideways: scaling first would cap on the sideways width and keep it 1300 wide.
        [$width, $height] = getimagesize($this->binarise($this->jpeg(orientation: 6, width: 3000, height: 1300)));

        $this->assertGreaterThan(1300, $width);
        $this->assertGreaterThan($width, $height);
    }

    public function test_an_untagged_photo_is_not_rotated(): void
    {
        $out = $this->binarise($this->jpeg(orientation: null));

        $this->assertSame([200, 100], array_slice(getimagesize($out), 0, 2));
        $this->assertSame(0, $this->grey($out, 50, 50));
    }

    public function test_a_file_that_is_not_an_image_throws(): void
    {
        $file = $this->files[] = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        file_put_contents($file, 'not an image');

        $this->expectException(RuntimeException::class);

        (new ImagePreprocessor)->binarise($file, 0.80);
    }

    public function test_upright_keeps_grey_levels_and_the_size_for_the_second_engine(): void
    {
        $out = $this->files[] = (new ImagePreprocessor)->upright($this->png(20, 10, function (GdImage $image) {
            imagefilledrectangle($image, 0, 0, 9, 9, imagecolorallocate($image, 128, 128, 128));
        }));

        // No threshold and no 2x: RapidOCR thresholds and scales for itself.
        $this->assertSame([20, 10], array_slice(getimagesize($out), 0, 2));
        // PHP's bundled GD (the production image) palettes 128 as 124 and 255 as 252; system libgd keeps both.
        $this->assertEqualsWithDelta(128, $this->grey($out, 2, 2), 6);
        $this->assertEqualsWithDelta(255, $this->grey($out, 15, 5), 6);
    }

    public function test_upright_turns_a_sideways_photo_and_never_resizes(): void
    {
        $out = $this->files[] = (new ImagePreprocessor)->upright($this->jpeg(orientation: 6));
        $this->assertSame([50, 100], array_slice(getimagesize($out), 0, 2));
        $this->assertLessThan(20, $this->grey($out, 25, 10));

        // Wider than binarise()'s width cap: upright() keeps it (the source cap already bounds its pixels).
        $wide = $this->files[] = (new ImagePreprocessor)->upright($this->png(4000, 100));
        $this->assertSame([4000, 100], array_slice(getimagesize($wide), 0, 2));
    }

    public function test_upright_writes_8_bit_greys_not_rgb(): void
    {
        $out = $this->files[] = (new ImagePreprocessor)->upright($this->png(20, 10));

        $this->assertFalse(imageistruecolor(imagecreatefrompng($out)));
    }

    private function binarise(string $path, float $threshold = 0.80): string
    {
        return $this->files[] = (new ImagePreprocessor)->binarise($path, $threshold);
    }

    private function png(int $width, int $height, ?callable $paint = null): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $paint && $paint($image);

        $file = $this->files[] = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        imagepng($image, $file);

        return $file;
    }

    /** A JPEG (100x50 by default), left half black, with an EXIF Orientation tag spliced in after SOI (GD can't write EXIF). */
    private function jpeg(?int $orientation, int $width = 100, int $height = 50): string
    {
        if ($orientation !== null && ! function_exists('exif_read_data')) {
            $this->markTestSkipped('Needs ext-exif (the Docker image has it).');
        }

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagejpeg($image, null, 95);
        $bytes = (string) ob_get_clean();

        if ($orientation !== null) {
            // Little-endian TIFF: one IFD entry, tag 0x0112 (Orientation), type SHORT, count 1.
            $tiff = "II*\0".pack('V', 8).pack('v', 1).pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\0\0".pack('V', 0);
            $bytes = substr($bytes, 0, 2)."\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\0\0".$tiff.substr($bytes, 2);
        }

        $file = $this->files[] = (string) tempnam(sys_get_temp_dir(), 'tv_test_');
        file_put_contents($file, $bytes);

        // A bad splice must fail here, not pass as "not rotated".
        $this->assertSame($orientation, $orientation === null ? null : @exif_read_data($file)['Orientation'] ?? 'missing');

        return $file;
    }

    private function grey(string $path, int $x, int $y): int
    {
        $image = imagecreatefrompng($path);

        return imagecolorsforindex($image, imagecolorat($image, $x, $y))['red'];
    }
}
