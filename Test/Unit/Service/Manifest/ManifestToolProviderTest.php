<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Manifest;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Model\Manifest\Config;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
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

        $tools = (new ManifestToolProvider($config, $factory, $this->reporter()))->getTools();

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

        self::assertSame([], (new ManifestToolProvider($config, $factory, $this->reporter()))->getTools());
    }

    /**
     * A malformed manifest in one module must not take the chat down for everyone: the registry is
     * built on every request, so the error is logged and the manifest tools are left out.
     */
    #[Test]
    public function aManifestThatCannotBeReadIsLoggedAndLeavesTheOtherToolsStanding(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getTools')->willThrowException(
            new \Magento\Framework\Exception\LocalizedException(__("Element 'tool': The attribute 'acl' is required"))
        );
        $factory = $this->createMock(ManifestToolFactory::class);
        $factory->expects(self::never())->method('create');
        $logger = new FakeLogger();

        $tools = (new ManifestToolProvider($config, $factory, $this->reporter($logger)))->getTools();

        self::assertSame([], $tools);
        $messages = $logger->getMessages();
        self::assertCount(1, $messages);
        self::assertStringContainsString('ManifestTools', json_encode($messages[0]) ?: '');
        self::assertStringContainsString("attribute 'acl' is required", json_encode($messages[0]) ?: '');
    }

    private function reporter(?FakeLogger $logger = null): ErrorReporter
    {
        return new ErrorReporter(new ErrorLogger($logger ?? new FakeLogger(), new Json()), new PiiHeuristic());
    }
}
