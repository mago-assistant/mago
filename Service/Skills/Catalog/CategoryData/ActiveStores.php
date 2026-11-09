<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\CategoryData;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The active store views a category_data action checks, and the root category each one shows.
 */
class ActiveStores
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Every active store view, or the one with the given code; null when the code is not one of them.
     *
     * @return list<StoreInterface>|null
     */
    public function select(string $code): ?array
    {
        $stores = array_values(array_filter(
            $this->storeManager->getStores(),
            static fn (StoreInterface $store): bool => (bool)$store->getIsActive()
        ));
        if ($code === '') {
            return $stores;
        }

        $matching = array_values(array_filter(
            $stores,
            static fn (StoreInterface $store): bool => strcasecmp((string)$store->getCode(), $code) === 0
        ));

        return $matching === [] ? null : $matching;
    }

    public function rootCategoryId(StoreInterface $store): int
    {
        return (int)$this->storeManager->getGroup((string)$store->getStoreGroupId())->getRootCategoryId();
    }
}
