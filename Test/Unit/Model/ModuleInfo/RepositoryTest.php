<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\ModuleInfo;

use MagoAssistant\Mago\Api\ModuleInfo\RepositoryInterface;
use MagoAssistant\Mago\Model\ModuleInfo\Repository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeComponentRegistrar;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInstalledPackages;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeModuleDeclarationLoader;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeModuleList;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePackageInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase
{
    #[Test]
    public function itReportsTheVersionComposerActuallyInstalled(): void
    {
        $repository = $this->repository(
            (new FakePackageInfo())->withModule('Acme_Blog', 'acme/module-blog'),
            (new FakeModuleDeclarationLoader())->withModule('Acme_Blog'),
            (new FakeInstalledPackages())->withPackage('acme/module-blog', '2.4.0')
        );

        self::assertSame(
            ['version' => '2.4.0', 'source' => RepositoryInterface::VERSION_SOURCE_COMPOSER],
            $repository->getVersionInfo('Acme_Blog')
        );
    }

    #[Test]
    public function itPrefersComposerOverAStaleVersionFieldInTheModuleComposerJson(): void
    {
        $repository = $this->repository(
            (new FakePackageInfo())->withModule('MagoAssistant_Mago', 'mago-assistant/magento2-mago', '1.0.0'),
            (new FakeModuleDeclarationLoader())->withModule('MagoAssistant_Mago'),
            (new FakeInstalledPackages())->withPackage('mago-assistant/magento2-mago', '1.1.0')
        );

        self::assertSame(
            ['version' => '1.1.0', 'source' => RepositoryInterface::VERSION_SOURCE_COMPOSER],
            $repository->getVersionInfo('MagoAssistant_Mago')
        );
    }

    #[Test]
    public function itResolvesModulesRegisteredFromASubdirectoryOfTheirPackage(): void
    {
        $repository = $this->repository(
            new FakePackageInfo(),
            (new FakeModuleDeclarationLoader())->withModule('Hyva_Checkout'),
            (new FakeInstalledPackages())->withPackage(
                'hyva-themes/magento2-hyva-checkout',
                '1.3.12',
                '/app/vendor/hyva-themes/magento2-hyva-checkout'
            ),
            null,
            (new FakeComponentRegistrar())
                ->withModulePath('Hyva_Checkout', '/app/vendor/hyva-themes/magento2-hyva-checkout/src')
        );

        self::assertSame('hyva-themes/magento2-hyva-checkout', $repository->getPackageName('Hyva_Checkout'));
        self::assertSame(
            ['version' => '1.3.12', 'source' => RepositoryInterface::VERSION_SOURCE_COMPOSER],
            $repository->getVersionInfo('Hyva_Checkout')
        );
    }

    #[Test]
    public function itFallsBackToTheComposerJsonVersionFieldWhenComposerDoesNotKnowThePackage(): void
    {
        $repository = $this->repository(
            (new FakePackageInfo())->withModule('Acme_Blog', 'acme/module-blog', '2.4.0'),
            (new FakeModuleDeclarationLoader())->withModule('Acme_Blog'),
            new FakeInstalledPackages()
        );

        self::assertSame(
            ['version' => '2.4.0', 'source' => RepositoryInterface::VERSION_SOURCE_COMPOSER_JSON],
            $repository->getVersionInfo('Acme_Blog')
        );
    }

    #[Test]
    public function itFallsBackToTheSetupVersionForModulesWithoutAnyComposerVersion(): void
    {
        $repository = $this->repository(
            new FakePackageInfo(),
            (new FakeModuleDeclarationLoader())->withModule('Acme_Legacy', '1.2.3'),
            new FakeInstalledPackages()
        );

        self::assertSame(
            ['version' => '1.2.3', 'source' => RepositoryInterface::VERSION_SOURCE_MODULE_XML],
            $repository->getVersionInfo('Acme_Legacy')
        );
    }

    #[Test]
    public function itReportsAnUnknownVersionWhenNoSourceHasOne(): void
    {
        $repository = $this->repository(
            (new FakePackageInfo())->withModule('Acme_Bare', 'acme/module-bare'),
            (new FakeModuleDeclarationLoader())->withModule('Acme_Bare'),
            new FakeInstalledPackages()
        );

        self::assertSame(
            ['version' => '', 'source' => RepositoryInterface::VERSION_SOURCE_UNKNOWN],
            $repository->getVersionInfo('Acme_Bare')
        );
    }

    #[Test]
    public function itMatchesAModuleOnItsComposerPackageName(): void
    {
        $repository = $this->repository(
            (new FakePackageInfo())->withModule('Hyva_Checkout', 'hyva-themes/magento2-hyva-checkout'),
            (new FakeModuleDeclarationLoader())->withModule('Hyva_Checkout')->withModule('Magento_Checkout'),
            new FakeInstalledPackages()
        );

        self::assertSame(['Hyva_Checkout'], $repository->findModuleNames('hyva themes checkout'));
    }

    #[Test]
    public function itReportsWhetherAModuleIsEnabled(): void
    {
        $repository = $this->repository(
            new FakePackageInfo(),
            (new FakeModuleDeclarationLoader())->withModule('Acme_Disabled')->withModule('Acme_Enabled'),
            new FakeInstalledPackages(),
            (new FakeModuleList())->withEnabledModule('Acme_Enabled')
        );

        self::assertTrue($repository->isEnabled('Acme_Enabled'));
        self::assertFalse($repository->isEnabled('Acme_Disabled'));
    }

    private function repository(
        FakePackageInfo $packageInfo,
        FakeModuleDeclarationLoader $moduleDeclarationLoader,
        FakeInstalledPackages $installedPackages,
        ?FakeModuleList $moduleList = null,
        ?FakeComponentRegistrar $componentRegistrar = null
    ): Repository {
        return new Repository(
            $packageInfo,
            $moduleDeclarationLoader,
            $moduleList ?? new FakeModuleList(),
            $installedPackages,
            $componentRegistrar ?? new FakeComponentRegistrar()
        );
    }
}
