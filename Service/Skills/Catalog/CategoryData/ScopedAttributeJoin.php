<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\CategoryData;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Joins the store view value of an EAV attribute with the default scope value as fallback, the way
 * Magento's own indexers read store-scoped category and product values.
 */
class ScopedAttributeJoin
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * Whether a store view has its own value for any of the attributes. Without one it shows the
     * default scope values, so a result read on the default scope holds for it too.
     *
     * @param list<string> $codes Attributes sharing one backend table
     */
    public function hasStoreValues(string $entityType, array $codes, int $storeId): bool
    {
        $attributes = array_map(fn (string $code) => $this->eavConfig->getAttribute($entityType, $code), $codes);
        $attributeIds = array_values(array_filter(array_map(
            static fn ($attribute): int => (int)$attribute->getAttributeId(),
            $attributes
        )));
        if ($attributeIds === []) {
            return false;
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($attributes[0]->getBackendTable(), [new \Zend_Db_Expr('1')])
            ->where('attribute_id IN (?)', $attributeIds)
            ->where('store_id = ?', $storeId)
            ->limit(1);

        return (bool)$connection->fetchOne($select);
    }

    /**
     * @return string SQL expression for the value, NULL when the attribute does not exist
     */
    public function join(
        Select $select,
        string $entityType,
        string $code,
        string $entityAlias,
        string $linkField,
        int $storeId
    ): string {
        $attribute = $this->eavConfig->getAttribute($entityType, $code);
        $attributeId = (int)$attribute->getAttributeId();
        if ($attributeId === 0) {
            return 'NULL';
        }

        $values = [];
        foreach (['d' => 0, 's' => $storeId] as $suffix => $scope) {
            $alias = $code . '_' . $suffix;
            $select->joinLeft(
                [$alias => $attribute->getBackendTable()],
                sprintf(
                    '%1$s.%2$s = %3$s.%2$s AND %1$s.attribute_id = %4$d AND %1$s.store_id = %5$d',
                    $alias,
                    $linkField,
                    $entityAlias,
                    $attributeId,
                    $scope
                ),
                []
            );
            $values[] = $alias . '.value';
        }

        return (string)$this->resource->getConnection()->getIfNullSql($values[1], $values[0]);
    }
}
