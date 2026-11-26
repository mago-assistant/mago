<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Analytics\ProductData;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\AttributeFillRateReport;
use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\AttributeFillRateScope;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #319: the attribute fill rate report reads the real EAV tables. Read only: nothing is written.
 */
final class AttributeFillRateReportTest extends TestCase
{
    #[Test]
    public function everyProductHasANameOnTheDefaultScope(): void
    {
        $report = $this->report()->build(new AttributeFillRateScope(isIncludingSystem: true));
        if ($report['product_count'] === 0) {
            self::markTestSkipped('Needs a catalog with products');
        }

        $name = $this->row($report['attributes'], 'name');

        self::assertSame($report['product_count'], $name['applicable_count']);
        self::assertSame($name['applicable_count'], $name['filled_count']);
    }

    #[Test]
    public function theFilledCountMatchesAnIndependentCountOfTheValueTable(): void
    {
        $report = $this->report()->build(new AttributeFillRateScope(isIncludingSystem: true));
        $description = $this->row($report['attributes'], 'description');

        self::assertSame($this->filledDescriptions(), $description['filled_count']);
    }

    #[Test]
    public function aStoreViewCountsNoMoreProductsThanTheWholeCatalog(): void
    {
        $store = MagentoObjectManager::get()->get(StoreManagerInterface::class)->getDefaultStoreView();
        if ($store === null) {
            self::markTestSkipped('Needs a store view');
        }
        $report = $this->report();
        $catalog = $report->build(new AttributeFillRateScope());

        $storeView = $report->build(new AttributeFillRateScope((int)$store->getId(), (int)$store->getWebsiteId()));

        self::assertLessThanOrEqual($catalog['product_count'], $storeView['product_count']);
        foreach ($storeView['attributes'] as $row) {
            self::assertLessThanOrEqual($row['applicable_count'], $row['filled_count'], $row['attribute_code']);
        }
    }

    private function report(): AttributeFillRateReport
    {
        return MagentoObjectManager::get()->get(AttributeFillRateReport::class);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function row(array $rows, string $code): array
    {
        $matching = array_values(array_filter($rows, static fn (array $row): bool => $row['attribute_code'] === $code));
        self::assertCount(1, $matching, $code . ' is reported once');

        return $matching[0];
    }

    private function filledDescriptions(): int
    {
        $resource = MagentoObjectManager::get()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $linkField = MagentoObjectManager::get()->get(MetadataPool::class)->getMetadata(ProductInterface::class)->getLinkField();
        $select = $connection->select()
            ->from(['v' => $resource->getTableName('catalog_product_entity_text')], [new \Zend_Db_Expr('COUNT(DISTINCT p.entity_id)')])
            ->join(['p' => $resource->getTableName('catalog_product_entity')], sprintf('p.%1$s = v.%1$s', $linkField), [])
            ->join(['a' => $resource->getTableName('eav_attribute')], 'a.attribute_id = v.attribute_id', [])
            ->join(['t' => $resource->getTableName('eav_entity_type')], 't.entity_type_id = a.entity_type_id', [])
            ->join(
                ['s' => $resource->getTableName('eav_entity_attribute')],
                's.attribute_set_id = p.attribute_set_id AND s.attribute_id = a.attribute_id',
                []
            )
            ->where('t.entity_type_code = ?', 'catalog_product')
            ->where('a.attribute_code = ?', 'description')
            ->where('v.store_id = 0')
            ->where("TRIM(v.value) <> ''");

        return (int)$connection->fetchOne($select);
    }
}
