<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Navigation;

use MagoAssistant\Mago\Service\Skills\Navigation\AdminNavigator;
use MagoAssistant\Mago\Service\Skills\Navigation\PageRegistry;
use MagoAssistant\Mago\Service\Url\AdminRouteAcl;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminNavigatorTest extends TestCase
{
    private AdminRouteAcl&MockObject $adminRouteAcl;

    protected function setUp(): void
    {
        $this->adminRouteAcl = $this->createMock(AdminRouteAcl::class);
    }

    /**
     * Which resource a route resolves to is Magento's answer, checked against the real route
     * config in Test/Integration; here the resolver is stubbed, so what is under test is whether
     * the navigator gates on it at all.
     *
     * @param string[] $allowedResources What Magento's ACL answers yes to for this admin
     */
    private function navigator(array $allowedResources = ['Magento_Backend::admin']): AdminNavigator
    {
        $secureAdminUrl = $this->createMock(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')->willReturnCallback(
            static fn(string $route): string => 'https://example.test/admin/' . $route . '/key/abc'
        );

        return new AdminNavigator(
            new PageRegistry(),
            $secureAdminUrl,
            new EntityRouteMap($this->adminRouteAcl),
            $this->adminRouteAcl,
            new FakeAclAuthorization($allowedResources)
        );
    }

    #[Test]
    public function aDirectLinkIsGatedByTheResourceOfTheEntitysOwnEditRoute(): void
    {
        $this->adminRouteAcl->expects(self::once())->method('forRoute')
            ->with('sales/order/view')
            ->willReturn('Magento_Sales::actions_view');

        self::assertSame(
            'Magento_Sales::actions_view',
            $this->navigator()->getMagentoAcl(['entity_type' => 'order', 'entity_id' => 42])
        );
    }

    #[Test]
    public function everyEntityTypeOfferedInTheSchemaIsGated(): void
    {
        $this->adminRouteAcl->method('forRoute')->willReturn('Magento_Example::resource');
        $navigator = $this->navigator();
        $offered = $navigator->getParameterSchema()['properties']['entity_type']['enum'];

        self::assertNotEmpty($offered);
        foreach ($offered as $entityType) {
            self::assertSame(
                'Magento_Example::resource',
                $navigator->getMagentoAcl(['entity_type' => $entityType, 'entity_id' => 42]),
                $entityType
            );
        }
    }

    /**
     * A known type whose route Magento resolves nothing for stays closed: that cannot be told
     * apart from a wrong gate.
     */
    #[Test]
    public function aKnownEntityTypeWhoseRouteResolvesToNothingIsRefused(): void
    {
        $this->adminRouteAcl->method('forRoute')->willReturn(null);

        self::assertSame('', $this->navigator()->getMagentoAcl(['entity_type' => 'order', 'entity_id' => 42]));
    }

    /**
     * An entity type the map does not know builds no link, so execute() gets to say "Unknown
     * entity type" - which tells the model more than a permission error would.
     */
    #[Test]
    public function anUnknownEntityTypeIsAnsweredByExecuteNotByTheGate(): void
    {
        $this->adminRouteAcl->expects(self::never())->method('forRoute');
        $navigator = $this->navigator();
        $input = ['entity_type' => 'parcel', 'entity_id' => 42];

        self::assertSame('Magento_Backend::admin', $navigator->getMagentoAcl($input));
        self::assertSame('Unknown entity type: parcel', $navigator->execute($input)['error']);
    }

    /**
     * Search mode names the standard admin pages this module ships a registry of and carries no
     * entity to gate on, so any logged-in admin may use it; what it returns is filtered instead.
     */
    #[Test]
    public function searchModeIsOpenToAnyAdmin(): void
    {
        self::assertSame('Magento_Backend::admin', $this->navigator()->getMagentoAcl(['query' => 'orders']));
        self::assertSame('Magento_Backend::admin', $this->navigator()->getMagentoAcl());
        self::assertSame('Magento_Backend::admin', $this->navigator()->getMagentoAcl(['entity_type' => 'order']));
    }

    /**
     * A link to a page that answers 403 on arrival is noise, so a search result is kept only when
     * the admin holds the resource Magento guards that page with.
     */
    #[Test]
    public function searchResultsAreOnlyPagesTheAdminMayOpen(): void
    {
        $this->adminRouteAcl->method('forRoute')->willReturnCallback(
            static fn(string $route): ?string => match ($route) {
                'sales/order' => 'Magento_Sales::sales_order',
                'sales/invoice' => 'Magento_Sales::sales_invoice',
                default => null,
            }
        );
        $results = $this->navigator(['Magento_Sales::sales_order'])->execute(['query' => 'invoice orders'])['results'];

        $labels = array_column($results, 'label');
        self::assertContains('Orders', $labels);
        self::assertNotContains('Invoices', $labels);
    }

    /**
     * The permission filter runs before the limit, so a denied page in the top results is replaced
     * by the next allowed one instead of leaving the admin short.
     */
    #[Test]
    public function aDeniedPageDoesNotCostTheAdminAResult(): void
    {
        $this->adminRouteAcl->method('forRoute')->willReturnCallback(
            static fn(string $route): ?string => $route === 'sales/invoice' ? 'Magento_Sales::sales_invoice' : 'Magento_Backend::admin'
        );
        $results = $this->navigator(['Magento_Backend::admin'])->execute(['query' => 'sales', 'limit' => 2])['results'];

        self::assertCount(2, $results);
        self::assertNotContains('Invoices', array_column($results, 'label'));
    }

    /**
     * The category filter runs before the limit too, for the same reason: a search narrowed to a
     * category still fills its limit from that category.
     */
    #[Test]
    public function aCategoryFilterDoesNotCostTheAdminAResultEither(): void
    {
        $this->adminRouteAcl->method('forRoute')->willReturn('Magento_Backend::admin');

        $results = $this->navigator()->execute(['query' => 'product catalog', 'category' => 'Catalog', 'limit' => 2])['results'];

        self::assertCount(2, $results);
        self::assertSame(['Catalog', 'Catalog'], array_column($results, 'category'));
    }

    /**
     * A route Magento resolves no controller for is not offered either: there is no way to tell
     * what guards it.
     */
    #[Test]
    public function aPageWhoseRouteResolvesToNothingIsNotOffered(): void
    {
        $this->adminRouteAcl->method('forRoute')->willReturn(null);

        $result = $this->navigator(['Magento_Sales::sales_order'])->execute(['query' => 'orders']);

        self::assertSame([], $result['results']);
    }
}
