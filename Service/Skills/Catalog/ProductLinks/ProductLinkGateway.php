<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductLinkRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\LinkTypeProvider;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Throwable;

/**
 * Reads links with one query per call and writes them one at a time through the link repository,
 * which saves the link without saving the product. A product save per target would be far heavier
 * for a bulk change and would run every product save observer. Because the product is not saved,
 * nothing else clears its pages from the full page cache, so this does.
 */
class ProductLinkGateway implements ProductLinkGatewayInterface
{
    private const LINK_TABLE = 'catalog_product_link';
    private const LINK_ATTRIBUTE_TABLE = 'catalog_product_link_attribute';
    private const LINK_POSITION_TABLE = 'catalog_product_link_attribute_int';
    private const PRODUCT_TABLE = 'catalog_product_entity';
    private const POSITION_ATTRIBUTE = 'position';
    private const ADMIN_STORE_ID = 0;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly ProductLinkRepositoryInterface $linkRepository,
        private readonly ProductLinkInterfaceFactory $linkFactory,
        private readonly LinkTypeProvider $linkTypeProvider,
        private readonly MetadataPool $metadataPool,
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager
    ) {
    }

    public function findProducts(array $skus): CatalogProducts
    {
        if ($skus === []) {
            return new CatalogProducts([]);
        }

        $collection = $this->productCollectionFactory->create()
            ->setStoreId(self::ADMIN_STORE_ID)
            ->addAttributeToSelect(['name', 'status'])
            ->addFieldToFilter('sku', ['in' => array_values(array_unique($skus))]);

        return new CatalogProducts(array_values(array_map(
            static fn (DataObject $product): CatalogProduct => new CatalogProduct(
                (int)$product->getData('entity_id'),
                (string)$product->getData('sku'),
                (string)$product->getData('name'),
                (int)$product->getData('status') === Status::STATUS_ENABLED
            ),
            $collection->getItems()
        )));
    }

    public function findLinks(array $skus, LinkType $type): array
    {
        if ($skus === []) {
            return [];
        }

        $skusByRow = $this->currentRowSkus($skus);
        if ($skusByRow === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from(['link' => $this->resource->getTableName(self::LINK_TABLE)], ['link_id', 'product_id'])
            ->join(
                ['linked' => $this->resource->getTableName(self::PRODUCT_TABLE)],
                'linked.entity_id = link.linked_product_id',
                ['linked_sku' => 'linked.sku']
            )
            ->joinLeft(
                ['attribute' => $this->resource->getTableName(self::LINK_ATTRIBUTE_TABLE)],
                $connection->quoteInto(
                    'attribute.link_type_id = link.link_type_id AND attribute.product_link_attribute_code = ?',
                    self::POSITION_ATTRIBUTE
                ),
                []
            )
            ->joinLeft(
                ['position' => $this->resource->getTableName(self::LINK_POSITION_TABLE)],
                'position.link_id = link.link_id'
                    . ' AND position.product_link_attribute_id = attribute.product_link_attribute_id',
                ['position' => 'position.value']
            )
            ->where('link.product_id IN (?)', array_keys($skusByRow))
            ->where('link.link_type_id = ?', $this->linkTypeId($type))
            ->order(['link.product_id ASC', 'position.value ASC', 'link.link_id ASC']);

        return array_map(
            static fn (array $row): ProductLink => new ProductLink(
                $skusByRow[(int)$row['product_id']],
                $type,
                (string)$row['linked_sku'],
                (int)$row['position']
            ),
            $connection->fetchAll($select)
        );
    }

    /**
     * SKU per link-field value of the current version of each product. On a store with content
     * staging a product has a row per scheduled version, each with its own copy of the links, and
     * the link repository only writes the current one; the product collection filters to it.
     *
     * @param list<string> $skus
     * @return array<int, string>
     */
    private function currentRowSkus(array $skus): array
    {
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $collection = $this->productCollectionFactory->create()
            ->addFieldToFilter('sku', ['in' => array_values(array_unique($skus))]);

        return array_reduce(
            $collection->getItems(),
            static fn (array $skusByRow, DataObject $product): array => $skusByRow
                + [(int)$product->getData($linkField) => (string)$product->getData('sku')],
            []
        );
    }

    public function apply(LinkChangeSet $changes): void
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            array_map(fn (ProductLink $link): bool => $this->linkRepository->save($this->toEntity($link)), $changes->saves);
            array_map(fn (ProductLink $link): bool => $this->linkRepository->delete($this->toEntity($link)), $changes->deletes);
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw new LinkWriteFailedException(
                'The product links could not be saved, nothing was changed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $this->cleanProductPages($changes->changedProductSkus());
    }

    /**
     * @param list<string> $skus
     */
    private function cleanProductPages(array $skus): void
    {
        $this->cacheContext->registerEntities(Product::CACHE_TAG, $this->productIds($skus));
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
    }

    /**
     * @param list<string> $skus
     * @return list<int>
     */
    private function productIds(array $skus): array
    {
        $products = $this->findProducts($skus);

        return array_values(array_filter(array_map(
            static fn (string $sku): ?int => $products->find($sku)?->id,
            $skus
        )));
    }

    private function toEntity(ProductLink $link): ProductLinkInterface
    {
        return $this->linkFactory->create()
            ->setSku($link->productSku)
            ->setLinkType($link->type->value)
            ->setLinkedProductSku($link->linkedSku)
            ->setPosition($link->position);
    }

    private function linkTypeId(LinkType $type): int
    {
        return (int)($this->linkTypeProvider->getLinkTypes()[$type->value] ?? 0);
    }
}
