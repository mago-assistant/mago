<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Marketing\CatalogPriceRules;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\CreateRuleAction;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\GetRuleAction;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\UpdateRuleAction;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #329: update_rule replaces a saved rule's conditions instead of adding to them, and get_rule
 * reads them back in words. Everything runs in a transaction that is rolled back.
 */
final class UpdateRuleActionTest extends TestCase
{
    private const ADMIN_USER_ID = 1;

    private AdapterInterface $connection;

    protected function setUp(): void
    {
        $this->connection = MagentoObjectManager::get()->get(ResourceConnection::class)->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
    }

    #[Test]
    public function itReplacesTheConditionsAndGetRuleReadsThemBack(): void
    {
        $objectManager = MagentoObjectManager::get();
        $websiteIds = array_map(
            static fn ($website): int => (int)$website->getId(),
            array_values($objectManager->get(StoreManagerInterface::class)->getWebsites())
        );
        $created = $objectManager->get(CreateRuleAction::class)->execute([
            'name' => 'Issue 329 update',
            'discount_type' => 'percent',
            'discount_amount' => 10,
            'website_ids' => $websiteIds,
            'customer_group_ids' => [0],
            'conditions' => [['attribute' => 'sku', 'operator' => 'is_not', 'values' => ['first-329']]],
        ], self::ADMIN_USER_ID);
        self::assertArrayHasKey('rule_id', $created, (string)($created['error'] ?? ''));

        $updated = $objectManager->get(UpdateRuleAction::class)->execute([
            'rule_id' => $created['rule_id'],
            'discount_amount' => 15,
            'is_active' => false,
            'conditions' => [['attribute' => 'sku', 'operator' => 'is_not', 'values' => ['second-329', 'third-329']]],
            'conditions_match' => 'any',
        ], self::ADMIN_USER_ID);
        $rule = $objectManager->get(GetRuleAction::class)->execute(['rule_id' => $created['rule_id']], self::ADMIN_USER_ID)['rule'];

        self::assertTrue($updated['success'] ?? false, (string)($updated['error'] ?? ''));
        self::assertSame(15.0, $rule['discount_amount']);
        self::assertFalse($rule['is_active']);
        self::assertStringContainsString('If ANY of these conditions are TRUE', $rule['conditions_readable']);
        self::assertStringContainsString('SKU is not one of second-329,third-329', $rule['conditions_readable']);
        self::assertStringNotContainsString('first-329', $rule['conditions_readable']);
    }

    /**
     * The repository hands out the same rule for the rest of the request, so what Magento refused
     * must not stay on it for a later write to save
     */
    #[Test]
    public function aRefusedUpdateLeavesTheConditionsAsTheyWere(): void
    {
        $objectManager = MagentoObjectManager::get();
        $created = $objectManager->get(CreateRuleAction::class)->execute([
            'name' => 'Issue 329 refused update',
            'discount_type' => 'percent',
            'discount_amount' => 10,
            'from_date' => '2026-12-01',
            'website_ids' => $this->websiteIds(),
            'customer_group_ids' => [0],
            'conditions' => [['attribute' => 'sku', 'operator' => 'is_not', 'values' => ['first-329']]],
        ], self::ADMIN_USER_ID);

        $updated = $objectManager->get(UpdateRuleAction::class)->execute([
            'rule_id' => $created['rule_id'],
            'to_date' => '2026-11-01',
            'conditions' => [['attribute' => 'sku', 'operator' => 'is_not', 'values' => ['second-329']]],
        ], self::ADMIN_USER_ID);
        $rule = $objectManager->get(GetRuleAction::class)->execute(['rule_id' => $created['rule_id']], self::ADMIN_USER_ID)['rule'];

        self::assertArrayHasKey('error', $updated);
        self::assertNull($rule['to_date']);
        self::assertStringContainsString('first-329', $rule['conditions_readable']);
        self::assertStringNotContainsString('second-329', $rule['conditions_readable']);
    }

    /**
     * @return int[]
     */
    private function websiteIds(): array
    {
        return array_map(
            static fn ($website): int => (int)$website->getId(),
            array_values(MagentoObjectManager::get()->get(StoreManagerInterface::class)->getWebsites())
        );
    }
}
