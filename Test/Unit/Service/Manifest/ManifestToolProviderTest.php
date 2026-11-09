<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Manifest;

use MagoAssistant\Mago\Model\Manifest\Config;
use MagoAssistant\Mago\Service\Manifest\ManifestTool;
use MagoAssistant\Mago\Service\Manifest\ManifestToolFactory;
use MagoAssistant\Mago\Service\Manifest\ManifestToolProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManifestToolProviderTest extends TestCase
{
    #[Test]
    public function itMakesOneToolPerDeclaredDefinition(): void
    {
        $faq = ['name' => 'faq_manager', 'actions' => []];
        $rma = ['name' => 'rma_manager', 'actions' => []];
        $config = $this->createStub(Config::class);
        $config->method('getTools')->willReturn(['faq_manager' => $faq, 'rma_manager' => $rma]);

        $made = [];
        $factory = $this->createMock(ManifestToolFactory::class);
        $factory->expects(self::exactly(2))->method('create')->willReturnCallback(
            function (array $definition) use (&$made): ManifestTool {
                $tool = $this->createStub(ManifestTool::class);
                $tool->method('getName')->willReturn($definition['name']);
                $made[] = $definition['name'];

                return $tool;
            }
        );

        $tools = (new ManifestToolProvider($config, $factory))->getTools();

        self::assertSame(['faq_manager', 'rma_manager'], $made);
        self::assertSame(
            ['faq_manager', 'rma_manager'],
            array_map(static fn (ManifestTool $tool): string => $tool->getName(), $tools)
        );
    }

    #[Test]
    public function noManifestsMeansNoTools(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getTools')->willReturn([]);
        $factory = $this->createMock(ManifestToolFactory::class);
        $factory->expects(self::never())->method('create');

        self::assertSame([], (new ManifestToolProvider($config, $factory))->getTools());
    }
}
