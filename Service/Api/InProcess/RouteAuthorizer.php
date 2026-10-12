<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\Framework\AuthorizationInterface;

/**
 * Applies a route's <resources> the way Magento\Framework\Webapi\Authorization does: every resource
 * must be allowed. "anonymous" routes are open to anyone; "self" routes act on the logged-in customer,
 * which an admin user never is.
 */
class RouteAuthorizer
{
    public const RESOURCE_ANONYMOUS = 'anonymous';
    public const RESOURCE_SELF = 'self';

    /**
     * @throws AccessDeniedException
     */
    public function assertAllowed(ResolvedRoute $route, AuthorizationInterface $authorization): void
    {
        $deniedResources = array_filter(
            $route->aclResources,
            fn (string $resource): bool => !$this->isAllowed($resource, $authorization)
        );

        if ($route->aclResources === []) {
            throw new AccessDeniedException(__('The route has no ACL resource an admin user can be allowed.'));
        }

        if ($deniedResources !== []) {
            throw new AccessDeniedException(__(
                'The admin user is not allowed to use %1.',
                implode(', ', $deniedResources)
            ));
        }
    }

    private function isAllowed(string $resource, AuthorizationInterface $authorization): bool
    {
        return match ($resource) {
            self::RESOURCE_ANONYMOUS => true,
            self::RESOURCE_SELF => false,
            default => $authorization->isAllowed($resource),
        };
    }
}
