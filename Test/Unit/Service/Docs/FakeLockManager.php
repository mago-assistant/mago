<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Docs;

use Magento\Framework\Lock\LockManagerInterface;

/**
 * An in-memory lock that is always free at the start of a test
 */
final class FakeLockManager implements LockManagerInterface
{
    /** @var array<string, true> */
    private array $locks = [];

    public function lock(string $name, int $timeout = -1): bool
    {
        if (isset($this->locks[$name])) {
            return false;
        }
        $this->locks[$name] = true;

        return true;
    }

    public function unlock(string $name): bool
    {
        unset($this->locks[$name]);

        return true;
    }

    public function isLocked(string $name): bool
    {
        return isset($this->locks[$name]);
    }
}
