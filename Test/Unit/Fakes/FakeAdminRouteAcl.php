<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Url\AdminRouteAcl;

/**
 * Answers the admin routes it was given with their resource, and every other route with nothing,
 * the way AdminRouteAcl answers a route no controller resolves for. The routes it was asked about
 * are recorded.
 */
final class FakeAdminRouteAcl extends AdminRouteAcl
{
    /** @var list<string> */
    private array $askedRoutes = [];

    /**
     * @param array<string,string> $resourcesByRoute "catalog/product/edit" => "Magento_Catalog::products"
     */
    public function __construct(
        private readonly array $resourcesByRoute = []
    ) {
    }

    public function forRoute(string $route): ?string
    {
        $this->askedRoutes[] = $route;

        return $this->resourcesByRoute[$route] ?? null;
    }

    /**
     * @return list<string>
     */
    public function askedRoutes(): array
    {
        return $this->askedRoutes;
    }
}
