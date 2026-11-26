<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionDescriber;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleTargetCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #328: the card says what Magento will do. Rules that have to fail follow Magento's
 * Combine::_isValid: "all" + false means none holds, "any" + false means at least one fails.
 */
final class RuleConditionDescriberTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const MEN_TEES = ['attribute' => 'category_ids', 'operator' => 'is', 'value' => '16'];
    private const HIKVISION = ['attribute' => 'brand', 'operator' => 'is', 'value' => RuleTargetFixture::HIKVISION];
    private const MEN_TEES_WORDS = 'category is Default Category > Men > Tees (16)';
    private const HIKVISION_WORDS = 'Brand is Hikvision';

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function actionTrees(): array
    {
        return [
            'all hold' => [
                ['match' => 'all', 'rules' => [self::MEN_TEES, self::HIKVISION]],
                self::MEN_TEES_WORDS . ' AND ' . self::HIKVISION_WORDS,
            ],
            'any holds' => [
                ['match' => 'any', 'rules' => [self::MEN_TEES, self::HIKVISION]],
                self::MEN_TEES_WORDS . ' OR ' . self::HIKVISION_WORDS,
            ],
            'all fail' => [
                ['match' => 'all', 'is' => false, 'rules' => [self::MEN_TEES, self::HIKVISION]],
                'NOT (' . self::MEN_TEES_WORDS . ' OR ' . self::HIKVISION_WORDS . ')',
            ],
            'any fails' => [
                ['match' => 'any', 'is' => false, 'rules' => [self::MEN_TEES, self::HIKVISION]],
                'NOT (' . self::MEN_TEES_WORDS . ' AND ' . self::HIKVISION_WORDS . ')',
            ],
            'one rule that has to fail' => [
                ['match' => 'all', 'is' => false, 'rules' => [self::HIKVISION]],
                'NOT ' . self::HIKVISION_WORDS,
            ],
            'sample rule 3 shape: a group where all have to fail' => [
                ['match' => 'all', 'rules' => [
                    ['group' => ['match' => 'all', 'is' => false, 'rules' => [self::MEN_TEES, self::HIKVISION]]],
                ]],
                'NOT (' . self::MEN_TEES_WORDS . ' OR ' . self::HIKVISION_WORDS . ')',
            ],
            'a group that has to hold, next to a rule' => [
                ['match' => 'all', 'rules' => [
                    ['attribute' => 'sku', 'operator' => 'is_not', 'value' => 'MS04'],
                    ['group' => ['match' => 'any', 'rules' => [self::MEN_TEES, self::HIKVISION]]],
                ]],
                'SKU is not MS04 AND (' . self::MEN_TEES_WORDS . ' OR ' . self::HIKVISION_WORDS . ')',
            ],
        ];
    }

    #[Test]
    #[DataProvider('actionTrees')]
    public function actionsAreSaidTheWayMagentoAppliesThem(array $actions, string $expected): void
    {
        self::assertSame(
            'Discounts only items where ' . $expected . '.',
            $this->describer()->describeActions($actions, self::ADMIN_USER_ID)
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function conditionTrees(): array
    {
        $subtotal = ['field' => 'subtotal', 'operator' => '>=', 'value' => 100];
        $quantity = ['field' => 'total_qty', 'operator' => '<', 'value' => 3];
        $both = 'the subtotal (base currency) is at least 100; the total item quantity is less than 3.';

        return [
            'all hold' => [['match' => 'all', 'rules' => [$subtotal, $quantity]], 'Applies when ALL of these hold: ' . $both],
            'none holds' => [
                ['match' => 'all', 'is' => false, 'rules' => [$subtotal, $quantity]],
                'Applies when NONE of these hold: ' . $both,
            ],
            'one fails' => [
                ['match' => 'any', 'is' => false, 'rules' => [$subtotal, $quantity]],
                'Applies when at least one of these does NOT hold: ' . $both,
            ],
            'single rule that has to fail' => [
                ['match' => 'all', 'is' => false, 'rules' => [$subtotal]],
                'Applies unless the subtotal (base currency) is at least 100.',
            ],
            'no item of a category in the cart' => [
                ['match' => 'all', 'rules' => [
                    ['field' => 'product_in_cart', 'presence' => 'not_found', 'match' => 'all', 'rules' => [self::MEN_TEES]],
                ]],
                'Applies when the cart has no item where ' . self::MEN_TEES_WORDS . '.',
            ],
            'shipping country' => [
                ['match' => 'all', 'rules' => [['field' => 'shipping_country', 'operator' => 'not_in', 'value' => ['NL', 'BE']]]],
                'Applies when the shipping country is not one of Netherlands (NL), Belgium (BE).',
            ],
        ];
    }

    #[Test]
    #[DataProvider('conditionTrees')]
    public function conditionsAreSaidTheWayMagentoAppliesThem(array $conditions, string $expected): void
    {
        self::assertSame($expected, $this->describer()->describeConditions($conditions, self::ADMIN_USER_ID));
    }

    private function describer(): RuleConditionDescriber
    {
        return new RuleConditionDescriber(new RuleTargetCatalog(RuleTargetFixture::store()));
    }
}
