<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Analytics\SalesData;

use Magento\Framework\App\ResourceConnection;
use MagoAssistant\Mago\Api\Data\ExportColumn;
use MagoAssistant\Mago\Service\Skills\Analytics\SalesData\TopProductsAction;
use MagoAssistant\Mago\Service\Skills\Analytics\SalesData\TopRefundedAction;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #298: the download of the top products and top refunded lists holds every product of the
 * period, in the order the chat lists them, with the keys its columns read.
 */
final class TopListsExportTest extends TestCase
{
    private const PERIOD = '2001-01-07:2001-01-07';
    private const CREATED_AT = '2001-01-07 12:00:00';
    private const ADMIN_USER_ID = 1;

    private SalesOrderFixture $orders;
    private ResourceConnection $resourceConnection;

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
        $this->orders = new SalesOrderFixture($this->resourceConnection, self::CREATED_AT);
        $this->orders->begin();
    }

    protected function tearDown(): void
    {
        $this->orders->rollBack();
    }

    #[Test]
    public function itExportsTheTopProductsMostOrderedFirstWithTheSkuBreakingTies(): void
    {
        $orderId = $this->orders->processingOrder();
        $this->orderItems($orderId, [['A', 3, 30.0], ['B', 5, 10.0], ['C', 3, 9.0], ['A', 1, 10.0]]);
        $action = MagentoObjectManager::get()->get(TopProductsAction::class);

        $rows = iterator_to_array($action->exportRows(['period' => self::PERIOD], self::ADMIN_USER_ID, 10), false);

        self::assertSame(['B', 'A', 'C'], array_column($rows, 'sku'));
        self::assertSame(3, $action->countExportRows(['period' => self::PERIOD], self::ADMIN_USER_ID));
        self::assertSame(4, $rows[1]['qty_ordered']);
        self::assertSame(40.0, $rows[1]['revenue']);
        $this->assertEveryColumnIsInTheRows($action->getExportColumns(), $rows[0]);
    }

    #[Test]
    public function itStopsTheTopProductsExportAtTheRowsAskedFor(): void
    {
        $this->orderItems($this->orders->processingOrder(), [['A', 3, 30.0], ['B', 5, 10.0], ['C', 1, 9.0]]);

        $rows = iterator_to_array(
            MagentoObjectManager::get()->get(TopProductsAction::class)->exportRows(['period' => self::PERIOD], self::ADMIN_USER_ID, 2),
            false
        );

        self::assertSame(['B', 'A'], array_column($rows, 'sku'));
    }

    #[Test]
    public function itExportsTheTopRefundedProductsWithTheirCreditMemoCount(): void
    {
        $orderId = $this->orders->processingOrder();
        $this->creditmemoItems($orderId, [['A', 1, 10.0], ['B', 2, 8.0]]);
        $this->creditmemoItems($orderId, [['A', 2, 20.0]]);
        $action = MagentoObjectManager::get()->get(TopRefundedAction::class);

        $rows = iterator_to_array($action->exportRows(['period' => self::PERIOD], self::ADMIN_USER_ID, 10), false);

        self::assertSame(['A', 'B'], array_column($rows, 'sku'));
        self::assertSame(3, $rows[0]['qty_refunded']);
        self::assertSame(2, $action->countExportRows(['period' => self::PERIOD], self::ADMIN_USER_ID));
        self::assertSame(30.0, $rows[0]['refund_total']);
        self::assertSame(2, $rows[0]['creditmemo_count']);
        $this->assertEveryColumnIsInTheRows($action->getExportColumns(), $rows[0]);
    }

    /**
     * @param list<ExportColumn> $columns
     * @param array<string, scalar|null> $row
     */
    private function assertEveryColumnIsInTheRows(array $columns, array $row): void
    {
        foreach ($columns as $column) {
            self::assertArrayHasKey($column->key, $row, 'no value for the ' . $column->label . ' column');
        }
    }

    /**
     * @param list<array{0: string, 1: int, 2: float}> $items SKU, quantity, row total
     */
    private function orderItems(int $orderId, array $items): void
    {
        foreach ($items as [$sku, $qty, $rowTotal]) {
            $this->resourceConnection->getConnection()->insert($this->resourceConnection->getTableName('sales_order_item'), [
                'order_id' => $orderId,
                'sku' => $sku,
                'name' => 'Product ' . $sku,
                'qty_ordered' => $qty,
                'row_total' => $rowTotal,
                'created_at' => self::CREATED_AT,
            ]);
        }
    }

    /**
     * @param list<array{0: string, 1: int, 2: float}> $items SKU, quantity, row total
     */
    private function creditmemoItems(int $orderId, array $items): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName('sales_creditmemo'), [
            'order_id' => $orderId,
            'created_at' => self::CREATED_AT,
        ]);
        $creditmemoId = (int)$connection->lastInsertId($this->resourceConnection->getTableName('sales_creditmemo'));
        foreach ($items as [$sku, $qty, $rowTotal]) {
            $connection->insert($this->resourceConnection->getTableName('sales_creditmemo_item'), [
                'parent_id' => $creditmemoId,
                'sku' => $sku,
                'name' => 'Product ' . $sku,
                'qty' => $qty,
                'row_total' => $rowTotal,
            ]);
        }
    }
}
