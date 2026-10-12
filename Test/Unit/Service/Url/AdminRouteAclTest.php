<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Url;

use Magento\Framework\App\Route\ConfigInterface;
use Magento\Framework\App\Router\ActionList;
use MagoAssistant\Mago\Service\Url\AdminRouteAcl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminRouteAclTest extends TestCase
{
    private ConfigInterface&MockObject $routeConfig;

    private ActionList&MockObject $actionList;

    protected function setUp(): void
    {
        $this->routeConfig = $this->createMock(ConfigInterface::class);
        $this->actionList = $this->createMock(ActionList::class);
    }

    private function resolver(): AdminRouteAcl
    {
        return new AdminRouteAcl($this->routeConfig, $this->actionList);
    }

    #[Test]
    public function itReadsTheResourceOffTheControllerThatAnswersTheRoute(): void
    {
        $this->routeConfig->method('getModulesByFrontName')->with('sales', 'adminhtml')
            ->willReturn(['Magento_Sales']);
        $this->actionList->expects(self::once())->method('get')
            ->with('Magento_Sales', 'adminhtml', 'order', 'view')
            ->willReturn(ControllerWithOwnResource::class);

        self::assertSame('Magento_Example::own', $this->resolver()->forRoute('sales/order/view'));
    }

    /**
     * Most admin controllers declare no resource of their own and take their module's, which is
     * what `AbstractAction::_isAllowed()` reads through `static::`. Reading the constant off the
     * class rather than the file is what picks that up.
     */
    #[Test]
    public function itPicksUpAResourceInheritedFromTheModulesOwnAbstractController(): void
    {
        $this->routeConfig->method('getModulesByFrontName')->willReturn(['Magento_Example']);
        $this->actionList->method('get')->willReturn(ControllerInheritingItsResource::class);

        self::assertSame('Magento_Example::own', $this->resolver()->forRoute('example/thing/edit'));
    }

    /**
     * A front name can be claimed by more than one module; the first module that actually has a
     * controller for it answers the route, the way the router picks it.
     */
    #[Test]
    public function itTriesEveryModuleClaimingTheFrontName(): void
    {
        $this->routeConfig->method('getModulesByFrontName')
            ->willReturn(['Magento_First', 'Magento_Second']);
        $this->actionList->method('get')->willReturnCallback(
            fn(string $module): ?string => $module === 'Magento_Second' ? ControllerWithOwnResource::class : null
        );

        self::assertSame('Magento_Example::own', $this->resolver()->forRoute('example/thing/edit'));
    }

    /**
     * An omitted action is "index", the same default the router applies.
     */
    #[Test]
    public function aTwoSegmentRouteResolvesTheIndexAction(): void
    {
        $this->routeConfig->method('getModulesByFrontName')->willReturn(['Magento_Example']);
        $this->actionList->expects(self::once())->method('get')
            ->with('Magento_Example', 'adminhtml', 'thing', 'index')
            ->willReturn(ControllerWithOwnResource::class);

        self::assertSame('Magento_Example::own', $this->resolver()->forRoute('example/thing'));
    }

    #[Test]
    public function aRouteNoControllerAnswersResolvesToNothing(): void
    {
        $this->routeConfig->method('getModulesByFrontName')->willReturn(['Magento_Example']);
        $this->actionList->method('get')->willReturn(null);

        self::assertNull($this->resolver()->forRoute('example/thing/edit'));
    }

    #[Test]
    public function aFrontNameNoModuleClaimsResolvesToNothing(): void
    {
        $this->routeConfig->method('getModulesByFrontName')->willReturn([]);

        self::assertNull($this->resolver()->forRoute('nonsense/thing/edit'));
    }

    /**
     * A controller that somehow carries no such constant must not be read as "allowed": there is
     * no resource to check, so the caller has to decide, not this class.
     */
    #[Test]
    public function aControllerWithoutTheConstantResolvesToNothing(): void
    {
        $this->routeConfig->method('getModulesByFrontName')->willReturn(['Magento_Example']);
        $this->actionList->method('get')->willReturn(\stdClass::class);

        self::assertNull($this->resolver()->forRoute('example/thing/edit'));
    }

    #[Test]
    public function aRouteWithNoFrontNameIsNotLookedUpAtAll(): void
    {
        $this->routeConfig->expects(self::never())->method('getModulesByFrontName');
        $resolver = $this->resolver();

        self::assertNull($resolver->forRoute(''));
        self::assertNull($resolver->forRoute('/'));
    }

    /**
     * A front name on its own is not malformed: the router reads it as its index controller and
     * index action, so this does too.
     */
    #[Test]
    public function aFrontNameOnItsOwnResolvesTheIndexControllerAndAction(): void
    {
        $this->routeConfig->method('getModulesByFrontName')->willReturn(['Magento_Example']);
        $this->actionList->expects(self::once())->method('get')
            ->with('Magento_Example', 'adminhtml', 'index', 'index')
            ->willReturn(ControllerWithOwnResource::class);

        self::assertSame('Magento_Example::own', $this->resolver()->forRoute('example'));
    }

    /**
     * getMagentoAcl() is asked more than once per tool call, and resolution reflects over a class
     * each time, so the answer per route is kept.
     */
    #[Test]
    public function itResolvesEachRouteOnlyOnce(): void
    {
        $this->routeConfig->expects(self::once())->method('getModulesByFrontName')
            ->willReturn(['Magento_Example']);
        $this->actionList->expects(self::once())->method('get')
            ->willReturn(ControllerWithOwnResource::class);
        $resolver = $this->resolver();

        self::assertSame('Magento_Example::own', $resolver->forRoute('example/thing/edit'));
        self::assertSame('Magento_Example::own', $resolver->forRoute('example/thing/edit'));
    }

    /**
     * A route that resolves to nothing is asked once too, rather than re-reflected every call.
     */
    #[Test]
    public function itRemembersThatARouteResolvedToNothing(): void
    {
        $this->routeConfig->expects(self::once())->method('getModulesByFrontName')->willReturn([]);
        $resolver = $this->resolver();

        self::assertNull($resolver->forRoute('nonsense/thing/edit'));
        self::assertNull($resolver->forRoute('nonsense/thing/edit'));
    }
}
