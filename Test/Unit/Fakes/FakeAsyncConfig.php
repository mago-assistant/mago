<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\WebapiAsync\Model\Config;

/**
 * Names a route's topic the way Magento_WebapiAsync does: async.<service>.<method>.<http method>.
 */
final class FakeAsyncConfig extends Config
{
    /**
     * @param array<string, string> $topicsByRoute keyed by "<HTTP method> <route>"
     */
    public function __construct(
        private readonly array $topicsByRoute
    ) {
    }

    public function getTopicName($routeUrl, $httpMethod)
    {
        return $this->topicsByRoute[$httpMethod . ' ' . $routeUrl] ?? throw new \OutOfBoundsException($routeUrl);
    }
}
