<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\CatalogProduct;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\CatalogProducts;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkChangeSet;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkType;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkWriteFailedException;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\ProductLink;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\ProductLinkGatewayInterface;

/**
 * An in-memory catalog of products and their links. apply() changes the links the way the real
 * gateway does and records every change set it was given.
 */
final class FakeProductLinkGateway implements ProductLinkGatewayInterface
{
    /** @var list<CatalogProduct> */
    private array $products = [];

    /** @var array<string, ProductLink> by pair key */
    private array $links = [];

    /** @var list<LinkChangeSet> */
    private array $applied = [];

    private bool $isFailingWrites = false;

    public function withProduct(string $sku, string $name = '', bool $isEnabled = true): self
    {
        $this->products[] = new CatalogProduct(count($this->products) + 1, $sku, $name, $isEnabled);

        return $this;
    }

    /**
     * @param string ...$skus
     */
    public function withProducts(string ...$skus): self
    {
        array_map(fn (string $sku): self => $this->withProduct($sku, 'Name of ' . $sku), $skus);

        return $this;
    }

    public function withLink(string $productSku, LinkType $type, string $linkedSku, int $position): self
    {
        $link = new ProductLink($productSku, $type, $linkedSku, $position);
        $this->links[$link->pairKey()] = $link;

        return $this;
    }

    public function givenWritesFail(): self
    {
        $this->isFailingWrites = true;

        return $this;
    }

    public function findProducts(array $skus): CatalogProducts
    {
        $keys = array_map(CatalogProducts::key(...), $skus);

        return new CatalogProducts(array_values(array_filter(
            $this->products,
            static fn (CatalogProduct $product): bool => in_array(CatalogProducts::key($product->sku), $keys, true)
        )));
    }

    public function findLinks(array $skus, LinkType $type): array
    {
        $keys = array_map(CatalogProducts::key(...), $skus);
        $links = array_values(array_filter(
            $this->links,
            static fn (ProductLink $link): bool => $link->type === $type
                && in_array(CatalogProducts::key($link->productSku), $keys, true)
        ));
        usort(
            $links,
            static fn (ProductLink $a, ProductLink $b): int => [$a->productSku, $a->position] <=> [$b->productSku, $b->position]
        );

        return $links;
    }

    public function apply(LinkChangeSet $changes): void
    {
        if ($this->isFailingWrites) {
            throw new LinkWriteFailedException('The product links could not be saved, nothing was changed: boom');
        }

        $this->applied[] = $changes;
        foreach ($changes->saves as $link) {
            $this->links[$link->pairKey()] = $link;
        }
        foreach ($changes->deletes as $link) {
            unset($this->links[$link->pairKey()]);
        }
    }

    /**
     * Every link now, as "PRODUCT -type-> LINKED @position", sorted
     *
     * @return list<string>
     */
    public function linkSummary(): array
    {
        $summary = array_map(
            static fn (ProductLink $link): string => $link->productSku . ' -' . $link->type->value . '-> '
                . $link->linkedSku . ' @' . $link->position,
            array_values($this->links)
        );
        sort($summary);

        return $summary;
    }

    /**
     * @return list<LinkChangeSet>
     */
    public function appliedChanges(): array
    {
        return $this->applied;
    }
}
