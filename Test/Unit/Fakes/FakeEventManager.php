<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Event\ManagerInterface;

/**
 * Records dispatched event names instead of running observers.
 */
final class FakeEventManager implements ManagerInterface
{
    /** @var string[] */
    private array $dispatched = [];

    public function dispatch($eventName, array $data = []): void
    {
        $this->dispatched[] = (string)$eventName;
    }

    /**
     * @return string[]
     */
    public function dispatchedEvents(): array
    {
        return $this->dispatched;
    }
}
