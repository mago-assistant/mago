<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

/**
 * The writes one call makes, worked out once from the current links so the confirmation card and
 * the save describe the same thing. "skipped" holds what was asked for but needs no write: a link
 * that already exists when adding, or one that does not exist when removing.
 */
final readonly class LinkChangeSet
{
    /**
     * @param list<ProductLink> $saves
     * @param list<ProductLink> $deletes
     * @param list<ProductLink> $skipped
     */
    public function __construct(
        public LinkType $type,
        public array $saves = [],
        public array $deletes = [],
        public array $skipped = []
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->saves === [] && $this->deletes === [];
    }

    public function changeCount(): int
    {
        return count($this->saves) + count($this->deletes);
    }

    /**
     * SKUs of the products whose links change, in the order they first appear
     *
     * @return list<string>
     */
    public function changedProductSkus(): array
    {
        return self::uniqueProductSkus([...$this->saves, ...$this->deletes]);
    }

    /**
     * SKUs of every product the call changes or skips, in the order they first appear
     *
     * @return list<string>
     */
    public function productSkus(): array
    {
        return self::uniqueProductSkus([...$this->saves, ...$this->deletes, ...$this->skipped]);
    }

    /**
     * The linked SKUs of the links that start at the given product
     *
     * @param list<ProductLink> $links
     * @return list<string>
     */
    public static function linkedSkusFrom(string $productSku, array $links): array
    {
        return array_values(array_map(
            static fn (ProductLink $link): string => $link->linkedSku,
            array_filter($links, static fn (ProductLink $link): bool => $link->isFrom($productSku))
        ));
    }

    /**
     * @param list<ProductLink> $links
     * @return list<string>
     */
    private static function uniqueProductSkus(array $links): array
    {
        return array_values(array_reduce(
            $links,
            static fn (array $skus, ProductLink $link): array => $skus
                + [CatalogProducts::key($link->productSku) => $link->productSku],
            []
        ));
    }
}
