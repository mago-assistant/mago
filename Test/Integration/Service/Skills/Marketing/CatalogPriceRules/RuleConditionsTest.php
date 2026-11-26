<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Marketing\CatalogPriceRules;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\CreateRuleAction;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\InvalidRuleConditionException;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\MatchingProducts;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\RuleConditions;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #329: catalog price rule conditions on attribute values and SKUs, resolved against the real
 * product attributes and matched the way Magento's catalog rule indexer matches them.
 *
 * Needs a catalog with two existing products and a select attribute enabled for promo rules; the
 * first one found is used, so the test does not depend on sample data.
 */
final class RuleConditionsTest extends TestCase
{
    private RuleConditions $ruleConditions;
    private MatchingProducts $matchingProducts;

    /** @var int[] */
    private array $websiteIds;

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $this->ruleConditions = $objectManager->get(RuleConditions::class);
        $this->matchingProducts = $objectManager->create(MatchingProducts::class);
        $this->websiteIds = array_map(
            static fn ($website): int => (int)$website->getId(),
            array_values($objectManager->get(StoreManagerInterface::class)->getWebsites())
        );
    }

    #[Test]
    public function itMatchesExactlyTheSkusInAList(): void
    {
        $skus = $this->twoSkus();

        $matches = $this->matchingProducts->find(
            $this->ruleConditions->build(['conditions' => [['attribute' => 'sku', 'values' => $skus]]]),
            $this->websiteIds
        );

        self::assertSame(2, $matches->count);
        self::assertEqualsCanonicalizing($skus, array_map('strval', array_keys($matches->sample)));
    }

    #[Test]
    public function itExcludesTheSkusOfAnIsNotCondition(): void
    {
        [$sku] = $this->twoSkus();

        $all = $this->matchingProducts->find($this->ruleConditions->build(['conditions' => [
            ['attribute' => 'sku', 'operator' => 'is_not', 'values' => ['no-such-sku-329']],
        ]]), $this->websiteIds);
        $withoutOne = $this->matchingProducts->find($this->ruleConditions->build(['conditions' => [
            ['attribute' => 'sku', 'operator' => 'is_not', 'values' => [$sku]],
        ]]), $this->websiteIds);

        self::assertSame($all->count - 1, $withoutOne->count);
    }

    #[Test]
    public function anyWidensWhatAllNarrows(): void
    {
        [$first, $second] = $this->twoSkus();
        $conditions = [
            ['attribute' => 'sku', 'values' => [$first]],
            ['attribute' => 'sku', 'values' => [$second]],
        ];

        $all = $this->matchingProducts->find(
            $this->ruleConditions->build(['conditions' => $conditions, 'conditions_match' => 'all']),
            $this->websiteIds
        );
        $any = $this->matchingProducts->find(
            $this->ruleConditions->build(['conditions' => $conditions, 'conditions_match' => 'any']),
            $this->websiteIds
        );

        self::assertSame(0, $all->count);
        self::assertSame(2, $any->count);
    }

    #[Test]
    public function itResolvesAnOptionByItsAdminLabel(): void
    {
        [$code, $optionId, $label] = $this->promoSelectOption();

        $conditions = $this->ruleConditions->build([
            'conditions' => [['attribute' => $code, 'values' => [strtoupper($label)]]],
        ]);

        self::assertSame($optionId, $conditions->conditions[0]->value);
        self::assertSame('==', $conditions->conditions[0]->operator);
    }

    /**
     * Magento itself ends in a PHP Error inside the product collection for an unknown code (#329)
     */
    #[Test]
    public function anUnknownAttributeIsRefusedInsteadOfFailing(): void
    {
        $this->expectException(InvalidRuleConditionException::class);
        $this->expectExceptionMessage('No product attribute has the code or label "no_such_attribute_329"');

        $this->ruleConditions->build(['conditions' => [['attribute' => 'no_such_attribute_329', 'values' => ['x']]]]);
    }

    #[Test]
    public function theCardNamesTheMatchCountAndTheRefusalCatchesAnEmptyMatch(): void
    {
        $action = MagentoObjectManager::get()->get(CreateRuleAction::class);
        $skus = $this->twoSkus();
        $params = [
            'name' => 'Issue 329',
            'discount_type' => 'percent',
            'discount_amount' => 10,
            'conditions' => [['attribute' => 'sku', 'values' => $skus]],
        ];

        $cautions = $action->getCautions($params, 1);
        $refusal = $action->findRefusal(['conditions' => [['attribute' => 'sku', 'values' => ['no-such-sku-329']]]]);

        self::assertSame('Takes 10% off the price of every matching product.', $cautions[0]);
        self::assertStringStartsWith('Matches 2 products right now, for example ', $cautions[2]);
        self::assertStringContainsString('match no products', $refusal['error'] ?? '');
        self::assertNull($action->findRefusal($params));
    }

    /**
     * @return string[] Two SKUs of products assigned to a website
     */
    private function twoSkus(): array
    {
        $matches = $this->matchingProducts->find(
            $this->ruleConditions->build(['conditions' => [
                ['attribute' => 'sku', 'operator' => 'is_not', 'values' => ['no-such-sku-329']],
            ]]),
            $this->websiteIds
        );
        if (count($matches->sample) < 2) {
            self::markTestSkipped('Needs at least two products assigned to a website.');
        }

        return array_map('strval', array_slice(array_keys($matches->sample), 0, 2));
    }

    /**
     * @return array{0: string, 1: string, 2: string} Attribute code, option id and its unique admin label
     */
    private function promoSelectOption(): array
    {
        $attributes = MagentoObjectManager::get()->get(ProductAttributeRepositoryInterface::class);
        foreach (['color', 'size', 'material', 'manufacturer'] as $code) {
            try {
                $attribute = $attributes->get($code);
            } catch (NoSuchEntityException) {
                continue;
            }
            if (!$attribute->getIsUsedForPromoRules() || $attribute->getFrontendInput() !== 'select') {
                continue;
            }
            $labels = array_column(array_filter(
                $attribute->getSource()->getAllOptions(false),
                static fn (array $option): bool => is_scalar($option['value'] ?? null) && (string)$option['value'] !== ''
            ), 'label', 'value');
            $counts = array_count_values(array_map('strtolower', array_map('strval', $labels)));
            foreach ($labels as $id => $label) {
                if ($counts[strtolower((string)$label)] === 1) {
                    return [$code, (string)$id, (string)$label];
                }
            }
        }

        self::markTestSkipped('Needs a select attribute enabled for promo rule conditions.');
    }
}
