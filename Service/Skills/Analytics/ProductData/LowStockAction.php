<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\ProductData;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class LowStockAction implements ActionInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getName(): string
    {
        return 'low_stock';
    }

    public function getDescription(): string
    {
        return 'Products with low inventory';
    }

    public function getParameterSchema(): array
    {
        return [
            'threshold' => [
                'type' => 'integer',
                'description' => 'Stock threshold for low_stock action (default: 5)',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results (default: 10)',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'threshold' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'qty' => [PiiClass::PUBLIC],
            'price' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $threshold = (int)($params['threshold'] ?? 5);
        $limit = (int)($params['limit'] ?? 10);

        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'sku', 'price', 'status'])
            ->joinField(
                'qty',
                'cataloginventory_stock_item',
                'qty',
                'product_id=entity_id',
                '{{table}}.stock_id=1',
                'left'
            )
            ->addFieldToFilter('qty', ['lt' => $threshold])
            ->addAttributeToFilter('status', 1)
            ->setOrder('qty', 'ASC')
            ->setPageSize($limit);

        $products = [];
        foreach ($collection as $product) {
            $products[] = [
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'qty' => (int)$product->getQty(),
                'price' => (float)$product->getPrice(),
            ];
        }

        return ['threshold' => $threshold, 'products' => $products];
    }
}
