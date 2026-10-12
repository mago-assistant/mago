<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Analytics\SalesData;

use Magento\Framework\App\ResourceConnection;
use MagoAssistant\Mago\Service\Skills\Analytics\SalesData\OrderCountAction;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where orders come from (#17): the country of an order is where it was shipped, and an order with
 * nothing to ship counts under the country it was billed to.
 */
final class OrderCountActionTest extends TestCase
{
    private const PERIOD = '2001-01-05:2001-01-05';
    private const CREATED_AT = '2001-01-05 12:00:00';
    private const ADMIN_USER_ID = 1;

    private SalesOrderFixture $orders;
    private OrderCountAction $action;

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $this->orders = new SalesOrderFixture($objectManager->get(ResourceConnection::class), self::CREATED_AT);
        $this->action = $objectManager->get(OrderCountAction::class);
        $this->orders->begin();
    }

    protected function tearDown(): void
    {
        $this->orders->rollBack();
    }

    #[Test]
    public function itGroupsOrdersByTheCountryTheyWereShippedTo(): void
    {
        $this->orders->addressedOrder('DE', 'NL');
        $this->orders->addressedOrder('DE', 'DE');
        $this->orders->addressedOrder('BE', 'NL');

        $result = $this->countOrders(['group_by' => 'country']);

        self::assertSame(['DE' => 2, 'BE' => 1], $result['counts'], 'Most orders first');
        self::assertSame(3, $result['total']);
        self::assertSame('shipping', $result['country_address']);
    }

    #[Test]
    public function itCountsAnOrderWithoutAShippingAddressUnderItsBillingCountry(): void
    {
        $this->orders->addressedOrder(null, 'NL');
        $this->orders->addressedOrder(null, null);

        $result = $this->countOrders(['group_by' => 'country']);

        self::assertSame(['NL' => 1, 'unknown' => 1], $result['counts']);
    }

    #[Test]
    public function itGroupsByTheBillingCountryWhenAskedTo(): void
    {
        $this->orders->addressedOrder('DE', 'NL');
        $this->orders->addressedOrder('BE', 'NL');

        $result = $this->countOrders(['group_by' => 'country', 'address' => 'Billing']);

        self::assertSame(['NL' => 2], $result['counts']);
        self::assertSame('billing', $result['country_address']);
    }

    #[Test]
    public function itFiltersOnTheShippingCountryByDefault(): void
    {
        $this->orders->addressedOrder('DE', 'NL');
        $this->orders->addressedOrder('NL', 'DE');

        $shipped = $this->countOrders(['country' => 'de']);
        $billed = $this->countOrders(['country' => 'DE', 'address' => 'billing']);

        self::assertSame(1, $shipped['total']);
        self::assertSame(1, $billed['total']);
        self::assertContains('country', $shipped['filters_applied']);
    }

    #[Test]
    public function itLeavesTheAddressOutOfACountThatDoesNotAskForACountry(): void
    {
        $this->orders->addressedOrder('DE', 'NL');

        $result = $this->countOrders([]);

        self::assertSame(1, $result['total']);
        self::assertNull($result['country_address']);
    }

    #[Test]
    public function itRefusesACountryThatIsNotACountryCode(): void
    {
        $this->orders->addressedOrder('DE', 'NL');

        $result = $this->countOrders(['group_by' => 'country', 'country' => 'none']);

        self::assertArrayHasKey('error', $result);
        self::assertArrayNotHasKey('counts', $result);
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function countOrders(array $params): array
    {
        return $this->action->execute(['period' => self::PERIOD] + $params, self::ADMIN_USER_ID);
    }
}
