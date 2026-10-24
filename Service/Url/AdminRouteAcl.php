<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Url;

use Magento\Framework\App\Route\ConfigInterface;
use Magento\Framework\App\Router\ActionList;

/**
 * The native Magento ACL resource guarding an admin route, asked of Magento itself rather than
 * kept as a list here.
 *
 * A tool that acts on an admin screen should be gated by whatever that screen is gated by. Writing
 * those pairs down means maintaining them: a route added without its resource is gated by nothing,
 * and a resource Magento changes drifts silently. So the route is resolved the way Magento's own
 * backend router resolves it - front name to module through the merged route config, then
 * module/controller/action to a controller class through ActionList - and the resource is read off
 * that class's ADMIN_RESOURCE.
 *
 * ADMIN_RESOURCE is inherited, which is the point: most controllers do not declare one and take
 * their module's, exactly as `AbstractAction::_isAllowed()` reads it through `static::`. So this
 * returns what Magento enforces on that page, including the broad `Magento_Backend::admin` for the
 * few pages Magento itself guards no more tightly than that.
 */
class AdminRouteAcl
{
    private const AREA = 'adminhtml';

    private const RESOURCE_CONSTANT = 'ADMIN_RESOURCE';

    /** @var array<string,string|null> */
    private array $resolved = [];

    public function __construct(
        private readonly ConfigInterface $routeConfig,
        private readonly ActionList $actionList
    ) {
    }

    /**
     * @param string $route An admin route path: "sales/order/view", "cms/page/edit"
     * @return string|null The resource, or null when no controller answers that route
     */
    public function forRoute(string $route): ?string
    {
        // array_key_exists, not ??=: a route that resolves to null is an answer worth keeping,
        // and ??= would re-resolve it on every call.
        if (!array_key_exists($route, $this->resolved)) {
            $this->resolved[$route] = $this->resolve($route);
        }

        return $this->resolved[$route];
    }

    private function resolve(string $route): ?string
    {
        // An omitted action is "index", the same default the router applies.
        [$frontName, $controller, $action] = array_pad(explode('/', trim($route, '/')), 3, 'index');
        if ($frontName === '' || $controller === '') {
            return null;
        }

        foreach ($this->routeConfig->getModulesByFrontName($frontName, self::AREA) as $module) {
            $class = $this->actionList->get($module, self::AREA, $controller, $action);
            if (is_string($class) && defined($class . '::' . self::RESOURCE_CONSTANT)) {
                return (string)constant($class . '::' . self::RESOURCE_CONSTANT);
            }
        }

        return null;
    }
}
