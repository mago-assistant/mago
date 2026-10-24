<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Plugin\Webapi;

use MagoAssistant\Mago\Api\WebApi\ChatManagementInterface;
use MagoAssistant\Mago\Api\WebApi\IndexerManagementInterface;
use MagoAssistant\Mago\Model\Config\AssistantDisabledException;
use MagoAssistant\Mago\Model\Config\EnabledGuard;
use MagoAssistant\Mago\Plugin\Webapi\RequireAssistantEnabled;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every route in etc/webapi.xml is read from the file, so a route added there without a matching
 * before method fails here instead of quietly working with the assistant switched off.
 */
final class RequireAssistantEnabledTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routes(): array
    {
        $routes = simplexml_load_file(__DIR__ . '/../../../../etc/webapi.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $routes, 'etc/webapi.xml must parse');

        $cases = [];
        foreach ($routes->route as $route) {
            $label = (string)$route['method'] . ' ' . (string)$route['url'];
            $cases[$label] = [(string)$route->service['class'], (string)$route->service['method']];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('routes')]
    public function itRefusesTheRouteWhenTheAssistantIsDisabled(string $serviceClass, string $method): void
    {
        $plugin = $this->plugin(false);

        $this->expectException(AssistantDisabledException::class);

        $plugin->{'before' . ucfirst($method)}($this->subject($serviceClass));
    }

    #[Test]
    #[DataProvider('routes')]
    public function itLetsTheRouteThroughWhenTheAssistantIsEnabled(string $serviceClass, string $method): void
    {
        $plugin = $this->plugin(true);

        $plugin->{'before' . ucfirst($method)}($this->subject($serviceClass));

        $this->addToAssertionCount(1);
    }

    private function plugin(bool $isEnabled): RequireAssistantEnabled
    {
        return new RequireAssistantEnabled(new EnabledGuard((new FakeConfigRepository())->withEnabled($isEnabled)));
    }

    private function subject(string $serviceClass): ChatManagementInterface|IndexerManagementInterface
    {
        return match ($serviceClass) {
            ChatManagementInterface::class => new NullChatManagement(),
            IndexerManagementInterface::class => new NullIndexerManagement(),
        };
    }
}
