<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use Magento\Catalog\Model\Indexer\Product\Price\Processor as PriceIndexProcessor;
use Magento\CatalogRule\Model\Rule;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Indexer\CacheContext;

/**
 * Recalculates the prices one catalog rule gives, right away, whatever mode the indexers run in.
 *
 * Magento's "Apply Rules" only marks the rule indexer invalid, so prices wait for cron. With the
 * indexers on "Update on Save" a rule save already reindexes that rule; on "Update by Schedule" it
 * does not, and Magento's own single-rule reindex then skips the product and price indexers too.
 * This runs those two for just the products the rule reaches now or reached before, and cleans the
 * page cache of those products, as the save does in "Update on Save" mode.
 */
class RulePriceReindexer
{
    private const RULE_PRODUCT_TABLE = 'catalogrule_product';

    public function __construct(
        private readonly CatalogRuleServices $catalogRules,
        private readonly ResourceConnection $resourceConnection,
        private readonly PriceIndexProcessor $priceIndexProcessor,
        private readonly CacheContext $cacheContext,
        private readonly EventManagerInterface $eventManager,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * Whether saving a rule already recalculates its prices ("Update on Save")
     */
    public function isAppliedOnSave(): bool
    {
        return !$this->catalogRules->getRuleProductProcessor()->isIndexerScheduled();
    }

    /**
     * What the model should offer once a rule was saved, for the save's result message
     */
    public function describeAfterSave(int $ruleId): string
    {
        return $this->isAppliedOnSave()
            ? 'Prices are already recalculated; there is no need to run apply_rules.'
            : 'Prices only change once the catalog rule indexer runs on cron; offer apply_rules with rule_id '
                . $ruleId . ' to recalculate them right away.';
    }

    /**
     * @return int[] The products the rule gives a price now, plus those it gave one at the last reindex
     */
    public function findAffectedProductIds(Rule $rule): array
    {
        $current = (bool)$rule->getIsActive() ? $this->matchingProductIds($rule) : [];

        return array_values(array_unique([...$this->indexedProductIds((int)$rule->getId()), ...$current]));
    }

    /**
     * @return int The number of products whose price was recalculated
     */
    public function reindex(Rule $rule): int
    {
        $productIds = $this->findAffectedProductIds($rule);
        if ($productIds === []) {
            return 0;
        }

        $this->catalogRules->getProductRuleProcessor()->reindexList($productIds, true);
        $this->priceIndexProcessor->reindexList($productIds, true);
        $this->cleanPageCache();

        return count($productIds);
    }

    /**
     * @return int[]
     */
    private function matchingProductIds(Rule $rule): array
    {
        return array_map('intval', array_keys(array_filter(
            $rule->getMatchingProductIds(),
            static fn (array $byWebsite): bool => in_array(true, $byWebsite, true)
        )));
    }

    /**
     * @return int[]
     */
    private function indexedProductIds(int $ruleId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from($this->resourceConnection->getTableName(self::RULE_PRODUCT_TABLE), ['product_id'])
            ->where('rule_id = ?', $ruleId);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * The price indexer registers the products it touched; this request has to clean them, as
     * Magento's own indexer cache cleaners do
     */
    private function cleanPageCache(): void
    {
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
        $identities = $this->cacheContext->getIdentities();
        if ($identities !== []) {
            $this->cache->clean($identities);
        }
        $this->cacheContext->flush();
    }
}
