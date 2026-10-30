<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Ui\Source;

use MagoAssistant\Mago\Service\Skills\Sales\OrderManager;
use MagoAssistant\Mago\Service\Tool\ToolCategory;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTool;
use MagoAssistant\Mago\Ui\Source\SkillCategory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #307: the Category filter offers the exact values the Category column holds
 */
final class SkillCategoryTest extends TestCase
{
    #[Test]
    public function itOffersEachCategoryOfTheRegisteredToolsWithTheCellValueAsValue(): void
    {
        $orderManager = new OrderManager(new FakeAuthorization(), []);
        $registry = new ToolRegistry(null, [$orderManager, new FakeTool('cache_manager', [], [])]);
        $toolCategory = new ToolCategory();

        $options = (new SkillCategory($registry, $toolCategory))->toOptionArray();

        self::assertSame(
            [['value' => 'Fakes', 'label' => 'Fakes'], ['value' => 'Sales', 'label' => 'Sales']],
            $options
        );
        self::assertContains($toolCategory->of($orderManager), array_column($options, 'value'));
    }
}
