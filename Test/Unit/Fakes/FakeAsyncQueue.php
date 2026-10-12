<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;
use MagoAssistant\Mago\Service\Api\InProcess\AsyncQueueInterface;

/**
 * Records what was queued and answers with a fixed bulk, unless told the modules are missing or the
 * publish fails.
 */
final class FakeAsyncQueue implements AsyncQueueInterface
{
    public const BULK_UUID = 'bulk-uuid-1';
    public const TOPIC_PREFIX = 'async.';

    /** @var list<array{topic: string, arguments: array<int, mixed>, admin_user_id: int}> */
    private array $published = [];
    private bool $isAvailable = true;
    private ?\Throwable $failure = null;
    private ?FakeConnectionTransaction $transaction = null;

    public function givenModulesMissing(): self
    {
        $this->isAvailable = false;

        return $this;
    }

    /**
     * The publish opens a transaction on the given connection, then fails before it can commit.
     */
    public function givenPublishFails(\Throwable $failure, FakeConnectionTransaction $transaction): self
    {
        $this->failure = $failure;
        $this->transaction = $transaction;

        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->isAvailable;
    }

    public function getTopicName(string $routePath, string $httpMethod): string
    {
        return self::TOPIC_PREFIX . $routePath . '.' . $httpMethod;
    }

    public function publish(string $topicName, array $arguments, int $adminUserId): AsyncResponseInterface
    {
        $this->published[] = ['topic' => $topicName, 'arguments' => $arguments, 'admin_user_id' => $adminUserId];
        $this->transaction?->begin();
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new FakeAsyncResponse(self::BULK_UUID);
    }

    /**
     * @return list<array{topic: string, arguments: array<int, mixed>, admin_user_id: int}>
     */
    public function published(): array
    {
        return $this->published;
    }
}
