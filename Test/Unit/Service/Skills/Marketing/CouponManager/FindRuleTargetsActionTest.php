<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\FindRuleTargetsAction;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionDescriber;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleTargetCatalog;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #328: names become ids through a lookup that shows every match, so an ambiguous name
 * reaches the admin as a question instead of a guess.
 */
final class FindRuleTargetsActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private FakeInternalApiClient $apiClient;

    protected function setUp(): void
    {
        $this->apiClient = RuleTargetFixture::store();
    }

    #[Test]
    public function aSharedCategoryNameComesBackAsEveryCandidateWithItsPath(): void
    {
        $this->apiClient->withResponse(FakeInternalApiClient::GET, 'categories/list', ['items' => [
            ['id' => 2, 'name' => 'Default Category', 'path' => '1/2', 'level' => 1],
            ['id' => 11, 'name' => 'Men', 'path' => '1/2/11', 'level' => 2],
            ['id' => 20, 'name' => 'Women', 'path' => '1/2/20', 'level' => 2],
            ['id' => 16, 'name' => 'Tees', 'path' => '1/2/11/16', 'level' => 3],
            ['id' => 25, 'name' => 'Tees', 'path' => '1/2/20/25', 'level' => 3],
        ]]);

        $result = $this->find(['target' => 'category', 'query' => 'Tees']);

        $tees = array_values(array_filter($result['categories'], static fn (array $c): bool => $c['name'] === 'Tees'));
        self::assertSame([
            ['id' => 16, 'name' => 'Tees', 'path' => 'Default Category > Men > Tees'],
            ['id' => 25, 'name' => 'Tees', 'path' => 'Default Category > Women > Tees'],
        ], $tees);
    }

    #[Test]
    public function itListsTheOptionsOfAnAttributeMatchingTheQuery(): void
    {
        $result = $this->find(['target' => 'attribute_option', 'attribute_code' => 'brand', 'query' => 'hik']);

        self::assertSame([['id' => RuleTargetFixture::HIKVISION, 'label' => 'Hikvision']], $result['options']);
        self::assertTrue($result['attribute']['is_used_for_promo_rules']);
    }

    #[Test]
    public function itOnlySearchesAttributesUsedForPromoRules(): void
    {
        $this->find(['target' => 'attribute', 'query' => 'brand']);

        $call = $this->apiClient->callsOf(FakeInternalApiClient::GET)[0];
        self::assertSame('products/attributes', $call['endpoint']);
        self::assertSame('is_used_for_promo_rules', $call['payload']['searchCriteria[filter_groups][0][filters][0][field]']);
    }

    #[Test]
    public function anUnknownTargetIsAnError(): void
    {
        self::assertSame(
            'target must be one of: category, attribute, attribute_option',
            $this->find(['target' => 'customer'])['error'] ?? null
        );
    }

    #[Test]
    public function aCategoryThatNoLongerExistsIsSaidOnTheCard(): void
    {
        $describer = new RuleConditionDescriber(new RuleTargetCatalog($this->apiClient));

        $line = $describer->describeActions(
            ['rules' => [['attribute' => 'category_ids', 'operator' => 'in', 'value' => [404]]]],
            self::ADMIN_USER_ID
        );

        self::assertSame('Discounts only items where category is category 404 (not found).', $line);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function find(array $params): array
    {
        return (new FindRuleTargetsAction(new RuleTargetCatalog($this->apiClient)))
            ->execute($params, self::ADMIN_USER_ID);
    }
}
