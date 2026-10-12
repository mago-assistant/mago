<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\AsynchronousOperations\Model\MassSchedule;

/**
 * Records every publishMass() call instead of creating a bulk.
 */
final class FakeMassSchedule extends MassSchedule
{
    /** @var list<array{topic: string, entities: array<int, mixed>, group_id: mixed, user_id: mixed}> */
    private array $published = [];

    public function __construct()
    {
    }

    public function publishMass($topicName, array $entitiesArray, $groupId = null, $userId = null)
    {
        $this->published[] = [
            'topic' => $topicName,
            'entities' => $entitiesArray,
            'group_id' => $groupId,
            'user_id' => $userId,
        ];

        return new FakeAsyncResponse(FakeAsyncQueue::BULK_UUID);
    }

    /**
     * @return list<array{topic: string, entities: array<int, mixed>, group_id: mixed, user_id: mixed}>
     */
    public function published(): array
    {
        return $this->published;
    }
}
