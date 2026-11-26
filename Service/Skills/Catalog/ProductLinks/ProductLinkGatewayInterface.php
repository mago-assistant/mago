<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

/**
 * Reads and writes the related, up-sell and cross-sell links of products
 */
interface ProductLinkGatewayInterface
{
    /**
     * @param list<string> $skus
     */
    public function findProducts(array $skus): CatalogProducts;

    /**
     * The links of the given type from each of the given products, per product by position
     *
     * @param list<string> $skus
     * @return list<ProductLink>
     */
    public function findLinks(array $skus, LinkType $type): array;

    /**
     * Writes every change, or none of them
     *
     * @throws LinkWriteFailedException
     */
    public function apply(LinkChangeSet $changes): void;
}
