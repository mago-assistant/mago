<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document;

/**
 * The VAT number as stored on a billing address, made usable by whoever reads it off the order.
 *
 * Shops store it as the customer typed it: with or without the country prefix, with spaces and
 * dots, in any case. A register lookup needs the two-letter member state in front, and the billing
 * address knows which state that is - so every consumer gets the same normalised number from the
 * order document rather than each repairing it on its own.
 *
 * Two leading letters are only taken as the prefix when they are a code a VAT number starts with:
 * a member state, or the billing country. A French number keeps letters after its prefix
 * (FRAB123456789), so "AB123456789" on a French address is not mistaken for one that has its own.
 */
class VatNumber
{
    /**
     * The prefixes VIES uses. Greece is EL there but GR in ISO 3166 and on a Magento address, and
     * XI is Northern Ireland.
     */
    private const MEMBER_STATES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ];

    private const ISO_TO_VAT_PREFIX = ['GR' => 'EL'];

    /**
     * @param string $vatId   The stored value, possibly empty
     * @param string $country The billing address's ISO country code, possibly empty
     * @return string Upper-cased, without separators, prefixed with the billing country's VAT
     *                prefix unless it already starts with a member state or that country;
     *                '' when there is no number
     */
    public function withCountryPrefix(string $vatId, string $country): string
    {
        $number = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $vatId));
        if ($number === '') {
            return '';
        }

        $country = strtoupper(trim($country));
        if (strlen($country) !== 2 || $this->hasPrefix($number, $country)) {
            return $number;
        }

        return (self::ISO_TO_VAT_PREFIX[$country] ?? $country) . $number;
    }

    private function hasPrefix(string $number, string $country): bool
    {
        $lead = substr($number, 0, 2);

        return in_array($lead, self::MEMBER_STATES, true)
            || $lead === $country
            || $lead === (self::ISO_TO_VAT_PREFIX[$country] ?? null);
    }
}
