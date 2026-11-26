<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionValidator;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleTargetCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #328: Magento's REST API saves any condition, so Mago checks the shape before the card and
 * what the ids point at before the save.
 */
final class RuleConditionValidatorTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function malformedConditions(): array
    {
        return [
            'not an object' => [42, 'conditions must be an object'],
            'unknown match' => [['match' => 'some', 'rules' => []], 'conditions.match must be "all" or "any"'],
            'unknown field' => [['rules' => [['field' => 'weight']]], 'Unsupported condition field "weight"'],
            'bad operator on a total' => [
                ['rules' => [['field' => 'subtotal', 'operator' => 'in', 'value' => 1]]],
                'subtotal needs an operator',
            ],
            'negative total' => [
                ['rules' => [['field' => 'subtotal', 'operator' => '>=', 'value' => -5]]],
                'subtotal needs a number of 0 or more',
            ],
            'country name' => [
                ['rules' => [['field' => 'shipping_country', 'operator' => 'in', 'value' => ['Netherlands']]]],
                'two-letter country codes',
            ],
            'empty product in cart' => [
                ['rules' => [['field' => 'product_in_cart', 'presence' => 'found', 'rules' => []]]],
                'needs at least one rule',
            ],
            'category by name' => [
                ['rules' => [['field' => 'product_in_cart', 'rules' => [
                    ['attribute' => 'category_ids', 'operator' => 'in', 'value' => ['Tees']],
                ]]]],
                'category_ids takes category ids, not names',
            ],
            'two values for is' => [
                ['rules' => [['field' => 'product_in_cart', 'rules' => [
                    ['attribute' => 'sku', 'operator' => 'is', 'value' => ['A', 'B']],
                ]]]],
                'takes exactly one value',
            ],
            'too many rules' => [
                ['rules' => array_fill(0, 21, ['field' => 'subtotal', 'operator' => '>=', 'value' => 1])],
                'at most 20 rules',
            ],
            'too many values' => [
                ['rules' => [['field' => 'shipping_country', 'operator' => 'in', 'value' => array_fill(0, 101, 'NL')]]],
                'at most 100 values',
            ],
        ];
    }

    #[Test]
    #[DataProvider('malformedConditions')]
    public function malformedConditionsAreRefused(mixed $conditions, string $expected): void
    {
        self::assertStringContainsString($expected, (string)$this->validator()->findConditionsError($conditions));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedGroups(): array
    {
        $sku = ['attribute' => 'sku', 'operator' => 'is', 'value' => 'MS04'];

        return [
            'is that is not a boolean' => [['is' => 'no', 'rules' => [$sku]], 'actions.is must be true or false'],
            'empty group' => [['rules' => [['group' => ['match' => 'all', 'rules' => []]]]], 'A group needs "rules"'],
            'group inside a group' => [
                ['rules' => [['group' => ['rules' => [['group' => ['rules' => [$sku]]]]]]]],
                'Groups do not nest',
            ],
            'two categories for is' => [
                ['rules' => [['attribute' => 'category_ids', 'operator' => 'is', 'value' => ['16', '25']]]],
                'category_ids with "is" takes exactly one id',
            ],
        ];
    }

    #[Test]
    #[DataProvider('malformedGroups')]
    public function malformedGroupsAreRefused(array $actions, string $expected): void
    {
        self::assertStringContainsString($expected, (string)$this->validator()->findActionsError($actions));
    }

    #[Test]
    public function productInCartTakesPresenceNotIs(): void
    {
        $error = $this->validator()->findConditionsError(['rules' => [
            ['field' => 'product_in_cart', 'is' => false, 'rules' => [['attribute' => 'sku', 'operator' => 'is', 'value' => 'A']]],
        ]]);

        self::assertSame('product_in_cart takes "presence" ("found" or "not_found"), not "is"', $error);
    }

    #[Test]
    public function aGroupThatHasToFailWithOneCategoryPasses(): void
    {
        self::assertNull($this->validator()->findActionsError(['match' => 'all', 'rules' => [
            ['group' => ['match' => 'all', 'is' => false, 'rules' => [
                ['attribute' => 'category_ids', 'operator' => 'is', 'value' => '6'],
                ['attribute' => 'sku', 'operator' => 'in', 'value' => ['A', 'B']],
            ]]],
        ]]));
    }

    #[Test]
    public function rulesInsideAGroupAreCheckedAgainstTheStore(): void
    {
        $error = $this->validator()->findTargetError(null, ['rules' => [
            ['group' => ['is' => false, 'rules' => [['attribute' => 'description', 'operator' => 'is', 'value' => 'x']]]],
        ]], self::ADMIN_USER_ID);

        self::assertStringContainsString('is not used for promo rules', (string)$error);
    }

    #[Test]
    public function wellFormedConditionsPass(): void
    {
        self::assertNull($this->validator()->findConditionsError([
            'match' => 'any',
            'rules' => [
                ['field' => 'subtotal_after_discount', 'operator' => '<=', 'value' => '99.95'],
                ['field' => 'shipping_country', 'operator' => 'in', 'value' => 'NL,BE'],
                ['field' => 'product_in_cart', 'presence' => 'not_found', 'match' => 'any', 'rules' => [
                    ['attribute' => 'sku', 'operator' => 'in', 'value' => ['MS04']],
                ]],
            ],
        ]));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function rulesPointingAtTheWrongThing(): array
    {
        return [
            'unknown attribute' => [
                ['attribute' => 'unknown', 'operator' => 'is', 'value' => '1'],
                'No product attribute "unknown" exists',
            ],
            'list operator on a dropdown' => [
                ['attribute' => 'brand', 'operator' => 'in', 'value' => [RuleTargetFixture::HIKVISION]],
                'brand is a single-choice attribute',
            ],
            'single operator on a multiple select' => [
                ['attribute' => 'activity', 'operator' => 'is', 'value' => '8'],
                'activity is a multiple-choice attribute',
            ],
            'unknown option' => [
                ['attribute' => 'brand', 'operator' => 'is', 'value' => '1'],
                'Attribute brand has no option with id 1',
            ],
            'text attribute' => [
                ['attribute' => 'material_note', 'operator' => 'is', 'value' => 'cotton'],
                'Attribute material_note is a text attribute',
            ],
        ];
    }

    #[Test]
    #[DataProvider('rulesPointingAtTheWrongThing')]
    public function aRulePointingAtTheWrongThingIsRefused(array $rule, string $expected): void
    {
        $error = $this->validator()->findTargetError(null, ['match' => 'all', 'rules' => [$rule]], self::ADMIN_USER_ID);

        self::assertStringContainsString($expected, (string)$error);
    }

    #[Test]
    public function anUnknownCountryIsRefused(): void
    {
        $error = $this->validator()->findTargetError(
            ['rules' => [['field' => 'shipping_country', 'operator' => 'in', 'value' => ['NL', 'XX']]]],
            null,
            self::ADMIN_USER_ID
        );

        self::assertSame('Unknown country code XX.', $error);
    }

    #[Test]
    public function productRulesInsideConditionsAreCheckedToo(): void
    {
        $error = $this->validator()->findTargetError(
            ['rules' => [['field' => 'product_in_cart', 'rules' => [
                ['attribute' => 'category_ids', 'operator' => 'in', 'value' => [RuleTargetFixture::MEN_TEES, 404]],
            ]]]],
            null,
            self::ADMIN_USER_ID
        );

        self::assertSame('No category with id 404 exists, or this admin role cannot read categories. Look categories up with find_rule_targets.', $error);
    }

    #[Test]
    public function existingTargetsPass(): void
    {
        $error = $this->validator()->findTargetError(
            ['rules' => [['field' => 'shipping_country', 'operator' => 'in', 'value' => ['nl']]]],
            ['rules' => [
                ['attribute' => 'brand', 'operator' => 'is', 'value' => RuleTargetFixture::DAHUA],
                ['attribute' => 'activity', 'operator' => 'not_in', 'value' => ['9']],
                ['attribute' => 'category_ids', 'operator' => 'in', 'value' => [RuleTargetFixture::WOMEN_TEES]],
                ['attribute' => 'sku', 'operator' => 'is', 'value' => 'anything'],
            ]],
            self::ADMIN_USER_ID
        );

        self::assertNull($error);
    }

    private function validator(): RuleConditionValidator
    {
        return new RuleConditionValidator(new RuleTargetCatalog(RuleTargetFixture::store()));
    }
}
