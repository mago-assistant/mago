<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

/**
 * Products found by SKU. Magento matches SKUs case-insensitively, so lookups here do too.
 */
final readonly class CatalogProducts
{
    /** @var array<string, CatalogProduct> */
    private array $bySku;

    /**
     * @param list<CatalogProduct> $products
     */
    public function __construct(array $products)
    {
        $this->bySku = array_combine(
            array_map(static fn (CatalogProduct $product): string => self::key($product->sku), $products),
            $products
        );
    }

    public function find(string $sku): ?CatalogProduct
    {
        return $this->bySku[self::key($sku)] ?? null;
    }

    /**
     * @param list<string> $skus
     * @return list<string>
     */
    public function missingFrom(array $skus): array
    {
        return array_values(array_filter($skus, fn (string $sku): bool => $this->find($sku) === null));
    }

    public function labelOf(string $sku): string
    {
        return $this->find($sku)?->label() ?? $sku;
    }

    public static function key(string $sku): string
    {
        return mb_strtolower(trim($sku));
    }
}
