<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Modules\TransactionVerification\Data\ExpectedDestination;
use Modules\TransactionVerification\Data\VerificationRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Modules\TransactionVerification\Tests\TestCase;

class DataValidationTest extends TestCase
{
    public function test_an_unknown_destination_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExpectedDestination('bank_account', '1234');
    }

    public function test_an_empty_destination_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExpectedDestination(ExpectedDestination::PHONE, '  ');
    }

    /** @return array<string, array{string, string|int, string, int, string}> */
    public static function badRequests(): array
    {
        return [
            'subject type too long' => [str_repeat('a', 65), 1, 'key', 205000, 'EGP'],
            'subject id too long' => ['affiliate_payout', str_repeat('1', 65), 'key', 205000, 'EGP'],
            'idempotency key too long' => ['affiliate_payout', 1, str_repeat('k', 256), 205000, 'EGP'],
            'empty idempotency key' => ['affiliate_payout', 1, '', 205000, 'EGP'],
            'zero amount' => ['affiliate_payout', 1, 'key', 0, 'EGP'],
            'negative amount' => ['affiliate_payout', 1, 'key', -100, 'EGP'],
            'bad currency' => ['affiliate_payout', 1, 'key', 205000, 'EGPX'],
            'currency with a trailing newline' => ['affiliate_payout', 1, 'key', 205000, "EGP\n"],
        ];
    }

    #[DataProvider('badRequests')]
    public function test_bad_requests_are_rejected(string $subjectType, string|int $subjectId, string $key, int $amount, string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VerificationRequest(
            $subjectType,
            $subjectId,
            $amount,
            new ExpectedDestination(ExpectedDestination::PHONE, '01000000001'),
            UploadedFile::fake()->image('r.png'),
            $key,
            currency: $currency,
        );
    }
}
