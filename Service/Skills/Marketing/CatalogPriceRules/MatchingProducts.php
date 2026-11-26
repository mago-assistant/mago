<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\DataObject;

/**
 * The products a rule with these conditions would reach on these websites, found the way Magento's
 * catalog rule indexer finds them: Rule::getMatchingProductIds() on an unsaved rule.
 *
 * The refusal check and the caution card both ask for the same call, so the last answer is kept.
 */
class MatchingProducts
{
    public const SAMPLE_SIZE = 5;

    private ?string $lastKey = null;
    private ?ProductMatches $lastMatches = null;

    public function __construct(
        private readonly CatalogRuleServices $catalogRules,
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * @param int[] $websiteIds
     */
    public function find(RuleConditionSet $conditions, array $websiteIds): ProductMatches
    {
        $key = json_encode([$conditions->toMagentoArray(), $websiteIds], JSON_THROW_ON_ERROR);
        if ($key !== $this->lastKey || $this->lastMatches === null) {
            $this->lastMatches = $this->match($conditions, $websiteIds);
            $this->lastKey = $key;
        }

        return $this->lastMatches;
    }

    /**
     * @param int[] $websiteIds
     */
    private function match(RuleConditionSet $conditions, array $websiteIds): ProductMatches
    {
        if ($websiteIds === []) {
            return new ProductMatches(0, []);
        }

        $rule = $this->catalogRules->createRule();
        $rule->setData('website_ids', $websiteIds);
        $rule->getConditions()->loadArray($conditions->toMagentoArray());
        $productIds = array_keys(array_filter(
            $rule->getMatchingProductIds(),
            static fn (array $byWebsite): bool => in_array(true, $byWebsite, false)
        ));

        return new ProductMatches(count($productIds), $this->sample(array_slice($productIds, 0, self::SAMPLE_SIZE)));
    }

    /**
     * @param int[] $productIds
     * @return array<string, string> Product name by SKU
     */
    private function sample(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter($productIds);
        $collection->addAttributeToSelect(ProductInterface::NAME);

        return array_column(
            array_map(
                static fn (DataObject $product): array => [
                    'sku' => (string)$product->getData(ProductInterface::SKU),
                    'name' => (string)$product->getData(ProductInterface::NAME),
                ],
                array_values($collection->getItems())
            ),
            'name',
            'sku'
        );
    }
}
