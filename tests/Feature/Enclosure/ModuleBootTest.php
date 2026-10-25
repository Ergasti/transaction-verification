<?php

namespace Modules\TransactionVerification\Tests\Feature\Enclosure;

use Modules\TransactionVerification\Contracts\OcrEngine;
use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\TransactionVerifier;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Models\TransactionVerification;
use Modules\TransactionVerification\Services\Ocr\NullEngine;
use Modules\TransactionVerification\Services\Ocr\TesseractEngine;
use Modules\TransactionVerification\Services\Parsers\ReceiptRouter;
use Modules\TransactionVerification\Services\TransactionVerificationService;
use Modules\TransactionVerification\Tests\TestCase;

/** Wiring has no call sites, so a dropped binding or config merge only shows up here. */
class ModuleBootTest extends TestCase
{
    public function test_contracts_resolve_to_their_implementations(): void
    {
        $this->assertInstanceOf(TransactionVerificationService::class, app(TransactionVerifier::class));
        $this->assertInstanceOf(ReceiptRouter::class, app(ReceiptParser::class));
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
        // Defaults to where install-ocr unpacks it; is_file() decides, so not-yet-installed falls back to `url`.
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_RAPIDOCR_BINARY', storage_path('app/transaction-verification/ocr/tv-ocr'))", $file);
        // And `url` has no default: it named a compose service that no longer exists, so absence must read as absence.
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_RAPIDOCR_URL')", $file);
        $this->assertStringNotContainsString('ocr_rapid:8080', $file);
        // Poppler is the host's: a bundled default would be a path that never exists.
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_PDFTOPPM_BINARY', 'pdftoppm')", $file);
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_CONFIDENCE', 0.90)", $file);
        // Nothing is exposed until a caller exists and its secret is set.
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_API_ENABLED', false)", $file);
        // On by default: an app that required this package wants it, and a required variable is a step an
        // installer can skip. The variable remains, as the off switch.
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_ENABLED', true)", $file);
        // Nothing host-specific: the app's default connection unless set. No disk: receipts are never stored.
        $this->assertStringContainsString("env('TRANSACTION_VERIFICATION_DB_CONNECTION')", $file);
        $this->assertStringNotContainsString('TRANSACTION_VERIFICATION_DISK', $file);
    }

    /**
     * What the file resolves to, not what it says: an app that sets nothing of its own still gets an enabled
     * package and a second engine. This is the zero-env install.
     */
    public function test_an_app_that_sets_nothing_gets_an_enabled_package(): void
    {
        $settings = require __DIR__.'/../../../config/transaction-verification.php';

        $this->assertTrue($settings['enabled'], 'nothing to switch on');
        $this->assertSame('always', $settings['second_engine']['mode']);
        $this->assertNull($settings['connection'], "the app's own default connection");
    }

    public function test_the_parser_follows_config(): void
    {
        // Resolved once first, so a singleton binding (stuck on the first parser) would fail below.
        $this->assertInstanceOf(ReceiptRouter::class, app(ReceiptParser::class));
        config(['transaction-verification.parser' => StubReceiptParser::class]);

        $this->assertInstanceOf(StubReceiptParser::class, app(ReceiptParser::class));
    }

    public function test_the_models_follow_the_connection_setting(): void
    {
        config(['transaction-verification.connection' => 'other']);

        $this->assertSame('other', (new TransactionVerification)->getConnectionName());
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
