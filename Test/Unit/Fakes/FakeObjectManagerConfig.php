<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\ObjectManager\ConfigCacheInterface;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Framework\ObjectManager\RelationsInterface;

final class FakeObjectManagerConfig implements ConfigInterface
{
    /**
     * @param array<string, string> $virtualTypes virtual type name => the type it extends
     */
    public function __construct(
        private readonly array $virtualTypes = []
    ) {
    }

    public function setRelations(RelationsInterface $relations): void
    {
    }

    public function setCache(ConfigCacheInterface $cache): void
    {
    }

    public function getArguments($type): array
    {
        return [];
    }

    public function isShared($type): bool
    {
        return true;
    }

    public function getInstanceType($instanceName): string
    {
        return isset($this->virtualTypes[$instanceName])
            ? $this->getInstanceType($this->virtualTypes[$instanceName])
            : $instanceName;
    }

    public function getPreference($type): string
    {
        return $type;
    }

    public function getVirtualTypes(): array
    {
        return $this->virtualTypes;
    }

    public function extend(array $configuration): void
    {
    }

    public function getPreferences(): array
    {
        return [];
    }
}
