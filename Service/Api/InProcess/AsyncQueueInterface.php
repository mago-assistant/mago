<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;
use Magento\Framework\Exception\BulkException;

/**
 * The asynchronous web API queue of Magento_WebapiAsync, which a store may have removed.
 */
interface AsyncQueueInterface
{
    public function isAvailable(): bool;

    public function getTopicName(string $routePath, string $httpMethod): string;

    /**
     * @param array<int, mixed> $arguments The service method's arguments
     * @throws BulkException When the operation is rejected; the bulk is still created
     */
    public function publish(string $topicName, array $arguments, int $adminUserId): AsyncResponseInterface;
}
