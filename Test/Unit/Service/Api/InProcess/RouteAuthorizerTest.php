<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess;

use MagoAssistant\Mago\Service\Api\InProcess\AccessDeniedException;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;
use MagoAssistant\Mago\Service\Api\InProcess\RouteAuthorizer;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteAuthorizerTest extends TestCase
{
    #[Test]
    public function itAllowsARouteWhenTheAdminHasEveryResourceOfIt(): void
    {
        $authorization = new FakeAclAuthorization(['Magento_Sales::actions_view', 'Magento_Sales::sales']);

        (new RouteAuthorizer())->assertAllowed($this->route(['Magento_Sales::actions_view', 'Magento_Sales::sales']), $authorization);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itRefusesARouteTheAdminHasNoAclFor(): void
    {
        $this->expectException(AccessDeniedException::class);

        (new RouteAuthorizer())->assertAllowed($this->route(['Magento_Customer::customer']), new FakeAclAuthorization([]));
    }

    #[Test]
    public function itRefusesARouteWhenOneOfItsResourcesIsMissing(): void
    {
        $authorization = new FakeAclAuthorization(['Magento_Sales::actions_view']);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Magento_Sales::sales');

        (new RouteAuthorizer())->assertAllowed($this->route(['Magento_Sales::actions_view', 'Magento_Sales::sales']), $authorization);
    }

    #[Test]
    public function itAllowsAnAnonymousRouteForAnyAdmin(): void
    {
        (new RouteAuthorizer())->assertAllowed($this->route(['anonymous']), new FakeAclAuthorization([]));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itRefusesACustomerSelfRouteToAnAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        (new RouteAuthorizer())->assertAllowed($this->route(['self']), new FakeAclAuthorization(['self']));
    }

    #[Test]
    public function itRefusesARouteThatDeclaresNoResources(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('The route has no ACL resource an admin user can be allowed.');

        (new RouteAuthorizer())->assertAllowed($this->route([]), new FakeAclAuthorization([]));
    }

    /**
     * @param string[] $resources
     */
    private function route(array $resources): ResolvedRoute
    {
        return new ResolvedRoute('Magento\Sales\Api\OrderRepositoryInterface', 'getList', '/V1/orders', $resources, []);
    }
}
