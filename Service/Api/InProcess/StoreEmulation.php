<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Runs a call in the store view its REST URL would have named, as Magento\Webapi\Controller\PathProcessor
 * does for /rest/{code}/V1/: no code means the default store view, "all" means the admin store (so
 * created entities belong to every store view), a code means that store view. The store and locale the
 * chat itself runs in are put back afterwards, also when the call fails.
 */
class StoreEmulation
{
    public const ALL_STORES_CODE = 'all';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ResolverInterface $localeResolver
    ) {
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws WebapiException When the store code is unknown
     */
    public function run(ApiCall $call, callable $operation): mixed
    {
        $store = $this->getTargetStore($call);
        $previousStoreId = (int)$this->storeManager->getStore()->getId();

        $this->storeManager->setCurrentStore((int)$store->getId());
        $this->localeResolver->emulate((int)$store->getId());
        try {
            return $operation();
        } finally {
            $this->localeResolver->revert();
            $this->storeManager->setCurrentStore($previousStoreId);
        }
    }

    private function getTargetStore(ApiCall $call): StoreInterface
    {
        if ($call->storeCode === null || $call->storeCode === '') {
            return $this->storeManager->getDefaultStoreView()
                ?? throw new WebapiException(__('The default store view is not available.'));
        }

        if ($call->storeCode === self::ALL_STORES_CODE) {
            return $this->storeManager->getStore(Store::ADMIN_CODE);
        }

        return $this->storeManager->getStores(false, true)[$call->storeCode]
            ?? throw new WebapiException(__('Specified request cannot be processed.'));
    }
}
