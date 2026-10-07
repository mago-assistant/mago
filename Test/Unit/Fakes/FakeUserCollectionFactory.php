<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\User\Model\ResourceModel\User\CollectionFactory;

/**
 * Hands out a FakeUserCollection over the given active user ids and counts the collections, each one
 * being a query in Magento.
 */
final class FakeUserCollectionFactory extends CollectionFactory
{
    private int $created = 0;

    /**
     * @param int[] $activeUserIds
     */
    public function __construct(
        private readonly array $activeUserIds
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data = []): FakeUserCollection
    {
        $this->created++;

        return new FakeUserCollection($this->activeUserIds);
    }

    public function queries(): int
    {
        return $this->created;
    }
}
