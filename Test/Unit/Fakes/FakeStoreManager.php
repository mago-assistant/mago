<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * An admin store (id 0, "admin") plus the store views it is given; the first one is the default.
 * Tracks the current store the way StoreManager does, so a test can see which store a call ran in.
 */
final class FakeStoreManager implements StoreManagerInterface
{
    /** @var array<int, StoreInterface> */
    private array $stores;

    private int $currentStoreId;

    public function __construct(StoreInterface ...$storeViews)
    {
        $this->stores = [Store::DEFAULT_STORE_ID => new FakeStore(Store::DEFAULT_STORE_ID, Store::ADMIN_CODE, 'Admin', 0, 0)];
        foreach ($storeViews as $storeView) {
            $this->stores[(int)$storeView->getId()] = $storeView;
        }
        $this->currentStoreId = Store::DEFAULT_STORE_ID;
    }

    public function setIsSingleStoreModeAllowed($value): void
    {
    }

    public function hasSingleStore(): bool
    {
        return count($this->stores) === 2;
    }

    public function isSingleStoreMode(): bool
    {
        return $this->hasSingleStore();
    }

    public function getStore($storeId = null): StoreInterface
    {
        if ($storeId === null) {
            return $this->stores[$this->currentStoreId];
        }

        return $this->findStore($storeId) ?? throw new NoSuchEntityException(__('Unknown store "%1"', $storeId));
    }

    public function getStores($withDefault = false, $codeKey = false): array
    {
        $stores = array_filter(
            $this->stores,
            static fn (StoreInterface $store): bool => $withDefault || (int)$store->getId() !== Store::DEFAULT_STORE_ID
        );
        if (!$codeKey) {
            return $stores;
        }

        return array_combine(
            array_map(static fn (StoreInterface $store): string => (string)$store->getCode(), $stores),
            $stores
        );
    }

    public function getWebsite($websiteId = null): never
    {
        throw new \LogicException('Not faked');
    }

    public function getWebsites($withDefault = false, $codeKey = false): array
    {
        return [];
    }

    public function reinitStores(): void
    {
    }

    public function getDefaultStoreView(): ?StoreInterface
    {
        return $this->getStores()[array_key_first($this->getStores())] ?? null;
    }

    public function getGroup($groupId = null): never
    {
        throw new \LogicException('Not faked');
    }

    public function getGroups($withDefault = false): array
    {
        return [];
    }

    public function setCurrentStore($store): void
    {
        $this->currentStoreId = (int)$this->getStore($store)->getId();
    }

    private function findStore(int|string|StoreInterface $store): ?StoreInterface
    {
        if ($store instanceof StoreInterface) {
            return $store;
        }

        $matches = array_filter(
            $this->stores,
            static fn (StoreInterface $candidate): bool => (string)$candidate->getId() === (string)$store
                || $candidate->getCode() === $store
        );

        return $matches === [] ? null : reset($matches);
    }
}
