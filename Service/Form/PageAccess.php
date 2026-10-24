<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Form;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Model\Form\PageContext;
use MagoAssistant\Mago\Service\Url\AdminPath;
use MagoAssistant\Mago\Service\Url\AdminRouteAcl;

/**
 * Whether the admin may act on an admin page through page_form, decided by the Magento ACL
 * resource that guards that page (#203).
 *
 * The form page_form reads and writes is reported by the browser, so "the form is open, so its
 * controller ACL already passed" only holds for an honest client. The server does not take that
 * on trust: the reported page is resolved to its admin controller through AdminRouteAcl, the same
 * way admin_navigator decides which pages it may link to, and the resource read off that
 * controller is checked against the admin's own role. A page whose controller cannot be found
 * cannot be told apart from a page nobody may open, so it is refused too (fail closed).
 *
 * A controller that overrides `_isAllowed()` with logic beyond ADMIN_RESOURCE is checked on its
 * ADMIN_RESOURCE only: running that logic needs the controller built around a live request. The
 * native Save still runs its own check on whatever page_form stages.
 *
 * The reasons are fixed sentences plus a resource id read off a controller class, never data the
 * client sent, so they can be shown to the model as they are.
 */
class PageAccess
{
    private const ROUTE_SEGMENTS = 3;

    private const UNRESOLVED_REASON = 'Access denied: the assistant cannot tell which Magento permission '
        . 'guards this admin page, so it cannot read or write the form on it. Tell the administrator '
        . 'so, do not call it a technical issue.';

    private const DENIED_REASON = 'Access denied: you do not have the required Magento permission (%s) '
        . 'to open this admin page, so the assistant cannot read or write the form on it. Tell the '
        . 'administrator so, do not call it a technical issue.';

    public function __construct(
        private readonly AdminPath $adminPath,
        private readonly AdminRouteAcl $adminRouteAcl,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * Why the admin may not act on the form open in the browser, or null when they may.
     */
    public function findOpenPageDenial(PageContext $pageContext): ?string
    {
        $route = $this->toAdminRoute($pageContext->route);

        return $route === null ? self::UNRESOLVED_REASON : $this->findRouteDenial($route);
    }

    /**
     * Why the admin may not act on the page at this admin route ("catalog/product/edit"), or null
     * when they may.
     */
    public function findRouteDenial(string $route): ?string
    {
        $resource = $this->adminRouteAcl->forRoute($route);
        if ($resource === null) {
            return self::UNRESOLVED_REASON;
        }

        return $this->authorization->isAllowed($resource) ? null : sprintf(self::DENIED_REASON, $resource);
    }

    /**
     * The browser reports window.location.pathname ("/admin/catalog/product/edit/id/5/key/.../").
     * Below the admin path, the first three segments are front name, controller and action, the
     * way the backend router reads them; the rest are parameters. A path outside the admin path
     * names no admin route at all.
     */
    private function toAdminRoute(string $path): ?string
    {
        $adminPath = $this->adminPath->get();
        if (!str_starts_with($path, $adminPath)) {
            return null;
        }

        $segments = array_slice($this->toSegments(substr($path, strlen($adminPath))), 0, self::ROUTE_SEGMENTS);

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * @return list<string>
     */
    private function toSegments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
    }
}
