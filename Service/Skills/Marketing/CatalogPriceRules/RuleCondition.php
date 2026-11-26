<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Model\Rule\Condition\Product;

/**
 * One resolved product condition: the attribute code, Magento's operator and value, and the same
 * condition in words for the caution card.
 */
class RuleCondition
{
    public function __construct(
        public readonly string $attributeCode,
        public readonly string $operator,
        public readonly string $value,
        public readonly string $description
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toMagentoArray(): array
    {
        return [
            'type' => Product::class,
            'attribute' => $this->attributeCode,
            'operator' => $this->operator,
            'value' => $this->value,
        ];
    }
}
