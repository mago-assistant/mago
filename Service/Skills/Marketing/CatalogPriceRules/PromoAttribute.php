<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

/**
 * A product attribute as a catalog rule condition sees it: its code and admin label, its input
 * type, whether "Use for Promo Rule Conditions" is on, and its options by id with their admin label.
 */
class PromoAttribute
{
    public const INPUT_SELECT = 'select';
    public const INPUT_MULTISELECT = 'multiselect';
    public const INPUT_BOOLEAN = 'boolean';

    /**
     * @param array<string, string> $options Admin label by option id
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $label,
        public readonly string $input,
        public readonly bool $isUsedForPromoRules,
        public readonly array $options = []
    ) {
    }

    public function hasOptions(): bool
    {
        return in_array($this->input, [self::INPUT_SELECT, self::INPUT_MULTISELECT], true);
    }

    public function isMultiselect(): bool
    {
        return $this->input === self::INPUT_MULTISELECT;
    }

    public function isBoolean(): bool
    {
        return $this->input === self::INPUT_BOOLEAN;
    }
}
