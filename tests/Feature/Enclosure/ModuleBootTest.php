<?php

namespace Modules\TransactionVerification\Tests\Feature\Enclosure;

use Modules\TransactionVerification\Contracts\OcrEngine;
use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\TransactionVerifier;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Models\TransactionVerification;
use Modules\TransactionVerification\Models\TransactionVerificationDuplicate;
use Modules\TransactionVerification\Services\Ocr\NullEngine;
use Modules\TransactionVerification\Services\Ocr\TesseractEngine;
use Modules\TransactionVerification\Services\Parsers\InstaPayReceiptParser;
use Modules\TransactionVerification\Services\TransactionVerificationService;
use Modules\TransactionVerification\Tests\TestCase;

/** Wiring has no call sites, so a dropped binding or config merge only shows up here. */
class ModuleBootTest extends TestCase
{
    public function test_contracts_resolve_to_their_implementations(): void
    {
        $this->assertInstanceOf(TransactionVerificationService::class, app(TransactionVerifier::class));
        $this->assertInstanceOf(InstaPayReceiptParser::class, app(ReceiptParser::class));
    }

    public function test_the_engine_follows_config(): void
    {
        // Pinned: a developer .env may set 'tesseract'.
        config(['transaction-verification.engine' => 'null']);
        $this->assertInstanceOf(NullEngine::class, app(OcrEngine::class));

        config(['transaction-verification.engine' => 'tesseract']);
        $this->assertInstanceOf(TesseractEngine::class, app(OcrEngine::class));
    }

    public function test_the_shipped_defaults_are_tesseract_both_engines_and_ninety_percent(): void
    {
        // The file, not config(): a developer .env may change them.
        $file = (string) file_get_contents(__DIR__.'/../../../config/transaction-verification.php');

        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_ENGINE', 'tesseract')", $file);
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_SECOND_ENGINE', 'always')", $file);
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_CONFIDENCE', 0.90)", $file);
        // Nothing is exposed until a caller exists and its secret is set.
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_API_ENABLED', false)", $file);
        // Callers submit no receipt until someone switches it on (shadow mode first).
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_ENABLED', false)", $file);
        // Nothing host-specific: the app's default connection unless set. No disk: receipts are never stored.
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_DB_CONNECTION')", $file);
        $this->assertStringNotContainsString('TRANSACTION_VERIFICATION_DISK', $file);
    }

    public function test_the_parser_follows_config(): void
    {
        // Resolved once first, so a singleton binding (stuck on the first parser) would fail below.
        $this->assertInstanceOf(InstaPayReceiptParser::class, app(ReceiptParser::class));
        config(['transaction-verification.parser' => StubReceiptParser::class]);

        $this->assertInstanceOf(StubReceiptParser::class, app(ReceiptParser::class));
    }

    public function test_the_models_follow_the_connection_setting(): void
    {
        config(['transaction-verification.connection' => 'other']);

        $this->assertSame('other', (new TransactionVerification)->getConnectionName());
        $this->assertSame('other', (new TransactionVerificationDuplicate)->getConnectionName());
        // An explicit connection still wins.
        $this->assertSame('picked', (new TransactionVerification)->setConnection('picked')->getConnectionName());
    }

    public function test_the_migrations_follow_the_connection_setting(): void
    {
        config(['transaction-verification.connection' => 'other']);

        $files = glob(__DIR__.'/../../../database/migrations/*.php') ?: [];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertSame('other', (require $file)->getConnection(), basename($file));
        }
    }

    public function test_config_is_merged(): void
    {
        // Keys and types only: a developer .env may override the values.
        $this->assertIsFloat(config('transaction-verification.confidence_threshold'));
        $this->assertNull(config('transaction-verification.disk'), 'receipts are never stored');
        $this->assertNotEmpty(config('transaction-verification.engine'));
        $this->assertNotEmpty(config('transaction-verification.tesseract.passes'));
        // Not env-overridable: a decimal point lost at one size must be checked at another.
        $this->assertCount(2, array_unique(array_column(config('transaction-verification.tesseract.passes'), 0)));
        $this->assertGreaterThan(0.75, config('transaction-verification.confidence_threshold'));
    }
}

/** Any ReceiptParser an app plugs in through config. */
class StubReceiptParser implements ReceiptParser
{
    public function parse(string $text): ExtractedFields
    {
        return new ExtractedFields;
    }
}
