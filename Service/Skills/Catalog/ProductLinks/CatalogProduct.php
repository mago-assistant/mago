<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

final readonly class CatalogProduct
{
    public function __construct(
        public int $id,
        public string $sku,
        public string $name,
        public bool $isEnabled
    ) {
    }

    public function label(): string
    {
        return $this->name === '' ? $this->sku : $this->sku . ' (' . $this->name . ')';
    }
}
