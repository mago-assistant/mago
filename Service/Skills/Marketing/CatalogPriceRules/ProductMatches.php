<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

/**
 * How many products a rule's conditions match right now, with a few of them by SKU and name
 */
class ProductMatches
{
    /**
     * @param array<string, string> $sample Product name by SKU
     */
    public function __construct(
        public readonly int $count,
        public readonly array $sample
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    public function describe(): string
    {
        if ($this->isEmpty()) {
            return 'Matches no products right now.';
        }

        $examples = implode(', ', array_map(
            static fn (string $sku, string $name): string => $name === '' ? $sku : $sku . ' (' . $name . ')',
            array_keys($this->sample),
            $this->sample
        ));
        $count = $this->count === 1 ? 'Matches 1 product right now' : 'Matches ' . $this->count . ' products right now';

        return $examples === '' ? $count . '.' : $count . ', for example ' . $examples . '.';
    }
}
