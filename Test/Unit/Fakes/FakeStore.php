<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Store\Api\Data\StoreExtensionInterface;
use Magento\Store\Api\Data\StoreInterface;

final class FakeStore implements StoreInterface
{
    public function __construct(
        private int $id,
        private string $code,
        private string $name = '',
        private int $websiteId = 1,
        private int $storeGroupId = 1,
        private bool $isActive = true
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId($id): self
    {
        $this->id = (int)$id;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode($code): self
    {
        $this->code = (string)$code;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName($name): self
    {
        $this->name = (string)$name;

        return $this;
    }

    public function getWebsiteId(): int
    {
        return $this->websiteId;
    }

    public function setWebsiteId($websiteId): self
    {
        $this->websiteId = (int)$websiteId;

        return $this;
    }

    public function getStoreGroupId(): int
    {
        return $this->storeGroupId;
    }

    public function setIsActive($isActive): self
    {
        $this->isActive = (bool)$isActive;

        return $this;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setStoreGroupId($storeGroupId): self
    {
        $this->storeGroupId = (int)$storeGroupId;

        return $this;
    }

    public function getExtensionAttributes(): ?StoreExtensionInterface
    {
        return null;
    }

    public function setExtensionAttributes(StoreExtensionInterface $extensionAttributes): self
    {
        return $this;
    }
}
