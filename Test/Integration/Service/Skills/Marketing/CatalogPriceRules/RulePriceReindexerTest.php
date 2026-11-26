<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Marketing\CatalogPriceRules;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Model\Rule;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\CreateRuleAction;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\RulePriceReindexer;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #329: apply_rules with a rule_id changes the indexed price right away, also when the
 * indexers run "Update by Schedule", where Magento itself leaves it to cron.
 *
 * Indexers cannot run inside a rolled-back transaction, so the rule is switched off, reindexed
 * and deleted again afterwards, which restores the product's price index.
 */
final class RulePriceReindexerTest extends TestCase
{
    private const ADMIN_USER_ID = 1;
    private const GUEST_GROUP_ID = 0;

    private ?int $ruleId = null;

    protected function tearDown(): void
    {
        if ($this->ruleId === null) {
            return;
        }

        $repository = MagentoObjectManager::get()->get(CatalogRuleRepositoryInterface::class);
        $rule = $repository->get($this->ruleId);
        $rule->setData('is_active', 0);
        $repository->save($rule);
        $this->reindexer()->reindex($this->freshRule());
        $repository->deleteById($this->ruleId);
    }

    #[Test]
    public function itChangesTheIndexedPriceOfTheRulesProductsRightAway(): void
    {
        [$productId, $sku, $price] = $this->simpleProduct();
        $created = MagentoObjectManager::get()->get(CreateRuleAction::class)->execute([
            'name' => 'Issue 329 reindex',
            'discount_type' => 'percent',
            'discount_amount' => 10,
            'website_ids' => $this->websiteIds(),
            'customer_group_ids' => [self::GUEST_GROUP_ID],
            'conditions' => [['attribute' => 'sku', 'values' => [$sku]]],
        ], self::ADMIN_USER_ID);
        $this->ruleId = $created['rule_id'] ?? null;
        self::assertNotNull($this->ruleId, (string)($created['error'] ?? ''));

        $count = $this->reindexer()->reindex($this->freshRule());

        self::assertSame(1, $count);
        self::assertEqualsWithDelta($price * 0.9, $this->indexedFinalPrice($productId), 0.01);
    }

    /**
     * @return array{0: int, 1: string, 2: float}
     */
    private function simpleProduct(): array
    {
        $collection = MagentoObjectManager::get()->get(ProductCollectionFactory::class)->create();
        $collection->addAttributeToFilter('type_id', 'simple')
            ->addAttributeToFilter('price', ['gt' => 0])
            ->addAttributeToSelect('price')
            ->addWebsiteFilter($this->websiteIds())
            ->setPageSize(1);
        $product = $collection->getFirstItem();
        if (!$product->getId()) {
            self::markTestSkipped('Needs a simple product with a price on a website.');
        }

        return [(int)$product->getId(), (string)$product->getSku(), (float)$product->getData('price')];
    }

    private function indexedFinalPrice(int $productId): float
    {
        $resource = MagentoObjectManager::get()->get(ResourceConnection::class);
        $connection = $resource->getConnection();

        return (float)$connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('catalog_product_index_price'), ['final_price'])
                ->where('entity_id = ?', $productId)
                ->where('customer_group_id = ?', self::GUEST_GROUP_ID)
                ->limit(1)
        );
    }

    private function freshRule(): Rule
    {
        $rule = MagentoObjectManager::get()->create(Rule::class);
        $rule->load($this->ruleId);

        return $rule;
    }

    private function reindexer(): RulePriceReindexer
    {
        return MagentoObjectManager::get()->get(RulePriceReindexer::class);
    }

    /**
     * @return int[]
     */
    private function websiteIds(): array
    {
        return array_map(
            static fn ($website): int => (int)$website->getId(),
            array_values(MagentoObjectManager::get()->get(StoreManagerInterface::class)->getWebsites())
        );
    }
}
