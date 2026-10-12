<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess\FollowUp;

use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;

/**
 * Work Magento only does after a real REST request (a webapi_rest plugin) that an in-process call
 * must not miss. Runs after the service succeeded.
 */
interface ServiceCallFollowUpInterface
{
    /**
     * @param array<int, mixed> $arguments The service method's arguments, as passed
     */
    public function afterCall(ResolvedRoute $route, array $arguments): void;
}
