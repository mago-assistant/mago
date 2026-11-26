<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\PromoAttribute;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\PromoAttributes;

/**
 * The given product attributes, found the way PromoAttributes finds them: an exact code first,
 * otherwise every attribute with that admin label, both case-insensitive.
 */
class FakePromoAttributes extends PromoAttributes
{
    /** @var PromoAttribute[] */
    private readonly array $attributes;

    public function __construct(PromoAttribute ...$attributes)
    {
        $this->attributes = $attributes;
    }

    public function findByName(string $name): array
    {
        $byCode = $this->matching(static fn (PromoAttribute $attribute): bool => strcasecmp($attribute->code, $name) === 0);

        return $byCode !== []
            ? $byCode
            : $this->matching(static fn (PromoAttribute $attribute): bool => strcasecmp($attribute->label, $name) === 0);
    }

    /**
     * @return PromoAttribute[]
     */
    private function matching(callable $isMatch): array
    {
        return array_values(array_filter($this->attributes, $isMatch));
    }
}
