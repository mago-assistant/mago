<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;
use Magento\AsynchronousOperations\Model\MassSchedule;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\WebapiAsync\Model\Config as AsyncConfig;

/**
 * Magento_WebapiAsync and Magento_AsynchronousOperations services, resolved when a call is queued
 * rather than injected. Stores remove these modules, and a constructor dependency on them would break
 * setup:di:compile and every tool that uses the internal API client, not only the one that queues.
 */
class WebapiAsyncQueue implements AsyncQueueInterface
{
    private const REQUIRED_MODULES = ['Magento_AsynchronousOperations', 'Magento_WebapiAsync'];

    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function isAvailable(): bool
    {
        return array_filter(
            self::REQUIRED_MODULES,
            fn (string $module): bool => !$this->moduleManager->isEnabled($module)
        ) === [];
    }

    public function getTopicName(string $routePath, string $httpMethod): string
    {
        return (string)$this->objectManager->get(AsyncConfig::class)->getTopicName($routePath, $httpMethod);
    }

    public function publish(string $topicName, array $arguments, int $adminUserId): AsyncResponseInterface
    {
        return $this->objectManager->get(MassSchedule::class)
            ->publishMass($topicName, [$arguments], null, (string)$adminUserId);
    }
}
