<?php

namespace Modules\TransactionVerification\Tests\Unit;

use Modules\TransactionVerification\Contracts\ReceiptParser;
use Modules\TransactionVerification\Contracts\RecognisesReceipts;
use Modules\TransactionVerification\Data\ExtractedFields;
use Modules\TransactionVerification\Enums\ReceiptFormatEnum;
use Modules\TransactionVerification\Services\Parsers\InstaPayReceiptParser;
use Modules\TransactionVerification\Services\Parsers\ReceiptRouter;
use Modules\TransactionVerification\Services\Parsers\VotesAcrossPasses;
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

    public function test_a_whole_alexbank_confirmation_in_either_layout_goes_to_the_alexbank_parser(): void
    {
        // Its "CASH TRANSFER" sits right above "AMOUNT": that is not InstaPay's "Transfer Amount".
        $tesseract = AlexBankReceiptParserTest::confirmation('3,070.00', '00201000000001');
        $rapid = "ALEXBANK\nPAY SOMEONE\nCUSTOMER NAME\nACCOUNT\n900000000001\nMOBILE WALLET NUMBER\n00201000000001\n"
            ."PAYMENT TYPE\nPURPOSE OF TRANSFER\nMobile wallet\nCASH TRANSFER\nAMOUNT\nFEE\n3,070.00 EGP\n100.00 EGP\nEXECUTION DATE";

        foreach ([$tesseract, $rapid] as $text) {
            $fields = (new ReceiptRouter)->parse($text);

            $this->assertSame([ReceiptFormatEnum::ALEXBANK, 307000, '01000000001', null], [$fields->format, $fields->amountMinor, $fields->phone, $fields->reference]);
        }
    }

    public function test_an_alexbank_description_that_reads_like_instapay_stays_alexbank(): void
    {
        // The sender types the description: "Transfer Amount" or an InstaPay address there is not an InstaPay receipt.
        $tesseract = AlexBankReceiptParserTest::confirmation('3,070.00', '00201000000001');
        $rapid = "ALEXBANK\nPAY SOMEONE\nCUSTOMER NAME\nACCOUNT\n900000000001\nMOBILE WALLET NUMBER\n00201000000001\n"
            ."PAYMENT TYPE\nPURPOSE OF TRANSFER\nMobile wallet\nCASH TRANSFER\nAMOUNT\nFEE\n3,070.00 EGP\n100.00 EGP\nEXECUTION DATE\n"
            ."DESCRIPTION\nMarketing Fees\n17/02/2025";

        foreach ([$tesseract, $rapid] as $text) {
            foreach (['Transfer Amount', 'payee@instapay'] as $description) {
                $fields = (new ReceiptRouter)->parse(str_replace('Marketing Fees', $description, $text));

                $this->assertSame([ReceiptFormatEnum::ALEXBANK, 307000, null], [$fields->format, $fields->amountMinor, $fields->reference], $description);
            }
        }
    }

    public function test_a_known_format_is_read_by_its_parser_even_unrecognised(): void
    {
        // A second reading that lost "Transfer Amount": read as the InstaPay receipt the first reading recognised.
        $fields = (new ReceiptRouter)->parseAs("To Instapay\n01000000001", ReceiptFormatEnum::INSTAPAY);

        $this->assertSame([ReceiptFormatEnum::INSTAPAY, '01000000001'], [$fields->format, $fields->phone]);
        $this->assertEquals(new ExtractedFields(format: ReceiptFormatEnum::UNRECOGNISED), (new ReceiptRouter)->parseAs('To Instapay', ReceiptFormatEnum::UNRECOGNISED));
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

    public function test_a_parser_without_its_format_or_currency_cannot_be_written(): void
    {
        // The router needs format() and the shared vote needs currency(): both are declared, not left to the README.
        $this->assertTrue((new \ReflectionMethod(RecognisesReceipts::class, 'format'))->isAbstract());
        $this->assertTrue((new \ReflectionMethod(VotesAcrossPasses::class, 'currency'))->isAbstract());
    }

    public function test_a_configured_parser_that_cannot_recognise_is_a_configuration_error(): void
    {
        $this->app->instance('test.blind-parser', new class implements ReceiptParser
        {
            public function parse(string $text): ExtractedFields
            {
                return new ExtractedFields(amountMinor: 1);
            }
        });
        config(['transaction-verification.parsers' => ['test.blind-parser', InstaPayReceiptParser::class]]);

        // Skipped quietly, every receipt of its format would come back unreadable with nothing saying why.
        $this->expectExceptionMessage('test.blind-parser in transaction-verification.parsers must implement RecognisesReceipts.');

        (new ReceiptRouter)->parse(self::INSTAPAY);
    }
}
