<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Console\Command;

use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use MagoAssistant\Mago\Console\Command\CreateManifest;
use MagoAssistant\Mago\Service\Manifest\Scaffold\ManifestDrafter;
use MagoAssistant\Mago\Service\Tool\Scaffold\InvalidScaffoldException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateManifestTest extends TestCase
{
    private const DRAFT = "<?xml version=\"1.0\"?>\n<config><tool name=\"faq\"/></config>\n";

    private string $etc;

    protected function setUp(): void
    {
        $this->etc = sys_get_temp_dir() . '/mago-manifest-' . uniqid() . '/etc';
        mkdir($this->etc, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->etc . '/*') ?: []);
        rmdir($this->etc);
        rmdir(dirname($this->etc));
    }

    #[Test]
    public function itPrintsTheDraftByDefault(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(['module' => 'Vendor_Faq']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(self::DRAFT . "\n", $tester->getDisplay());
        self::assertFileDoesNotExist($this->etc . '/mago_tools.xml');
    }

    #[Test]
    public function itWritesTheDraftIntoTheModulesEtcOnRequest(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(['module' => 'Vendor_Faq', '--write' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringEqualsFile($this->etc . '/mago_tools.xml', self::DRAFT);
        self::assertStringContainsString('Created ' . $this->etc . '/mago_tools.xml', $tester->getDisplay());
        self::assertStringContainsString('bin/magento mago:tool:verify', $tester->getDisplay());
    }

    #[Test]
    public function itNeverOverwritesAnExistingManifest(): void
    {
        file_put_contents($this->etc . '/mago_tools.xml', 'keep');
        $tester = $this->tester();

        $exit = $tester->execute(['module' => 'Vendor_Faq', '--write' => true]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringEqualsFile($this->etc . '/mago_tools.xml', 'keep');
        self::assertStringContainsString('already exists; nothing was written', $tester->getDisplay());
    }

    #[Test]
    public function aDrafterRefusalIsInvalidInput(): void
    {
        $tester = $this->tester(new InvalidScaffoldException('Module must be Vendor_Module, e.g. MageOS_RMA.'));

        $exit = $tester->execute(['module' => 'faq', '--write' => true]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('Module must be Vendor_Module', $tester->getDisplay());
        self::assertFileDoesNotExist($this->etc . '/mago_tools.xml');
    }

    private function tester(?InvalidScaffoldException $refusal = null): CommandTester
    {
        $drafter = $this->createStub(ManifestDrafter::class);
        if ($refusal !== null) {
            $drafter->method('draft')->willThrowException($refusal);
        } else {
            $drafter->method('draft')->willReturn(self::DRAFT);
        }
        $reader = $this->createStub(ModuleDirReader::class);
        $reader->method('getModuleDir')->willReturn($this->etc);

        return new CommandTester(new CreateManifest($drafter, $reader, new File()));
    }
}
