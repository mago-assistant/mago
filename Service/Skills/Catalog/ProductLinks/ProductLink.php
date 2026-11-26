<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

final readonly class ProductLink
{
    public function __construct(
        public string $productSku,
        public LinkType $type,
        public string $linkedSku,
        public int $position
    ) {
    }

    public function withPosition(int $position): self
    {
        return new self($this->productSku, $this->type, $this->linkedSku, $position);
    }

    /**
     * The same link in the other direction, without a position yet
     */
    public function reversed(): self
    {
        return new self($this->linkedSku, $this->type, $this->productSku, 0);
    }

    public function isReverseOf(self $link): bool
    {
        return $this->type === $link->type && $this->isFrom($link->linkedSku) && $this->pointsAt($link->productSku);
    }

    public function pairKey(): string
    {
        return CatalogProducts::key($this->productSku) . '|' . $this->type->value . '|'
            . CatalogProducts::key($this->linkedSku);
    }

    public function isFrom(string $sku): bool
    {
        return CatalogProducts::key($this->productSku) === CatalogProducts::key($sku);
    }

    public function pointsAt(string $sku): bool
    {
        return CatalogProducts::key($this->linkedSku) === CatalogProducts::key($sku);
    }
}
