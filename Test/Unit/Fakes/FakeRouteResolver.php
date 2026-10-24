<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;
use MagoAssistant\Mago\Service\Api\InProcess\RouteResolver;

/**
 * Matches every call to the one route it was given.
 */
final class FakeRouteResolver extends RouteResolver
{
    public function __construct(
        private readonly ResolvedRoute $route
    ) {
    }

    public function resolve(ApiCall $call): ResolvedRoute
    {
        return $this->route;
    }
}
