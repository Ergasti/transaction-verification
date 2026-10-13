<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Enums\ReceiptFormatEnum;
use Modules\TransactionVerification\Services\Parsers\InstaPayReceiptParser;
use Modules\TransactionVerification\Services\Parsers\ReceiptRouter;
use Modules\TransactionVerification\Tests\TestCase;

/** Which parser reads which text. Synthetic text only. */
class ReceiptRouterTest extends TestCase
{
    private const INSTAPAY = "3,070 EGP\nTransfer Amount\nTo Instapay\n01000000001\nReference 100000000001";

    private const ALEXBANK = "ALEXBANK\nPAY SOMEONE\nBENEFICIARY NAME MOBILE WALLET NUMBER\nPAYEE 00201000000001\nAMOUNT FEE\n3,070.00 EGP 100.00 EGP\nEXECUTION DATE";

    public function test_an_instapay_receipt_goes_to_the_instapay_parser(): void
    {
        $fields = (new ReceiptRouter)->parse(self::INSTAPAY);

        $this->assertSame([ReceiptFormatEnum::INSTAPAY, 307000, '01000000001'], [$fields->format, $fields->amountMinor, $fields->phone]);
    }

    public function test_an_alexbank_confirmation_goes_to_the_alexbank_parser(): void
    {
        $fields = (new ReceiptRouter)->parse(self::ALEXBANK);

        $this->assertSame([ReceiptFormatEnum::ALEXBANK, 307000, '01000000001'], [$fields->format, $fields->amountMinor, $fields->phone]);
    }

    public function test_instapay_is_asked_first(): void
    {
        // A transfer sent from an AlexBank account can show the sender's bank by name.
        $fields = (new ReceiptRouter)->parse("ALEXBANK\n".self::INSTAPAY);

        $this->assertSame(ReceiptFormatEnum::INSTAPAY, $fields->format);
    }

    public function test_text_no_parser_knows_reads_nothing(): void
    {
        $fields = (new ReceiptRouter)->parse("Some Bank\nTotal 3,070.00 EGP\n01000000001");

        $this->assertEquals(new ExtractedFields(format: ReceiptFormatEnum::UNRECOGNISED), $fields);
    }

    public function test_a_configured_parser_that_cannot_recognise_is_skipped(): void
    {
        $this->app->instance('test.blind-parser', new class implements ReceiptParser
        {
            public function parse(string $text): ExtractedFields
            {
                return new ExtractedFields(amountMinor: 1);
            }
        });
        config(['transaction-verification.parsers' => ['test.blind-parser', InstaPayReceiptParser::class]]);

        $this->assertSame(307000, (new ReceiptRouter)->parse(self::INSTAPAY)->amountMinor);
    }
}
