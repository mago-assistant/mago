<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Analytics\ProductData;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\LowStockAction;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #298: the low stock download reads the real catalog in pages. Every page must add new
 * products, so the file neither repeats nor skips one, and it stops at the rows asked for.
 */
final class LowStockExportTest extends TestCase
{
    private const ADMIN_USER_ID = 1;
    private const EVERY_PRODUCT = 100000000;

    #[Test]
    public function itExportsEveryMatchingProductOnceAcrossPages(): void
    {
        $expected = $this->enabledProductCount();
        if ($expected < 3) {
            self::markTestSkipped('Needs a catalog with at least three enabled products');
        }
        $action = MagentoObjectManager::get()->get(LowStockAction::class);

        $rows = iterator_to_array($action->exportRows(['threshold' => self::EVERY_PRODUCT], self::ADMIN_USER_ID, $expected + 10), false);

        self::assertCount($expected, $rows);
        self::assertCount($expected, array_unique(array_column($rows, 'sku')));
        self::assertSame($expected, $action->countExportRows(['threshold' => self::EVERY_PRODUCT], self::ADMIN_USER_ID));
    }

    #[Test]
    public function itStopsAtTheRowsAskedForEvenMidPage(): void
    {
        if ($this->enabledProductCount() < 3) {
            self::markTestSkipped('Needs a catalog with at least three enabled products');
        }
        $action = MagentoObjectManager::get()->get(LowStockAction::class);

        $rows = iterator_to_array($action->exportRows(['threshold' => self::EVERY_PRODUCT], self::ADMIN_USER_ID, 2), false);

        self::assertCount(2, $rows);
        self::assertLessThanOrEqual($rows[1]['qty'], $rows[0]['qty']);
    }

    private function enabledProductCount(): int
    {
        $collection = MagentoObjectManager::get()->get(CollectionFactory::class)->create();
        $collection->joinField('qty', 'cataloginventory_stock_item', 'qty', 'product_id=entity_id', '{{table}}.stock_id=1', 'left')
            ->addFieldToFilter('qty', ['lt' => self::EVERY_PRODUCT])
            ->addAttributeToFilter('status', 1);

        return $collection->getSize();
    }
}
