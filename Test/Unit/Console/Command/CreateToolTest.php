<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Filesystem\Directory\WriteFactory;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverPool;
use MagoAssistant\Mago\Console\Command\CreateTool;
use MagoAssistant\Mago\Service\Tool\Scaffold\ToolScaffolder;
use MagoAssistant\Mago\Service\Tool\Verify\AclResourceIndex;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclResourceProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateToolTest extends TestCase
{
    private const ACL = 'Magento_Sales::actions_view';
    private const MODULE_DIR = '/app/code/MagoAssistant/Vies';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mago-create-tool-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        (new File())->deleteDirectory($this->root);
    }

    #[Test]
    public function itWritesTheModuleUnderThePackagePath(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute($this->input());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($this->root . self::MODULE_DIR . '/etc/di.xml');
        self::assertStringContainsString(
            "return '" . self::ACL . "';",
            (string)file_get_contents($this->root . self::MODULE_DIR . '/Service/Tool/VatCheck.php')
        );
        self::assertStringContainsString('bin/magento mago:tool:verify vies_vat_check', $tester->getDisplay());
    }

    #[Test]
    public function itNeedsNoComposerStepsForAModuleInAppCode(): void
    {
        $tester = $this->tester();

        $tester->execute($this->input());

        self::assertStringContainsString('bin/magento module:enable MagoAssistant_Vies', $tester->getDisplay());
        self::assertStringNotContainsString('composer config', $tester->getDisplay());
        self::assertStringNotContainsString('composer require', $tester->getDisplay());
    }

    #[Test]
    public function itWritesToAnotherDirectoryAndInstallsItAsAPathRepository(): void
    {
        $tester = $this->tester();

        $tester->execute(['--path' => 'package-source/magento2-vies/'] + $this->input());

        self::assertFileExists($this->root . '/package-source/magento2-vies/etc/di.xml');
        self::assertDirectoryDoesNotExist($this->root . '/app');
        self::assertStringContainsString(
            'composer config repositories.mago-assistant-magento2-vies'
                . ' \'{"type": "path", "url": "package-source/magento2-vies"}\'',
            $tester->getDisplay()
        );
        self::assertStringContainsString('composer require mago-assistant/magento2-vies:@dev', $tester->getDisplay());
    }

    #[Test]
    public function itNeverOverwritesAnExistingModule(): void
    {
        mkdir($this->root . self::MODULE_DIR, 0777, true);
        file_put_contents($this->root . self::MODULE_DIR . '/composer.json', 'mine');

        $exitCode = $this->tester()->execute($this->input());

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('mine', file_get_contents($this->root . self::MODULE_DIR . '/composer.json'));
    }

    #[Test]
    public function itRequiresTheAclAndTheAccessMode(): void
    {
        $exitCode = $this->tester()->execute(['tool' => 'vies_vat_check', 'module' => 'MagoAssistant_Vies']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertDirectoryDoesNotExist($this->root . '/app');
    }

    #[Test]
    public function itRejectsAPathOutsideTheMagentoRoot(): void
    {
        $exitCode = $this->tester()->execute($this->input() + ['--path' => '../elsewhere']);

        self::assertSame(Command::INVALID, $exitCode);
    }

    #[Test]
    public function itWarnsWhenAForeignAclResourceIsNotDeclared(): void
    {
        $tester = $this->tester();

        $tester->execute(['--acl' => 'Magento_Sales::action_view'] + $this->input());

        self::assertStringContainsString(
            'ACL resource Magento_Sales::action_view is not declared in any acl.xml',
            $tester->getDisplay()
        );
    }

    #[Test]
    public function itDeclaresAnAclResourceOfTheNewModuleInsteadOfWarning(): void
    {
        $tester = $this->tester();

        $tester->execute(['--acl' => 'MagoAssistant_Vies::check'] + $this->input());

        self::assertFileExists($this->root . self::MODULE_DIR . '/etc/acl.xml');
        self::assertStringNotContainsString('is not declared', $tester->getDisplay());
    }

    /**
     * @return array<string,string>
     */
    private function input(): array
    {
        return [
            'tool' => 'vies_vat_check',
            'module' => 'MagoAssistant_Vies',
            '--acl' => self::ACL,
            '--access' => 'read',
        ];
    }

    private function tester(): CommandTester
    {
        $driverPool = new DriverPool();

        return new CommandTester(new CreateTool(
            new ToolScaffolder(new File()),
            new AclResourceIndex(FakeAclResourceProvider::withResources(self::ACL)),
            new Filesystem(new DirectoryList($this->root), new ReadFactory($driverPool), new WriteFactory($driverPool))
        ));
    }
}
