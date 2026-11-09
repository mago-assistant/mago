<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Tool;

use MagoAssistant\Mago\Service\Skills\Sales\OrderManager;
use MagoAssistant\Mago\Service\Tool\ToolCategory;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #307: the Category column and its filter options come from the same rule
 */
final class ToolCategoryTest extends TestCase
{
    #[Test]
    public function itTakesTheCategoryFromTheNamespaceAfterServiceSkills(): void
    {
        $category = (new ToolCategory())->of(new OrderManager(new FakeAuthorization(), []));

        self::assertSame('Sales', $category);
    }

    #[Test]
    public function itListsEachCategoryOnceInAlphabeticalOrder(): void
    {
        $authorization = new FakeAuthorization();
        $tools = [
            new OrderManager($authorization, []),
            new FakeTool('cache_manager', [], []),
            new OrderManager($authorization, []),
        ];

        $categories = (new ToolCategory())->allOf($tools);

        self::assertSame(['Fakes', 'Sales'], $categories);
    }
}
