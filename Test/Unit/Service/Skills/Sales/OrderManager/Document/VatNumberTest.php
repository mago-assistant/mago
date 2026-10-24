<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager\Document;

use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document\VatNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VatNumberTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function storedNumbers(): array
    {
        return [
            'already prefixed' => ['NL123456789B01', 'NL', 'NL123456789B01'],
            'prefixed, other country on the address' => ['DE123456789', 'NL', 'DE123456789'],
            'no prefix, billing country supplies it' => ['123456789B01', 'NL', 'NL123456789B01'],
            'separators and case' => ['nl 1234.56789.b01', 'NL', 'NL123456789B01'],
            'lower-case country on the address' => ['123456789B01', 'nl', 'NL123456789B01'],
            'no prefix and no usable country' => ['123456789B01', '', '123456789B01'],
            'empty' => ['', 'NL', ''],
            'only separators' => ['- . ', 'NL', ''],
            'greek address takes the VIES prefix' => ['123456789', 'GR', 'EL123456789'],
            'greek number already prefixed EL' => ['EL123456789', 'GR', 'EL123456789'],
            'greek number typed with the ISO code' => ['GR123456789', 'GR', 'GR123456789'],
            'austrian number starts with a letter of its own' => ['U12345678', 'AT', 'ATU12345678'],
            'french letter key is not a prefix' => ['AB123456789', 'FR', 'FRAB123456789'],
            'french number with its prefix' => ['FRAB123456789', 'FR', 'FRAB123456789'],
            'non-EU number prefixed with its own country' => ['CHE-123.456.789 MWST', 'CH', 'CHE123456789MWST'],
        ];
    }

    #[Test]
    #[DataProvider('storedNumbers')]
    public function itNormalisesWhatTheShopStored(string $stored, string $country, string $expected): void
    {
        self::assertSame($expected, (new VatNumber())->withCountryPrefix($stored, $country));
    }
}
