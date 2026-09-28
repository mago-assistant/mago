<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Welcome;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Service\Skills\PermissionChecker;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Service\Welcome\ExampleQuestions;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExampleQuestionsTest extends TestCase
{
    private const ADMIN_ID = 7;

    #[Test]
    public function offersQuestionsInSortOrder(): void
    {
        $questions = $this->questions(['product_data' => 'write', 'sales_data' => 'write'], [
            'second' => ['question' => 'Second', 'tool' => 'sales_data', 'action' => 'order_count', 'icon' => 'orders', 'sortOrder' => 20],
            'first' => ['question' => 'First', 'tool' => 'product_data', 'action' => 'low_stock', 'icon' => 'stock', 'sortOrder' => 10],
        ]);

        self::assertSame(
            [['question' => 'First', 'icon' => 'stock'], ['question' => 'Second', 'icon' => 'orders']],
            $questions->getForAdmin(self::ADMIN_ID)
        );
    }

    #[Test]
    public function hidesQuestionsForDisabledSkills(): void
    {
        $questions = $this->questions(['sales_data' => 'write'], [
            'low_stock' => ['question' => 'Low stock', 'tool' => 'product_data', 'action' => 'low_stock'],
            'unknown' => ['question' => 'Unknown', 'tool' => 'not_registered'],
        ]);

        self::assertSame([], $questions->getForAdmin(self::ADMIN_ID));
    }

    #[Test]
    public function hidesWriteQuestionsFromReadOnlyGrants(): void
    {
        $questions = $this->questions(['coupon_manager' => 'read'], [
            'discount_code' => ['question' => 'Create a discount code', 'tool' => 'coupon_manager', 'action' => 'create_rule'],
        ]);

        self::assertSame([], $questions->getForAdmin(self::ADMIN_ID));
    }

    #[Test]
    public function hidesQuestionsWhoseMagentoAclIsDenied(): void
    {
        $questions = $this->questions(['product_data' => 'write'], [
            'low_stock' => ['question' => 'Low stock', 'tool' => 'product_data', 'action' => 'low_stock'],
        ], deniedAcl: 'Magento_Catalog::products');

        self::assertSame([], $questions->getForAdmin(self::ADMIN_ID));
    }

    #[Test]
    public function capsTheNumberOfQuestions(): void
    {
        $items = [];
        for ($i = 1; $i <= 6; $i++) {
            $items['q' . $i] = ['question' => 'Question ' . $i, 'tool' => 'product_data', 'action' => 'low_stock', 'sortOrder' => $i];
        }

        $result = $this->questions(['product_data' => 'write'], $items)->getForAdmin(self::ADMIN_ID);

        self::assertSame(['Question 1', 'Question 2', 'Question 3', 'Question 4'], array_column($result, 'question'));
    }

    /**
     * @param array<string, string> $grants
     * @param array<string, array<string, mixed>> $items
     */
    private function questions(array $grants, array $items, string $deniedAcl = ''): ExampleQuestions
    {
        $checker = $this->createStub(PermissionChecker::class);
        $checker->method('isAllowed')->willReturnCallback(
            static fn(int $adminUserId, string $skill, string $action): bool => $adminUserId === self::ADMIN_ID
                && match ($grants[$skill] ?? 'disabled') {
                    'write' => true,
                    'read' => $action === 'read',
                    default => false,
                }
        );

        $registry = new ToolRegistry($checker, [
            new FakeTool('product_data', ['low_stock'], ['low_stock'], 'Magento_Catalog::products'),
            new FakeTool('sales_data', ['order_count'], ['order_count'], 'Magento_Sales::sales'),
            new FakeTool('coupon_manager', ['create_rule'], [], 'Magento_SalesRule::quote'),
        ]);

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn(string $resource): bool => $resource !== $deniedAcl
        );

        return new ExampleQuestions($registry, $authorization, $items);
    }
}
