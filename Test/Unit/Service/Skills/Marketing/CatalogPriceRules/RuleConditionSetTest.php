<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Model\Rule\Condition\Combine;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\ProductMatches;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\RuleCondition;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\RuleConditionSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #329: the conditions as Magento saves them and as the caution card describes them. The
 * category_ids shortcut always has to hold, also when the other conditions are joined by "any".
 */
final class RuleConditionSetTest extends TestCase
{
    private RuleCondition $red;
    private RuleCondition $turret;
    private RuleCondition $category;

    protected function setUp(): void
    {
        $this->red = new RuleCondition('color', '==', '49', 'Color is Red');
        $this->turret = new RuleCondition('camera_type', '==', '7', 'Camera type is Turret');
        $this->category = new RuleCondition('category_ids', '()', '5', 'the category is one of 5');
    }

    #[Test]
    public function allConditionsAreSavedInOneGroup(): void
    {
        $array = (new RuleConditionSet('all', [$this->red, $this->turret], $this->category))->toMagentoArray();

        self::assertSame('all', $array['aggregator']);
        self::assertSame(
            ['category_ids', 'color', 'camera_type'],
            array_column($array['conditions'], 'attribute')
        );
    }

    #[Test]
    public function anyConditionsGetTheirOwnGroupNextToTheCategory(): void
    {
        $array = (new RuleConditionSet('any', [$this->red, $this->turret], $this->category))->toMagentoArray();

        self::assertSame('all', $array['aggregator']);
        self::assertSame('category_ids', $array['conditions'][0]['attribute']);
        self::assertSame(Combine::class, $array['conditions'][1]['type']);
        self::assertSame('any', $array['conditions'][1]['aggregator']);
        self::assertSame(['color', 'camera_type'], array_column($array['conditions'][1]['conditions'], 'attribute'));
    }

    #[Test]
    public function anyConditionsWithoutACategoryAreOneAnyGroup(): void
    {
        $array = (new RuleConditionSet('any', [$this->red, $this->turret]))->toMagentoArray();

        self::assertSame('any', $array['aggregator']);
        self::assertCount(2, $array['conditions']);
    }

    #[Test]
    public function itDescribesAllConditions(): void
    {
        $description = (new RuleConditionSet('all', [$this->red, $this->turret]))->describe();

        self::assertSame('Only products where all of: Color is Red; Camera type is Turret.', $description);
    }

    #[Test]
    public function itDescribesAnyConditionsWithTheCategory(): void
    {
        $description = (new RuleConditionSet('any', [$this->red, $this->turret], $this->category))->describe();

        self::assertSame(
            'Only products where the category is one of 5, and at least one of: Color is Red; Camera type is Turret.',
            $description
        );
    }

    #[Test]
    public function itDescribesASingleCondition(): void
    {
        $description = (new RuleConditionSet('any', [$this->red]))->describe();

        self::assertSame('Only products where Color is Red.', $description);
    }

    #[Test]
    public function aMatchNamesTheCountAndExamples(): void
    {
        $description = (new ProductMatches(34, ['CAM-1' => 'Turret 8MP', 'CAM-2' => '']))->describe();

        self::assertSame('Matches 34 products right now, for example CAM-1 (Turret 8MP), CAM-2.', $description);
    }

    #[Test]
    public function anEmptyMatchSaysSo(): void
    {
        $description = (new ProductMatches(0, []))->describe();

        self::assertSame('Matches no products right now.', $description);
    }
}
