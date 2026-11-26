<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\ProductData;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as CatalogAttribute;
use Magento\Framework\App\ResourceConnection;

/**
 * The product attributes an attribute fill rate is computed for, and which attribute sets hold them.
 *
 * Static attributes live on catalog_product_entity itself and the left out ones are not stored as one
 * value per product in the value tables, so no fill rate exists for them.
 *
 * @phpstan-type AttributeInfo array{
 *     attribute_id:int,
 *     attribute_code:string,
 *     label:string,
 *     input_type:string,
 *     backend_type:string,
 *     value_scope:string,
 *     is_required:bool,
 *     is_custom:bool,
 *     apply_to:list<string>
 * }
 */
class AttributeFillRateAttributes
{
    public const BACKEND_TYPES = ['varchar', 'int', 'decimal', 'text', 'datetime'];
    public const LEFT_OUT = ['tier_price', 'quantity_and_stock_status', 'gallery'];

    private const VALUE_SCOPES = [
        CatalogAttribute::SCOPE_STORE => 'store_view',
        CatalogAttribute::SCOPE_GLOBAL => 'global',
        CatalogAttribute::SCOPE_WEBSITE => 'website',
    ];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array<int,AttributeInfo> Attribute id => attribute
     */
    public function attributes(bool $includeSystem): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['ea' => $this->resource->getTableName('eav_attribute')], [
                'attribute_id',
                'attribute_code',
                'frontend_label',
                'frontend_input',
                'backend_type',
                'is_required',
                'is_user_defined',
            ])
            ->join(
                ['cea' => $this->resource->getTableName('catalog_eav_attribute')],
                'cea.attribute_id = ea.attribute_id',
                ['is_global', 'apply_to']
            )
            ->join(
                ['et' => $this->resource->getTableName('eav_entity_type')],
                'et.entity_type_id = ea.entity_type_id',
                []
            )
            ->where('et.entity_type_code = ?', Product::ENTITY)
            ->where('ea.backend_type IN (?)', self::BACKEND_TYPES)
            ->where("ea.backend_table IS NULL OR ea.backend_table = ''")
            ->where('ea.attribute_code NOT IN (?)', self::LEFT_OUT);
        if (!$includeSystem) {
            $select->where('ea.is_user_defined = 1');
        }

        $attributes = [];
        foreach ($connection->fetchAll($select) as $row) {
            $attributes[(int)$row['attribute_id']] = $this->attribute($row);
        }

        return $attributes;
    }

    /**
     * @return array<int,list<int>> Attribute set id => the attribute ids it holds
     */
    public function attributeIdsPerSet(): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['eea' => $this->resource->getTableName('eav_entity_attribute')], ['attribute_set_id', 'attribute_id'])
            ->join(
                ['et' => $this->resource->getTableName('eav_entity_type')],
                'et.entity_type_id = eea.entity_type_id',
                []
            )
            ->where('et.entity_type_code = ?', Product::ENTITY);

        $perSet = [];
        foreach ($connection->fetchAll($select) as $row) {
            $perSet[(int)$row['attribute_set_id']][] = (int)$row['attribute_id'];
        }

        return $perSet;
    }

    /**
     * @return array<int,string> Attribute set id => name
     */
    public function attributeSets(): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['eas' => $this->resource->getTableName('eav_attribute_set')], ['attribute_set_id', 'attribute_set_name'])
            ->join(
                ['et' => $this->resource->getTableName('eav_entity_type')],
                'et.entity_type_id = eas.entity_type_id',
                []
            )
            ->where('et.entity_type_code = ?', Product::ENTITY)
            ->order('eas.attribute_set_name ASC');

        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * @param array<string,mixed> $row
     * @return AttributeInfo
     */
    private function attribute(array $row): array
    {
        return [
            'attribute_id' => (int)$row['attribute_id'],
            'attribute_code' => (string)$row['attribute_code'],
            'label' => (string)($row['frontend_label'] ?? ''),
            'input_type' => (string)($row['frontend_input'] ?? ''),
            'backend_type' => (string)$row['backend_type'],
            'value_scope' => self::VALUE_SCOPES[(int)$row['is_global']] ?? 'global',
            'is_required' => (bool)$row['is_required'],
            'is_custom' => (bool)$row['is_user_defined'],
            'apply_to' => array_values(array_filter(
                explode(',', (string)($row['apply_to'] ?? '')),
                static fn (string $type): bool => $type !== ''
            )),
        ];
    }
}
