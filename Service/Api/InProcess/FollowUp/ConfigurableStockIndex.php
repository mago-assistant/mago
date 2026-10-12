<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess\FollowUp;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;

/**
 * Linking a child to a configurable product over REST also refreshes the parent's stock index:
 * Magento_InventoryConfigurableProductIndexer registers APISourceItemIndexerPlugin in webapi_rest only.
 * Without it a configurable created from the admin chat stays out of stock on the storefront until the
 * next full reindex. Inventory is optional, so its plugin is looked up by name when the module is on.
 */
class ConfigurableStockIndex implements ServiceCallFollowUpInterface
{
    private const SERVICE_CLASS = 'Magento\ConfigurableProduct\Api\LinkManagementInterface';
    private const SERVICE_METHOD = 'addChild';
    private const INDEXER_MODULE = 'Magento_InventoryConfigurableProductIndexer';
    private const INDEXER_PLUGIN = 'Magento\InventoryConfigurableProductIndexer\Plugin\InventoryIndexer'
        . '\Indexer\SourceItem\Strategy\Sync\APISourceItemIndexerPlugin';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductResource $productResource,
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager,
        private readonly State $appState
    ) {
    }

    public function afterCall(ResolvedRoute $route, array $arguments): void
    {
        if (!$route->isServiceMethod(self::SERVICE_CLASS, self::SERVICE_METHOD) || !$this->isNeeded()) {
            return;
        }

        $parent = $this->productRepository->get((string)$arguments[0], false, null, true);
        $this->objectManager->get(self::INDEXER_PLUGIN)
            ->afterSave($this->productResource, $this->productResource, $parent);
    }

    /**
     * In webapi_rest (the chat's own REST endpoint) Magento already ran the plugin on the parent's save.
     */
    private function isNeeded(): bool
    {
        return !$this->isRestArea()
            && $this->moduleManager->isEnabled(self::INDEXER_MODULE)
            && class_exists(self::INDEXER_PLUGIN);
    }

    private function isRestArea(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_WEBAPI_REST;
        } catch (LocalizedException) {
            return false;
        }
    }
}
