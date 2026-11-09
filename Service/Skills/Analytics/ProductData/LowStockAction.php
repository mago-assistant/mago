<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\ProductData;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\DataObject;
use MagoAssistant\Mago\Api\Data\ExportColumn;
use MagoAssistant\Mago\Api\Skill\ExportableActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\ResultLimit;

class LowStockAction implements ExportableActionInterface
{
    private const DEFAULT_THRESHOLD = 5;

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
                'description' => 'Stock threshold for low_stock action (default: ' . self::DEFAULT_THRESHOLD . ')',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results (default: 10, max: ' . ResultLimit::MAX . ')',
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
        $threshold = $this->threshold($params);
        $products = array_map(
            $this->row(...),
            array_values($this->lowStockProducts($threshold)->setPageSize(ResultLimit::fromParams($params, 10))->getItems())
        );

        return ['threshold' => $threshold, 'products' => $products];
    }

    public function getExportTitle(array $params): string
    {
        return (string)__('Full low stock list');
    }

    public function countExportRows(array $params, int $adminUserId): int
    {
        return $this->lowStockProducts($this->threshold($params))->getSize();
    }

    public function getExportColumns(): array
    {
        return [
            new ExportColumn('sku', (string)__('SKU')),
            new ExportColumn('name', (string)__('Product name')),
            new ExportColumn('qty', (string)__('Quantity'), true),
            new ExportColumn('price', (string)__('Price'), true),
        ];
    }

    public function exportRows(array $params, int $adminUserId, int $maxRows): iterable
    {
        $threshold = $this->threshold($params);
        $pageSize = max(1, min(ResultLimit::EXPORT_PAGE_SIZE, $maxRows));
        // Magento serves the last page again for a page past the end, so the count decides when to stop
        $lastPage = $this->lowStockProducts($threshold)->setPageSize($pageSize)->getLastPageNumber();
        $rowCount = 0;
        for ($page = 1; $page <= $lastPage && $rowCount < $maxRows; $page++) {
            $collection = $this->lowStockProducts($threshold)->setPageSize($pageSize)->setCurPage($page);
            foreach ($collection as $product) {
                yield $this->row($product);
                if (++$rowCount === $maxRows) {
                    return;
                }
            }
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function threshold(array $params): int
    {
        return (int)($params['threshold'] ?? self::DEFAULT_THRESHOLD);
    }

    /**
     * Enabled products below the threshold, lowest quantity first. The entity id breaks ties, so
     * the pages of an export neither repeat nor skip a product.
     */
    private function lowStockProducts(int $threshold): Collection
    {
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
            ->setOrder('entity_id', 'ASC');

        return $collection;
    }

    /**
     * @return array{sku: string, name: string, qty: int, price: float}
     */
    private function row(DataObject $product): array
    {
        return [
            'sku' => (string)$product->getData('sku'),
            'name' => (string)$product->getData('name'),
            'qty' => (int)$product->getData('qty'),
            'price' => (float)$product->getData('price'),
        ];
    }
}
