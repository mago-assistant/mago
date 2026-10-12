<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Config\Model\Config\Structure\ElementInterface;

/**
 * A system.xml element as Structure hands it out: its data array, with a section's ACL resource
 * under "resource" when it declares one.
 */
final class FakeConfigStructureElement implements ElementInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    public function setData(array $data, $scope): void
    {
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getId(): string
    {
        return (string)($this->data['id'] ?? '');
    }

    public function getLabel(): string
    {
        return (string)($this->data['label'] ?? '');
    }

    public function isVisible(): bool
    {
        return true;
    }

    public function getAttribute($key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
