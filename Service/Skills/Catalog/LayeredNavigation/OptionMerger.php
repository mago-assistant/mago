<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation;

use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Moves every product from some options of an attribute to one other option, then deletes the
 * emptied options
 *
 * The values are rewritten in the EAV table in one transaction, in every store view, so a merge of
 * thousands of products is not thousands of product saves. That bypasses the save-time indexers, so
 * the indexers that read the values are marked for a rebuild and the products' cache is cleaned.
 */
class OptionMerger
{
    /** Indexers that hold attribute values: layered navigation and search */
    public const INDEXERS = ['catalog_product_attribute', 'catalogsearch_fulltext'];

    public function __construct(
        private readonly FilterAttributes $filterAttributes,
        private readonly ResourceConnection $resource,
        private readonly AttributeOptionManagementInterface $optionManagement,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * @param array $attribute From FilterAttributes::attributes(), an option input
     * @param int $targetId Option the products keep
     * @param int[] $sourceIds Options the products move away from; deleted afterwards
     * @return int[] Entity ids of the products that changed
     * @throws \Exception When the rewrite fails; nothing is changed then
     */
    public function merge(array $attribute, int $targetId, array $sourceIds): array
    {
        $productIds = array_keys(array_replace(...array_values(
            $this->filterAttributes->productsByOption($attribute, $sourceIds) ?: [[]]
        )));

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_product_entity_' . $attribute['backend_type']);
        $connection->beginTransaction();
        try {
            if ($attribute['input'] === 'multiselect') {
                $this->rewriteLists($table, $attribute['attribute_id'], $targetId, $sourceIds);
            } else {
                $connection->update(
                    $table,
                    ['value' => $targetId],
                    ['attribute_id = ?' => $attribute['attribute_id'], 'value IN (?)' => $sourceIds]
                );
            }
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }

        foreach ($sourceIds as $sourceId) {
            $this->optionManagement->delete(FilterAttributes::PRODUCT_ENTITY_TYPE, $attribute['code'], $sourceId);
        }
        foreach (self::INDEXERS as $indexerId) {
            $this->indexerRegistry->get($indexerId)->invalidate();
        }
        if ($productIds !== []) {
            $this->cacheContext->registerEntities(Product::CACHE_TAG, $productIds);
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
        }

        return $productIds;
    }

    /**
     * A multiselect stores "12,15,18": swap the source ids for the target, without repeating it
     *
     * @param string $table
     * @param int $attributeId
     * @param int $targetId
     * @param int[] $sourceIds
     * @return void
     */
    private function rewriteLists(string $table, int $attributeId, int $targetId, array $sourceIds): void
    {
        $connection = $this->resource->getConnection();
        $matches = array_map(
            static fn (int $sourceId) => $connection->quoteInto('FIND_IN_SET(?, value)', (string)$sourceId),
            $sourceIds
        );
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['value_id', 'value'])
                ->where('attribute_id = ?', $attributeId)
                ->where(implode(' OR ', $matches))
        );
        $sources = array_map('strval', $sourceIds);
        foreach ($rows as $valueId => $value) {
            $ids = array_map(
                static fn (string $id) => in_array($id, $sources, true) ? (string)$targetId : $id,
                array_map('trim', explode(',', (string)$value))
            );
            $connection->update(
                $table,
                ['value' => implode(',', array_values(array_unique($ids)))],
                ['value_id = ?' => (int)$valueId]
            );
        }
    }
}
