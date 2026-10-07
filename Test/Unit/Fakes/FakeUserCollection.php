<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\User\Model\ResourceModel\User\Collection;

/**
 * The admin user table as a list of active user ids: filtered on user_id and is_active, its size is
 * the number of active users that match.
 */
final class FakeUserCollection extends Collection
{
    private const USER_ID = 'user_id';
    private const IS_ACTIVE = 'is_active';
    private const EQUALS = 'eq';

    /** @var array<string, mixed> */
    private array $filters = [];

    /**
     * @param int[] $activeUserIds
     */
    public function __construct(
        private readonly array $activeUserIds
    ) {
    }

    /**
     * @param string $field
     * @param mixed $condition
     */
    public function addFieldToFilter($field, $condition = null): self
    {
        $this->filters[$field] = is_array($condition) ? ($condition[self::EQUALS] ?? null) : $condition;

        return $this;
    }

    public function getSize(): int
    {
        $userId = $this->filters[self::USER_ID] ?? null;
        $isActive = (int)($this->filters[self::IS_ACTIVE] ?? 0) === 1;

        return $isActive && in_array((int)$userId, $this->activeUserIds, true) ? 1 : 0;
    }
}
