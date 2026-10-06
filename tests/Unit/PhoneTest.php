<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Modules\TransactionVerification\Services\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    /** @return array<string, array{?string, string}> */
    public static function normalised(): array
    {
        return [
            'null' => [null, ''],
            'empty' => ['', ''],
            'no digits' => ['abc', ''],
            'international with spaces' => ['+20 100 000 0001', '01000000001'],
            'country code' => ['201000000001', '01000000001'],
            'country code with 0' => ['0201000000001', '01000000001'],
            'country code with 00' => ['00201000000001', '01000000001'],
            'no trunk zero' => ['1000000001', '01000000001'],
            'extra leading zeros' => ['0001000000001', '01000000001'],
            'one digit too many' => ['010000000019', '01000000001'],
            'arabic-indic digits' => ['٠١٠٠٠٠٠٠٠٠١', '01000000001'],
            'persian digits' => ['۰۱۰۰۰۰۰۰۰۰۱', '01000000001'],
            'junk kept, not validated' => ['12345', '012345'],
        ];
    }

    #[DataProvider('normalised')]
    public function test_normalise(?string $input, string $expected): void
    {
        $this->assertSame($expected, Phone::normalise($input));
    }

    /** @return array<string, array{?string, bool}> */
    public static function mobiles(): array
    {
        return [
            '010' => ['01000000001', true],
            '015' => ['01500000001', true],
            '013 is no mobile prefix' => ['01300000001', false],
            'too short' => ['12345', false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('mobiles')]
    public function test_is_egyptian_mobile(?string $input, bool $expected): void
    {
        $this->assertSame($expected, Phone::isEgyptianMobile($input));
    }

    public function test_it_matches_the_app_helpers_it_was_ported_from(): void
    {
        // The normalised phone feeds the blind index: any difference orphans stored hashes.
        if (! function_exists('normalizePhoneNumber')) {
            $this->markTestSkipped('Only inside the app.');
        }

        $inputs = [...array_column(self::normalised(), 0), ...array_column(self::mobiles(), 0),
            ' 010-0000-0001 ', '+201500000001', '(010) 0000 0001', '0020 ١٠٠٠٠٠٠٠٠١', '01'];

        foreach ($inputs as $input) {
            $this->assertSame(normalizePhoneNumber($input), Phone::normalise($input), var_export($input, true));
            $this->assertSame(isValidEgyptianMobile($input), Phone::isEgyptianMobile($input), var_export($input, true));
        }
    }
}
