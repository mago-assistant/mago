<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\CategoryData;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\ResultLimit;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class EmptyCategoriesAction implements ActionInterface
{
    private const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly EmptyCategoriesReport $report,
        private readonly StoreManagerInterface $storeManager,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'empty_categories';
    }

    public function getDescription(): string
    {
        return 'Enabled categories that show no products in a store view: no products assigned, or only '
            . 'products that are disabled, not visible in the catalog (search-only products do not count), not '
            . 'in the website, or out of stock while the store hides out-of-stock products. Anchor categories '
            . 'count the products of their enabled subcategories. Checks every active store view, or the one '
            . 'given by store';
    }

    public function getParameterSchema(): array
    {
        return [
            'store' => [
                'type' => 'string',
                'description' => 'empty_categories: store view code to check. Omit for every active store view.',
            ],
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => ResultLimit::MAX,
                'description' => 'Maximum number of categories to list. Default 50.',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'store_code' => [PiiClass::PUBLIC],
            'store_name' => [PiiClass::PUBLIC],
            'active_categories' => [PiiClass::PUBLIC],
            'empty_categories' => [PiiClass::PUBLIC],
            'empty_category_count' => [PiiClass::PUBLIC],
            'shown' => [PiiClass::PUBLIC],
            'category_id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'path' => [PiiClass::PUBLIC],
            'level' => [PiiClass::PUBLIC],
            'is_anchor' => [PiiClass::PUBLIC],
            'display_mode' => [PiiClass::PUBLIC],
            'assigned_products' => [PiiClass::PUBLIC],
            'subcategories' => [PiiClass::PUBLIC],
            'reason' => [PiiClass::PUBLIC],
            'empty_in_stores' => [PiiClass::PUBLIC],
            'admin_url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return 'Lead with empty_category_count and the per-store totals, then a table with the category path '
            . 'linked with admin_url, reason and the store views it is empty in. A category is listed once per '
            . 'reason, so it appears twice when its store views are empty for different reasons; '
            . 'empty_category_count counts categories. Write each admin_url token '
            . 'exactly as it came back. reason "no_products_assigned" means nothing is assigned; '
            . '"no_live_products" means assigned_products exist but none is enabled, visible in the catalog and '
            . 'in the website; "out_of_stock_only" means it has such products but the store hides out-of-stock '
            . 'products and none is in stock; "static_block_only" means the category shows a static block '
            . 'instead of products, so it is probably empty on purpose: list those separately. A non-anchor '
            . 'category with subcategories may be meant as a landing page. When empty_category_count exceeds '
            . 'shown, say the table is the first part of the tree.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $stores = $this->stores(trim((string)($params['store'] ?? '')));
        if ($stores === null) {
            return ['error' => 'Unknown store view: ' . trim((string)($params['store'] ?? ''))];
        }

        $limit = ResultLimit::fromParams($params, self::DEFAULT_LIMIT);
        $storeTotals = [];
        $byCategory = [];
        foreach ($stores as $store) {
            $group = $this->storeManager->getGroup((string)$store->getStoreGroupId());
            $result = $this->report->forStore(
                (int)$store->getId(),
                (int)$store->getWebsiteId(),
                (int)$group->getRootCategoryId()
            );

            $storeTotals[] = [
                'store_code' => (string)$store->getCode(),
                'store_name' => (string)$store->getName(),
                'active_categories' => $result['active_categories'],
                'empty_categories' => count($result['empty']),
            ];
            // One row per category and reason: store views of another website can be empty for another reason.
            foreach ($result['empty'] as $row) {
                $id = $row['category_id'];
                $byCategory[$id][$row['reason']] ??= $row + ['empty_in_stores' => []];
                $byCategory[$id][$row['reason']]['empty_in_stores'][] = (string)$store->getCode();
            }
        }

        $rows = array_merge(...array_map('array_values', array_values($byCategory)));
        $shown = array_map(function (array $row): array {
            $row['empty_in_stores'] = implode(', ', $row['empty_in_stores']);
            $row['admin_url'] = $this->secureAdminUrl->getUrl('catalog/category/edit', ['id' => $row['category_id']]);
            return $row;
        }, array_slice($rows, 0, $limit));

        return [
            'stores' => $storeTotals,
            'empty_category_count' => count($byCategory),
            'shown' => count($shown),
            'categories' => $shown,
        ];
    }

    /**
     * The active store views to check, null when the requested code is not one of them.
     *
     * @return list<StoreInterface>|null
     */
    private function stores(string $code): ?array
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
}
