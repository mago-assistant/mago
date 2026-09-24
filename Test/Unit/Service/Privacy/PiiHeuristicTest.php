<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PiiHeuristicTest extends TestCase
{
    private function scrub(string $text): string
    {
        return (new PiiHeuristic())->tokeniseFreeText($text, new ConversationVault());
    }

    #[Test]
    public function itTokenisesAnEmailAddress(): void
    {
        self::assertSame('mail mago://email_1 please', $this->scrub('mail jan@example.com please'));
    }

    #[Test]
    public function itTokenisesAValidIbanButLeavesAnInvalidOne(): void
    {
        // NL91ABNA0417164300 is a valid test IBAN (mod-97 == 1).
        self::assertSame('pay to mago://iban_1', $this->scrub('pay to NL91ABNA0417164300'));
        self::assertSame('ref NL00ABNA0417164300', $this->scrub('ref NL00ABNA0417164300'));
    }

    #[Test]
    public function itTokenisesAValidBsnButLeavesAnOrdinaryNineDigitNumber(): void
    {
        // 111222333 passes the 11-proef; 123456789 does not.
        self::assertSame('bsn mago://bsn_1', $this->scrub('bsn 111222333'));
        self::assertSame('order 123456789', $this->scrub('order 123456789'));
    }

    #[Test]
    public function itTokenisesADutchVatNumber(): void
    {
        self::assertSame('vat mago://vat_1', $this->scrub('vat NL123456789B01'));
    }

    #[Test]
    public function itTokenisesADutchMobileNumber(): void
    {
        self::assertSame('call mago://phone_1', $this->scrub('call 0612345678'));
        self::assertSame('call mago://phone_1', $this->scrub('call +31612345678'));
    }

    #[Test]
    public function itTokenisesInternationalAndGroupedFormats(): void
    {
        self::assertSame('call mago://phone_1', $this->scrub('call +31 6 12345678'));
        self::assertSame('call mago://phone_1', $this->scrub('call 0031612345678'));
        self::assertSame('bsn mago://bsn_1', $this->scrub('bsn 111 222 333'));
    }

    #[Test]
    public function itLeavesAnAllZeroNineDigitNumberAlone(): void
    {
        self::assertSame('code 000000000', $this->scrub('code 000000000'));
    }

    #[Test]
    public function itLeavesTextWithoutPiiUntouched(): void
    {
        $text = 'How many orders did we ship in Amsterdam last week?';
        self::assertSame($text, $this->scrub($text));
    }

    #[Test]
    public function theSameValueGetsTheSameTokenAcrossOneScrub(): void
    {
        self::assertSame(
            'from mago://email_1 to mago://email_1',
            $this->scrub('from jan@example.com to jan@example.com')
        );
    }
}
