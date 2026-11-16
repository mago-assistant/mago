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

/**
 * Finds categories whose name contains a word, in the names each store view shows
 */
class SearchCategoriesAction implements ActionInterface
{
    private const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly CategoryTree $categoryTree,
        private readonly EmptyCategoriesReport $report,
        private readonly ActiveStores $activeStores,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'search_categories';
    }

    public function getDescription(): string
    {
        return 'Find categories by part of their name, case-insensitive, with their path, whether they are enabled '
            . 'and how many products they hold';
    }

    public function getParameterSchema(): array
    {
        return [
            'names' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'search_categories: words to look for in the category name; a category matching '
                    . 'any of them is listed, e.g. ["hoodies", "bras"]',
            ],
            'store' => [
                'type' => 'string',
                'description' => 'Store view code to check. Omit for every active store view.',
            ],
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => ResultLimit::MAX,
                'description' => 'Maximum number of rows to list. Default 50.',
            ],
        ];
    }

    /**
     * The skill's category resource covers it; product counts are totals, as empty_categories shows them
     */
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
            'names' => [PiiClass::PUBLIC],
            'match_count' => [PiiClass::PUBLIC],
            'shown' => [PiiClass::PUBLIC],
            'category_id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'path' => [PiiClass::PUBLIC],
            'level' => [PiiClass::PUBLIC],
            'is_active' => [PiiClass::PUBLIC],
            'is_anchor' => [PiiClass::PUBLIC],
            'assigned_products' => [PiiClass::PUBLIC],
            'live_products' => [PiiClass::PUBLIC],
            'store_codes' => [PiiClass::PUBLIC],
            'admin_url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return 'Show the categories as entity cards or a table: path (linked with admin_url), enabled or not, '
            . 'live_products. Write each admin_url token exactly as it came back. assigned_products counts every '
            . 'product assigned to the category itself; live_products only the enabled ones visible in the '
            . 'catalog; an anchor category also shows its subcategories\' products on the storefront. A category '
            . 'is listed once, with the names of the first store view it matched in; store_codes says where its '
            . 'name matched. When match_count exceeds shown, say the list is cut.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $words = array_values(array_filter(array_map(
            static fn ($word) => trim((string)$word),
            is_array($params['names'] ?? null) ? $params['names'] : [(string)($params['names'] ?? '')]
        ), static fn (string $word) => $word !== ''));
        if ($words === []) {
            return ['error' => 'search_categories needs "names": one or more words to look for.'];
        }
        $stores = $this->activeStores->select(trim((string)($params['store'] ?? '')));
        if ($stores === null) {
            return ['error' => 'Unknown store view: ' . trim((string)($params['store'] ?? ''))];
        }

        /** @var array<int, array<string, mixed>> $matches Category id => row, first store view first */
        $matches = [];
        foreach ($stores as $store) {
            $storeId = (int)$store->getId();
            $rootId = $this->activeStores->rootCategoryId($store);
            $categories = $this->categoryTree->below($storeId, $rootId);
            $found = array_filter(
                $categories,
                fn (array $category) => $this->matches($category['name'], $words)
            );
            if ($found === []) {
                continue;
            }
            $counts = $this->report->productCountsForStore($storeId, (int)$store->getWebsiteId(), $rootId);
            foreach ($found as $id => $category) {
                if (isset($matches[$id])) {
                    $matches[$id]['store_codes'][] = (string)$store->getCode();
                    continue;
                }
                $matches[$id] = [
                    'category_id' => $id,
                    'name' => $category['name'],
                    'path' => EmptyCategoriesReport::breadcrumb($categories, $category['path'], $id, $rootId),
                    'level' => $category['level'],
                    'is_active' => $category['is_active'],
                    'is_anchor' => $category['is_anchor'],
                    'assigned_products' => $counts['assigned'][$id] ?? 0,
                    'live_products' => $counts['live'][$id] ?? 0,
                    'store_codes' => [(string)$store->getCode()],
                ];
            }
        }

        $limit = ResultLimit::fromParams($params, self::DEFAULT_LIMIT);
        $shown = array_map(function (array $row): array {
            $row['store_codes'] = implode(', ', $row['store_codes']);
            $row['admin_url'] = $this->secureAdminUrl->getUrl('catalog/category/edit', ['id' => $row['category_id']]);
            return $row;
        }, array_slice(array_values($matches), 0, $limit));

        return [
            'names' => $words,
            'match_count' => count($matches),
            'shown' => count($shown),
            'categories' => $shown,
        ];
    }

    /**
     * Whether the name contains any of the words, ignoring case
     *
     * @param string $name
     * @param string[] $words
     * @return bool
     */
    private function matches(string $name, array $words): bool
    {
        foreach ($words as $word) {
            if (mb_stripos($name, $word) !== false) {
                return true;
            }
        }

        return false;
    }
}
