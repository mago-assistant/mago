<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Url;

use MagoAssistant\Mago\Service\Url\AdminRouteAcl;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class EntityRouteMapTest extends TestCase
{
    private AdminRouteAcl&MockObject $adminRouteAcl;

    protected function setUp(): void
    {
        $this->adminRouteAcl = $this->createMock(AdminRouteAcl::class);
    }

    private function map(): EntityRouteMap
    {
        return new EntityRouteMap($this->adminRouteAcl);
    }

    /**
     * Which resource that turns out to be is Magento's answer, not this map's; what this map owes
     * is asking about the entity's own route. Test/Integration checks the answers themselves.
     */
    #[Test]
    public function itAsksForTheResourceOfTheEntitysOwnRoute(): void
    {
        $this->adminRouteAcl->expects(self::once())->method('forRoute')
            ->with('sales/order/view')
            ->willReturn('Magento_Sales::actions_view');

        self::assertSame('Magento_Sales::actions_view', $this->map()->getAclResource('order'));
    }

    #[Test]
    public function everyRoutableEntityTypeHasARouteToAskAboutAndAParamKey(): void
    {
        $this->adminRouteAcl->method('forRoute')->willReturn('Magento_Example::resource');
        $map = $this->map();

        foreach ($map->getEntityTypes() as $entityType) {
            self::assertNotNull($map->getRoute($entityType), $entityType . ' has no route');
            self::assertNotNull($map->getParamKey($entityType), $entityType . ' has no param key');
            self::assertNotNull($map->getAclResource($entityType), $entityType . ' resolves no resource');
        }
    }

    /**
     * Reading records is what the grid shows, so a read is gated by the grid's resource; the edit
     * screen can ask for more (cms/page/edit is guarded by Magento_Cms::save, the grid by
     * Magento_Cms::page), and the two are asked about separately.
     */
    #[Test]
    public function itAsksForTheGridsResourceSeparatelyFromTheEditScreens(): void
    {
        $this->adminRouteAcl->method('forRoute')->willReturnMap([
            ['cms/page/index', 'Magento_Cms::page'],
            ['cms/page/edit', 'Magento_Cms::save'],
        ]);
        $map = $this->map();

        self::assertSame('Magento_Cms::page', $map->getListAclResource('cms_page'));
        self::assertSame('Magento_Cms::save', $map->getAclResource('cms_page'));
    }

    #[Test]
    public function everyRoutableEntityTypeHasAGridToAskAbout(): void
    {
        $this->adminRouteAcl->method('forRoute')->willReturn('Magento_Example::resource');
        $map = $this->map();

        foreach ($map->getEntityTypes() as $entityType) {
            self::assertNotNull($map->getListAclResource($entityType), $entityType . ' has no grid route');
        }
    }

    #[Test]
    public function anUnknownEntityTypeIsNeverAskedAbout(): void
    {
        $this->adminRouteAcl->expects(self::never())->method('forRoute');
        $map = $this->map();

        self::assertNull($map->getAclResource('parcel'));
        self::assertNull($map->getListAclResource('parcel'));
        self::assertNull($map->getRoute('parcel'));
        self::assertNull($map->getParamKey('parcel'));
    }

    /**
     * A route Magento has no controller for leaves the caller with nothing to check, which must
     * read as "no resource" rather than as an empty allow.
     */
    #[Test]
    public function anUnresolvableRouteResolvesToNothing(): void
    {
        $this->adminRouteAcl->method('forRoute')->willReturn(null);

        self::assertNull($this->map()->getAclResource('order'));
    }
}
