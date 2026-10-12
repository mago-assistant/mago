<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\ObjectManagerInterface;

/**
 * Hands out the instances it was given, by class name.
 */
final class FakeObjectManager implements ObjectManagerInterface
{
    /**
     * @param array<string, object> $instances
     */
    public function __construct(
        private readonly array $instances
    ) {
    }

    public function create($type, array $arguments = [])
    {
        return $this->get($type);
    }

    public function get($type)
    {
        return $this->instances[$type] ?? throw new \OutOfBoundsException('No instance of ' . $type);
    }

    public function configure(array $configuration)
    {
    }
}
