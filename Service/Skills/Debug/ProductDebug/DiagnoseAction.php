<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Debug\ProductDebug;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\Store;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class DiagnoseAction implements ActionInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly StockRegistryInterface $stockRegistry,
    ) {
    }

    public function getName(): string
    {
        return 'diagnose';
    }

    public function getDescription(): string
    {
        return 'Diagnose why a product is hidden — checks status, visibility, website assignment, price, and stock per store view';
    }

    public function getParameterSchema(): array
    {
        return [
            'sku' => [
                'type' => 'string',
                'description' => 'Product SKU to diagnose',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'sku' => [PiiClass::PUBLIC],
            'product_id' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
            'website_ids' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'visibility' => [PiiClass::PUBLIC],
            'price' => [PiiClass::PUBLIC],
            'is_in_stock' => [PiiClass::PUBLIC],
            'qty' => [PiiClass::PUBLIC],
            'manage_stock' => [PiiClass::PUBLIC],
            'backorders' => [PiiClass::PUBLIC],
            'issues' => [PiiClass::PUBLIC],
            'store_id' => [PiiClass::PUBLIC],
            'store_code' => [PiiClass::PUBLIC],
            'store_name' => [PiiClass::PUBLIC],
            'website_id' => [PiiClass::PUBLIC],
            'website_assigned' => [PiiClass::PUBLIC],
            'status_overridden' => [PiiClass::PUBLIC],
            'visibility_overridden' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return <<<INSTRUCTIONS
Present results as a compact table — one row per scope (Global + each store view):

| Scope | Status | Visibility | Website | Issues |
|-------|--------|------------|---------|--------|
| Global | Enabled | Catalog+Search | — | — |
| Default Store View | Enabled | Not Visible Individually ⚠️ | ✅ | Visibility override |

Rules:
- Use ✅/❌/⚠️ icons instead of prose.
- Only list issues that are actually present. Skip empty rows.
- After the table, one line per issue: "**[Scope]** — [fix]". Be brief.
- Do not repeat information already shown in the table.
- Do not add recommendations unless the user asks.
- When the user says they fixed something (e.g. "done", "fixed it", "changed it"), immediately re-run diagnose for the same SKU and show the updated table without asking for confirmation.
INSTRUCTIONS;
    }

    public function execute(array $params, int $adminUserId): array
    {
        $sku = trim($params['sku'] ?? '');
        if ($sku === '') {
            return ['error' => 'SKU is required'];
        }

        // --- Load product in admin (global) scope ---
        try {
            $globalProduct = $this->productRepository->get($sku, false, Store::DEFAULT_STORE_ID, true);
        } catch (NoSuchEntityException) {
            return ['error' => 'Product not found: ' . $sku];
        }

        $productId = (int)$globalProduct->getId();

        // Global-scope values (the "admin" defaults before store-view overrides)
        $globalStatus = (int)$globalProduct->getStatus();
        $globalVisibility = (int)$globalProduct->getVisibility();
        $globalPrice = $globalProduct->getPrice();
        $websiteIds = $globalProduct->getWebsiteIds() ?? [];

        // Legacy stock
        $stockItem = $this->stockRegistry->getStockItem($productId);

        $stockInfo = [
            'is_in_stock' => (bool)$stockItem->getIsInStock(),
            'qty' => (float)$stockItem->getQty(),
            'manage_stock' => (bool)$stockItem->getManageStock(),
            'backorders' => (int)$stockItem->getBackorders(),
        ];

        // Global-scope issues
        $globalIssues = [];
        if ($globalStatus === Status::STATUS_DISABLED) {
            $globalIssues[] = 'Product is disabled in admin (global) scope';
        }
        if ($globalVisibility === Visibility::VISIBILITY_NOT_VISIBLE) {
            $globalIssues[] = 'Visibility is "Not Visible Individually" in admin (global) scope';
        }
        if ($globalPrice === null || (float)$globalPrice <= 0) {
            $globalIssues[] = 'Price is missing or zero';
        }
        if (!$stockItem->getIsInStock()) {
            $globalIssues[] = 'Out of stock (legacy stock)';
        }

        $result = [
            'sku' => $sku,
            'product_id' => $productId,
            'type' => $globalProduct->getTypeId(),
            'website_ids' => array_values($websiteIds),
            'global_scope' => [
                'status' => $this->statusLabel($globalStatus),
                'visibility' => $this->visibilityLabel($globalVisibility),
                'price' => $globalPrice !== null ? (float)$globalPrice : null,
                'stock' => $stockInfo,
                'issues' => $globalIssues,
            ],
            'store_views' => [],
        ];

        // --- Per-store-view breakdown ---
        $stores = $this->storeRepository->getList();
        foreach ($stores as $store) {
            $storeId = (int)$store->getId();
            if ($storeId === Store::DEFAULT_STORE_ID) {
                // Admin store — already covered by global_scope
                continue;
            }

            try {
                $storeProduct = $this->productRepository->get($sku, false, $storeId, true);
            } catch (NoSuchEntityException) {
                $result['store_views'][] = [
                    'store_id' => $storeId,
                    'store_code' => $store->getCode(),
                    'store_name' => $store->getName(),
                    'issues' => ['Product not found for this store view'],
                ];
                continue;
            }

            $storeStatus = (int)$storeProduct->getStatus();
            $storeVisibility = (int)$storeProduct->getVisibility();
            $websiteId = (int)$store->getWebsiteId();
            $assignedToWebsite = in_array((string)$websiteId, array_map('strval', $websiteIds), true);

            $issues = [];

            if (!$assignedToWebsite) {
                $issues[] = 'Product is NOT assigned to website ' . $websiteId . ' (this store\'s website)';
            }
            if ($storeStatus === Status::STATUS_DISABLED) {
                $label = $storeStatus !== $globalStatus ? ' (overridden on this store view)' : '';
                $issues[] = 'Product is disabled' . $label;
            }
            if ($storeVisibility === Visibility::VISIBILITY_NOT_VISIBLE) {
                $label = $storeVisibility !== $globalVisibility ? ' (overridden on this store view)' : '';
                $issues[] = 'Visibility is "Not Visible Individually"' . $label;
            }

            $result['store_views'][] = [
                'store_id' => $storeId,
                'store_code' => $store->getCode(),
                'store_name' => $store->getName(),
                'website_id' => $websiteId,
                'website_assigned' => $assignedToWebsite,
                'status' => $this->statusLabel($storeStatus),
                'status_overridden' => $storeStatus !== $globalStatus,
                'visibility' => $this->visibilityLabel($storeVisibility),
                'visibility_overridden' => $storeVisibility !== $globalVisibility,
                'issues' => $issues,
            ];
        }

        return $result;
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            Status::STATUS_ENABLED => 'Enabled',
            Status::STATUS_DISABLED => 'Disabled',
            default => 'Unknown (' . $status . ')',
        };
    }

    private function visibilityLabel(int $visibility): string
    {
        return match ($visibility) {
            Visibility::VISIBILITY_NOT_VISIBLE => 'Not Visible Individually',
            Visibility::VISIBILITY_IN_CATALOG => 'Catalog',
            Visibility::VISIBILITY_IN_SEARCH => 'Search',
            Visibility::VISIBILITY_BOTH => 'Catalog+Search',
            default => 'Unknown (' . $visibility . ')',
        };
    }
}
