<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess\Guard;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Model\Page;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\CmsPageDesignGuard;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\DesignChangeRefusedException;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePageAuthorizationFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CmsPageDesignGuardTest extends TestCase
{
    private const PAGE_REPOSITORY = 'Magento\Cms\Api\PageRepositoryInterface';

    #[Test]
    public function itRefusesADesignChangeWithCoresMessageAndTheMissingPermission(): void
    {
        $guard = new CmsPageDesignGuard(new FakePageAuthorizationFactory(true));

        $this->expectException(DesignChangeRefusedException::class);
        $this->expectExceptionMessage(
            'You are not allowed to change CMS pages design settings. This needs the Magento_Cms::save_design permission.'
        );

        $guard->guard($this->pageSave(), [$this->page()], new FakeAclAuthorization(['Magento_Cms::page']));
    }

    #[Test]
    public function itLetsAnAdminWithTheSaveDesignPermissionChangeTheDesign(): void
    {
        $factory = new FakePageAuthorizationFactory(true);
        $page = $this->page();

        (new CmsPageDesignGuard($factory))->guard(
            $this->pageSave(),
            [$page],
            new FakeAclAuthorization([CmsPageDesignGuard::ACL_RESOURCE])
        );

        self::assertSame([$page], $factory->createdAuthorizations()[0]->checkedPages());
    }

    #[Test]
    public function itChecksTheConvertedPageAsTheAdminTheCallActsFor(): void
    {
        $factory = new FakePageAuthorizationFactory(false);
        $page = $this->page();
        $adminAuthorization = new FakeAclAuthorization([]);

        (new CmsPageDesignGuard($factory))->guard($this->pageSave(), [$page], $adminAuthorization);

        $created = $factory->createdAuthorizations();
        self::assertCount(1, $created);
        self::assertSame($adminAuthorization, $created[0]->adminAuthorization());
        self::assertSame([$page], $created[0]->checkedPages());
    }

    #[Test]
    public function itLeavesOtherRoutesOfThePageRepositoryAlone(): void
    {
        $factory = new FakePageAuthorizationFactory(true);
        $route = new ResolvedRoute(self::PAGE_REPOSITORY, 'deleteById', '/V1/cmsPage/:pageId', [], ['pageId' => 7]);

        (new CmsPageDesignGuard($factory))->guard($route, [7], new FakeAclAuthorization([]));

        self::assertSame([], $factory->createdAuthorizations());
    }

    private function pageSave(): ResolvedRoute
    {
        return new ResolvedRoute(self::PAGE_REPOSITORY, 'save', '/V1/cmsPage', ['Magento_Cms::page'], []);
    }

    private function page(): PageInterface
    {
        return (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
    }
}
