<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Navigation;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Route\ConfigInterface as RouteConfig;
use MagoAssistant\Mago\Service\Skills\Navigation\PageRegistry;
use MagoAssistant\Mago\Service\Url\AdminPath;
use MagoAssistant\Mago\Service\Url\AdminRouteAcl;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #303: the "Admin Users" link admin_navigator hands out redirects to the dashboard.
 *
 * Every page in PageRegistry is checked against the merged route config of a real install, the
 * two ways a link can fail the admin who clicks it:
 *
 * 1. The secret key. Backend\Model\Url::getUrl() signs the key with the first segment of the route
 *    it was given, verbatim. On arrival AbstractAction::_validateSecretKey() recomputes the key
 *    from the request's route name, which the router sets to the route *id* that owns the front
 *    name in the URL (Base::matchAction()). "admin/user" is signed as "admin" + "user" + "index"
 *    while the request is "adminhtml" + "user" + "index", so the key is refused and Magento sends
 *    the admin to the startup page: the dashboard.
 *
 * 2. The ACL resource. AdminNavigator::mayOpen() hides a page AdminRouteAcl resolves no resource
 *    for. AdminRouteAcl reads the first segment as a front name and falls back to the route id, so
 *    a page registered under its route id ("adminhtml/cache") is offered as well as signed right.
 *
 * The pages come from the PageRegistry the object manager builds, so pages an integrator adds
 * through additionalPages in di.xml are checked too. Outside a Magento install the provider falls
 * back to the built-in pages, because a skip thrown from a data provider is reported as an error;
 * setUp() then skips the tests.
 */
final class PageRegistryLinksTest extends TestCase
{
    private const AREA = 'adminhtml';
    private const KEY_SEGMENT = 'key';

    private SecureAdminUrl $secureAdminUrl;
    private BackendUrl $backendUrl;
    private RouteConfig $routeConfig;
    private AdminPath $adminPath;
    private AdminRouteAcl $adminRouteAcl;

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $this->secureAdminUrl = $objectManager->get(SecureAdminUrl::class);
        $this->backendUrl = $objectManager->get(BackendUrl::class);
        $this->routeConfig = $objectManager->get(RouteConfig::class);
        $this->adminPath = $objectManager->get(AdminPath::class);
        $this->adminRouteAcl = $objectManager->get(AdminRouteAcl::class);

        if (!$this->backendUrl->useSecretKey()) {
            self::markTestSkipped('admin/security/use_form_key is off on this install, so no link carries a key.');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function registryPages(): array
    {
        $pageRegistry = MagentoObjectManager::isAvailable()
            ? MagentoObjectManager::get()->get(PageRegistry::class)
            : new PageRegistry();
        $pages = $pageRegistry->getAll();

        return array_combine(
            array_map(static fn(array $page): string => $page['label'] . ' (' . $page['route'] . ')', $pages),
            array_map(static fn(array $page): array => [$page['route']], $pages)
        );
    }

    #[Test]
    #[DataProvider('registryPages')]
    public function theLinkCarriesTheKeyMagentoAcceptsOnArrival(string $route): void
    {
        $url = $this->secureAdminUrl->getUrl($route);
        $segments = $this->pathSegmentsBelowTheAdminPath($url);
        [$frontName, $controller, $action] = array_pad($segments, 3, 'index');
        $routeIdOnArrival = $this->routeConfig->getRouteByFrontName($frontName, self::AREA);

        self::assertNotFalse(
            $routeIdOnArrival,
            sprintf('%s links to %s, but no admin route answers the front name "%s": the link is a 404.', $route, $url, $frontName)
        );
        self::assertSame(
            $this->backendUrl->getSecretKey($routeIdOnArrival, $controller, $action),
            $this->keyOn($segments),
            sprintf(
                '%s links to %s with a key signed for route "%s", but on arrival Magento validates it for route "%s" '
                . '(%s/%s/%s), refuses it and redirects to the dashboard.',
                $route,
                $url,
                explode('/', $route)[0],
                $routeIdOnArrival,
                $routeIdOnArrival,
                $controller,
                $action
            )
        );
    }

    #[Test]
    #[DataProvider('registryPages')]
    public function thePageResolvesTheResourceThatGuardsItSoTheNavigatorCanOfferIt(string $route): void
    {
        self::assertNotNull(
            $this->adminRouteAcl->forRoute($route),
            sprintf('AdminRouteAcl resolves no controller for %s, so AdminNavigator::mayOpen() never offers it.', $route)
        );
    }

    /**
     * @return list<string>
     */
    private function pathSegmentsBelowTheAdminPath(string $url): array
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $adminPath = $this->adminPath->get();
        self::assertStringStartsWith($adminPath, $path, $url . ' is not under the admin path ' . $adminPath);

        return array_values(array_filter(
            explode('/', substr($path, strlen($adminPath))),
            static fn(string $segment): bool => $segment !== ''
        ));
    }

    /**
     * @param list<string> $segments
     */
    private function keyOn(array $segments): string
    {
        $position = array_search(self::KEY_SEGMENT, $segments, true);
        self::assertNotFalse($position, 'The link carries no /key/ segment: ' . implode('/', $segments));

        return $segments[$position + 1] ?? '';
    }
}
