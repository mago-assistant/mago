<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\ActiveStores;

/**
 * Per filter in a category: how many products have a value, which options are used, which are empty,
 * and which attributes not yet a filter would make a good one
 */
class AuditFiltersAction implements ActionInterface
{
    /** Under this share of products with a value, a filter hides most of the category */
    public const LOW_FILL_RATE = 20;

    /** A candidate filter needs at least this share filled ... */
    public const CANDIDATE_FILL_RATE = 50;

    /** ... and between 2 and this many options in use */
    public const CANDIDATE_MAX_OPTIONS = 30;

    /** Empty options listed per filter */
    private const EMPTY_OPTIONS_SHOWN = 10;

    public function __construct(
        private readonly FilterAttributes $filterAttributes,
        private readonly ActiveStores $activeStores,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getName(): string
    {
        return 'audit_filters';
    }

    public function getDescription(): string
    {
        return 'Check the filters of one category: per filter the share of products with a value, options in use '
            . 'and options without products there, useless filters flagged, and attributes that would make good '
            . 'filters but are not enabled';
    }

    public function getParameterSchema(): array
    {
        return [
            'category' => [
                'type' => 'string',
                'description' => 'audit_filters: category id or exact category name',
            ],
            'store' => [
                'type' => 'string',
                'description' => 'audit_filters: store view code; default the default store view',
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
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getInstructions(): string
    {
        return 'Lead with the category, store view and product_count. Then a table of the filters: label, '
            . 'fill_rate (%), options_used of options_total, flags. Flags: "single_option" (one choice: at a '
            . 'fill_rate of 100 it narrows nothing and Magento hides it, below 100 it still narrows to the products '
            . 'that have it), "low_fill" (under 20% of the products have a value, so picking it hides most of the '
            . 'category), "unused" (no product here has a value, so it does not show here). empty_options are '
            . 'options no product here has: with filterable "with_results" they do not show in this category, '
            . 'which answers "why does filter X not show option Y". A label both in top_options and in '
            . 'empty_options is a duplicate option: suggest cleanup_options. Then the candidates. Counts follow '
            . 'the storefront: enabled products visible in the catalog, a configurable counting when it or a '
            . 'variant has the value. Stock is not taken into account.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $store = $this->store(trim((string)($params['store'] ?? '')));
        if ($store === null) {
            return ['error' => 'Unknown or inactive store view: ' . trim((string)($params['store'] ?? ''))];
        }
        $storeId = (int)$store->getId();
        $category = $this->category(trim((string)($params['category'] ?? '')), $storeId);
        if (isset($category['error'])) {
            return $category;
        }

        $productIds = $this->filterAttributes->categoryProducts($category['id'], $storeId);
        $result = [
            'category' => $category['name'],
            'category_id' => $category['id'],
            'store_code' => (string)$store->getCode(),
            'product_count' => count($productIds),
        ];
        if ($productIds === []) {
            return $result + [
                'message' => 'No enabled, visible products in this category in this store view (or the category '
                    . 'product index is not built yet).',
            ];
        }

        $attributes = $this->filterAttributes->attributes(false);
        $children = $this->filterAttributes->children($productIds);
        $allIds = array_values(array_unique(array_merge($productIds, ...array_values($children))));
        $values = $this->filterAttributes->values($attributes, $allIds, $storeId);

        $filters = [];
        $candidates = [];
        foreach ($attributes as $attributeId => $attribute) {
            $isFilter = $attribute['is_filterable'] > 0;
            if (!$isFilter && !$this->couldBeFilter($attribute)) {
                continue;
            }
            $usage = $this->usage($productIds, $children, $values[$attributeId] ?? []);
            $fillRate = (int)round(100 * $usage['with_value'] / count($productIds));
            if ($isFilter) {
                $filters[] = $this->describeFilter($attribute, $usage, $fillRate, $storeId);
                continue;
            }
            $optionsUsed = count($usage['per_option']);
            $isCandidate = $fillRate >= self::CANDIDATE_FILL_RATE
                && $optionsUsed >= 2
                && $optionsUsed <= self::CANDIDATE_MAX_OPTIONS;
            if ($isCandidate) {
                $candidates[] = [
                    'label' => $attribute['label'],
                    'code' => $attribute['code'],
                    'fill_rate' => $fillRate,
                    'options_used' => $optionsUsed,
                ];
            }
        }
        usort($candidates, static fn (array $a, array $b) => $b['fill_rate'] <=> $a['fill_rate']);

        return $result + ['filters' => $filters, 'candidates' => $candidates];
    }

    /**
     * Per product in the category, the values it or its variants carry; then per option, how many
     *
     * @param int[] $productIds
     * @param array<int, int[]> $children
     * @param array<int, string[]> $values Product entity id => values
     * @return array{with_value: int, per_option: array<string, int>}
     */
    private function usage(array $productIds, array $children, array $values): array
    {
        $withValue = 0;
        $perOption = [];
        foreach ($productIds as $productId) {
            $own = array_unique(array_merge(
                $values[$productId] ?? [],
                ...array_map(static fn (int $childId) => $values[$childId] ?? [], $children[$productId] ?? [])
            ));
            if ($own === []) {
                continue;
            }
            $withValue++;
            foreach ($own as $value) {
                $perOption[$value] = ($perOption[$value] ?? 0) + 1;
            }
        }

        return ['with_value' => $withValue, 'per_option' => $perOption];
    }

    /**
     * @param array $attribute
     * @param array{with_value: int, per_option: array<string, int>} $usage
     * @param int $fillRate
     * @param int $storeId
     * @return array
     */
    private function describeFilter(array $attribute, array $usage, int $fillRate, int $storeId): array
    {
        $filter = [
            'label' => $attribute['label'],
            'code' => $attribute['code'],
            'filterable' => ListFiltersAction::FILTERABLE[$attribute['is_filterable']] ?? 'no',
            'position' => $attribute['position'],
            'products_with_value' => $usage['with_value'],
            'fill_rate' => $fillRate,
        ];
        $flags = [];
        if ($usage['with_value'] === 0) {
            $flags[] = 'unused';
        } elseif ($fillRate < self::LOW_FILL_RATE) {
            $flags[] = 'low_fill';
        }

        $options = $this->filterAttributes->options($attribute, $storeId);
        if ($options !== []) {
            $used = array_intersect_key($usage['per_option'], array_flip(array_map('strval', array_keys($options))));
            $empty = array_diff_key($options, $used);
            $filter['options_used'] = count($used);
            $filter['options_total'] = count($options);
            if (count($used) === 1) {
                $flags[] = 'single_option';
            }
            // An unused filter's empty options are all its options; the count says enough.
            if ($empty !== [] && $used !== []) {
                $filter['empty_options'] = array_values(array_slice($empty, 0, self::EMPTY_OPTIONS_SHOWN));
            }
            if ($empty !== []) {
                $filter['empty_option_count'] = count($empty);
            }
            arsort($used);
            $filter['top_options'] = array_map(
                static fn ($optionId, int $count) => ['option' => $options[(int)$optionId], 'products' => $count],
                array_keys(array_slice($used, 0, 5, true)),
                array_slice($used, 0, 5, true)
            );
        }
        $filter['flags'] = $flags;

        return $filter;
    }

    /**
     * An attribute worth checking as a new filter: the merchant's own, with options a shopper can pick
     *
     * @param array $attribute
     * @return bool
     */
    private function couldBeFilter(array $attribute): bool
    {
        return $attribute['is_user_defined']
            && in_array($attribute['input'], array_diff(FilterAttributes::FILTERABLE_INPUTS, ['price']), true);
    }

    private function store(string $code): ?StoreInterface
    {
        if ($code === '') {
            return $this->storeManager->getDefaultStoreView();
        }

        return $this->activeStores->select($code)[0] ?? null;
    }

    /**
     * The category named by id or exact name
     *
     * @param string $reference
     * @param int $storeId
     * @return array{id: int, name: string}|array{error: string}
     */
    private function category(string $reference, int $storeId): array
    {
        if ($reference === '') {
            return ['error' => 'audit_filters needs a category: its id or exact name.'];
        }
        $matches = $this->filterAttributes->findCategories($reference, $storeId);
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches === []) {
            return ['error' => sprintf('There is no category "%s".', $reference)];
        }

        return ['error' => sprintf(
            'Several categories are called "%s": ids %s. Ask which one.',
            $reference,
            implode(', ', array_column($matches, 'id'))
        )];
    }
}
