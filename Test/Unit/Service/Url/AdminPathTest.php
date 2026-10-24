<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Url;

use MagoAssistant\Mago\Service\Url\AdminPath;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AdminPathTest extends TestCase
{
    #[Test]
    public function itPutsTheAdminFrontNameAfterTheBasePath(): void
    {
        $adminPath = new AdminPath(new FakeBackendUrl('https://shop.test/', 'admin'));

        $path = $adminPath->get();

        self::assertSame('/admin/', $path);
    }

    #[Test]
    public function itUsesACustomAdminFrontName(): void
    {
        $adminPath = new AdminPath(new FakeBackendUrl('https://shop.test/', 'backoffice'));

        $path = $adminPath->get();

        self::assertSame('/backoffice/', $path);
    }

    #[Test]
    public function itKeepsIndexPhpWhenUrlRewritesAreOff(): void
    {
        $adminPath = new AdminPath(new FakeBackendUrl('https://shop.test/index.php/', 'admin'));

        $path = $adminPath->get();

        self::assertSame('/index.php/admin/', $path);
    }

    #[Test]
    public function itKeepsTheSubdirectoryOfAStoreInstalledBelowTheRoot(): void
    {
        $adminPath = new AdminPath(new FakeBackendUrl('https://shop.test/magento', 'backoffice'));

        $path = $adminPath->get();

        self::assertSame('/magento/backoffice/', $path);
    }
}
