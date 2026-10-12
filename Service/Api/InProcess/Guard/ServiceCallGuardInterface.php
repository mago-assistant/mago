<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess\Guard;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;

/**
 * A check Magento only runs for real REST requests (a webapi_rest plugin) that an in-process call
 * must not skip. It runs after the route's ACL and the input conversion, right before the service is
 * called, so it sees the same arguments a before-plugin on the service would.
 */
interface ServiceCallGuardInterface
{
    /**
     * @param array<int, mixed> $arguments The service arguments ServiceInputProcessor built
     * @throws LocalizedException When the call must not go ahead
     */
    public function guard(ResolvedRoute $route, array $arguments, AuthorizationInterface $authorization): void;
}
