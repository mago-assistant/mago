<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\CategoryData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\ResultLimit;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class UncategorizedProductsAction implements ActionInterface
{
    private const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly UncategorizedProductsReport $report,
        private readonly ActiveStores $activeStores,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'uncategorized_products';
    }

    public function getDescription(): string
    {
        return 'Products no enabled category leads to in a store view: no category at all, only the root '
            . 'category or categories of another store root, or only disabled categories. By default only '
            . 'live products (enabled, in the website, visible in the catalog), the ones missing from the '
            . 'menu; only_live false checks every product. Checks every active store view, or the one given '
            . 'by store';
    }

    public function getParameterSchema(): array
    {
        return [
            'store' => [
                'type' => 'string',
                'description' => 'Store view code to check. Omit for every active store view.',
            ],
            'only_live' => [
                'type' => 'boolean',
                'description' => 'uncategorized_products: only enabled products that are in the website and '
                    . 'visible in the catalog. Default true; false also counts disabled and not visible products.',
            ],
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => ResultLimit::MAX,
                'description' => 'Maximum number of rows to list. Default 50.',
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
            'only_live' => [PiiClass::PUBLIC],
            'store_code' => [PiiClass::PUBLIC],
            'store_name' => [PiiClass::PUBLIC],
            'product_count' => [PiiClass::PUBLIC],
            'shown' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'visibility' => [PiiClass::PUBLIC],
            'reason' => [PiiClass::PUBLIC],
            'missing_in_stores' => [PiiClass::PUBLIC],
            'admin_url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return 'Lead with product_count and the per-store totals, then a table with SKU linked with admin_url, '
            . 'name, reason and the store views. Write each admin_url token exactly as it came back. reason '
            . '"no_category" means no category at all; "root_only" means only the root category or categories '
            . 'of another store root; "disabled_categories_only" means only categories that are disabled in that '
            . 'store view. A product is listed once per reason, so it appears twice when its store views differ. '
            . 'product_count is the highest per-store count; the per-store counts are exact. Say whether '
            . 'only_live was on. When product_count exceeds shown, '
            . 'say the table shows part of the products.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $stores = $this->activeStores->select(trim((string)($params['store'] ?? '')));
        if ($stores === null) {
            return ['error' => 'Unknown store view: ' . trim((string)($params['store'] ?? ''))];
        }

        $onlyLive = filter_var($params['only_live'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $limit = ResultLimit::fromParams($params, self::DEFAULT_LIMIT);
        $storeTotals = [];
        /** @var array<int,array<string,list<string>>> $byProduct product id => reason => store codes */
        $byProduct = [];
        foreach ($stores as $store) {
            $result = $this->report->forStore(
                (int)$store->getId(),
                (int)$store->getWebsiteId(),
                $this->activeStores->rootCategoryId($store),
                $onlyLive,
                $limit
            );

            $storeTotals[] = [
                'store_code' => (string)$store->getCode(),
                'store_name' => (string)$store->getName(),
                'product_count' => $result['count'],
            ];
            foreach ($result['reasons'] as $productId => $reason) {
                $byProduct[$productId][$reason][] = (string)$store->getCode();
            }
        }

        $rows = [];
        foreach ($byProduct as $productId => $reasons) {
            foreach ($reasons as $reason => $storeCodes) {
                $rows[] = ['product_id' => $productId, 'reason' => $reason, 'stores' => $storeCodes];
            }
        }
        $rows = array_slice($rows, 0, $limit);

        // Names and status as the first checked store view shows them.
        $details = $rows === [] ? [] : $this->report->details(
            array_values(array_unique(array_column($rows, 'product_id'))),
            (int)$stores[0]->getId()
        );
        $shown = [];
        foreach ($rows as $row) {
            $product = $details[$row['product_id']] ?? null;
            if ($product === null) {
                continue;
            }
            $shown[] = $product + [
                'reason' => $row['reason'],
                'missing_in_stores' => implode(', ', $row['stores']),
                'admin_url' => $this->secureAdminUrl->getUrl('catalog/product/edit', ['id' => $row['product_id']]),
            ];
        }

        return [
            'only_live' => $onlyLive,
            'stores' => $storeTotals,
            // Each store view is counted, not every product id loaded: the store view with the most.
            'product_count' => max([0, ...array_column($storeTotals, 'product_count')]),
            'shown' => count($shown),
            'products' => $shown,
        ];
    }
}
