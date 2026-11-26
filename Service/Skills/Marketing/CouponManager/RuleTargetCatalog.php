<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Api\InternalApiClientInterface;

/**
 * What a cart rule condition can point at, read over REST as the admin: categories with their
 * path, product attributes with their promo-rule flag and options, and shipping countries.
 *
 * Results are kept for the rest of the request, because the caution card and the save that follows
 * it ask for the same names.
 */
class RuleTargetCatalog
{
    public const MAX_RESULTS = 20;

    private const ROOT_CATALOG_LEVEL = 0;
    private const PROMO_RULES_FLAG = '1';
    public const PATH_SEPARATOR = ' > ';

    /** @var array<string, array{code: string, label: string, input: string, is_promo: bool, options: array<string, string>}|null> */
    private array $attributes = [];

    /** @var array<string, string>|null */
    private ?array $countries = null;

    public function __construct(
        private readonly InternalApiClientInterface $apiClient
    ) {
    }

    /**
     * @param int[] $categoryIds
     * @return array<int, string> Category path ("Men > Tops > Tees") by id, only for ids that exist
     */
    public function categoryPaths(array $categoryIds, int $adminUserId): array
    {
        $categories = $this->categoriesById($categoryIds, $adminUserId);
        if ($categories === []) {
            return [];
        }
        $ancestorIds = array_map('intval', explode('/', implode('/', array_map(
            static fn (array $category): string => (string)($category['path'] ?? ''),
            $categories
        ))));
        $names = $this->categoryNames(
            $this->categoriesById(array_diff($ancestorIds, array_keys($categories)), $adminUserId) + $categories
        );

        return array_map(
            static fn (array $category): string => implode(self::PATH_SEPARATOR, array_filter(array_map(
                static fn (string $id): ?string => $names[(int)$id] ?? null,
                explode('/', (string)$category['path'])
            ))),
            $categories
        );
    }

    /**
     * @return list<array{id: int, name: string, path: string}>
     */
    public function findCategories(string $query, int $adminUserId): array
    {
        $result = $this->apiClient->get('categories/list', $this->apiClient->buildSearchCriteria(
            [['field' => 'name', 'value' => '%' . $query . '%', 'condition_type' => 'like']],
            self::MAX_RESULTS
        ), $adminUserId);
        $ids = array_map(
            static fn (array $category): int => (int)($category['id'] ?? 0),
            array_filter((array)($result['items'] ?? []), 'is_array')
        );
        $paths = $this->categoryPaths($ids, $adminUserId);

        return array_values(array_map(
            static fn (int $id, string $path): array => [
                'id' => $id,
                'name' => (string)array_slice(explode(self::PATH_SEPARATOR, $path), -1)[0],
                'path' => $path,
            ],
            array_keys($paths),
            $paths
        ));
    }

    /**
     * @return array<int, string> Path by id of every category with exactly this name
     */
    public function categoriesNamed(string $name, int $adminUserId): array
    {
        $result = $this->apiClient->get('categories/list', $this->apiClient->buildSearchCriteria(
            [['field' => 'name', 'value' => $name, 'condition_type' => 'eq']],
            self::MAX_RESULTS
        ), $adminUserId);
        $named = array_filter(
            array_filter((array)($result['items'] ?? []), 'is_array'),
            static fn (array $category): bool => (string)($category['name'] ?? '') === $name
        );

        return $this->categoryPaths(
            array_map(static fn (array $category): int => (int)($category['id'] ?? 0), $named),
            $adminUserId
        );
    }

    /**
     * @return array{code: string, label: string, input: string, is_promo: bool, options: array<string, string>}|null
     *         null when the attribute does not exist or cannot be read
     */
    public function attribute(string $code, int $adminUserId): ?array
    {
        if (!array_key_exists($code, $this->attributes)) {
            $this->attributes[$code] = $this->readAttribute($code, $adminUserId);
        }

        return $this->attributes[$code];
    }

    /**
     * @return list<array{code: string, label: string, input: string}>
     */
    public function findPromoAttributes(string $query, int $adminUserId): array
    {
        $result = $this->apiClient->get('products/attributes', $this->apiClient->buildSearchCriteria(
            [
                ['field' => 'is_used_for_promo_rules', 'value' => self::PROMO_RULES_FLAG, 'condition_type' => 'eq'],
                [
                    ['field' => 'attribute_code', 'value' => '%' . $query . '%', 'condition_type' => 'like'],
                    ['field' => 'frontend_label', 'value' => '%' . $query . '%', 'condition_type' => 'like'],
                ],
            ],
            self::MAX_RESULTS
        ), $adminUserId);

        return array_values(array_map(
            static fn (array $attribute): array => [
                'code' => (string)($attribute['attribute_code'] ?? ''),
                'label' => (string)($attribute['default_frontend_label'] ?? $attribute['attribute_code'] ?? ''),
                'input' => (string)($attribute['frontend_input'] ?? ''),
            ],
            array_filter((array)($result['items'] ?? []), 'is_array')
        ));
    }

    /**
     * @return array<string, string> Country name by two-letter code
     */
    public function countries(int $adminUserId): array
    {
        if ($this->countries === null) {
            $result = $this->apiClient->get('directory/countries', [], $adminUserId);
            $this->countries = isset($result['error']) ? [] : array_column(
                array_map(
                    static fn (array $country): array => [
                        'code' => strtoupper((string)($country['id'] ?? '')),
                        'name' => (string)($country['full_name_english'] ?? $country['id'] ?? ''),
                    ],
                    array_filter($result, 'is_array')
                ),
                'name',
                'code'
            );
        }

        return $this->countries;
    }

    /**
     * The root catalog is left out: every path starts there, so it says nothing.
     *
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, string>
     */
    private function categoryNames(array $categories): array
    {
        return array_map(
            static fn (array $category): string => (string)($category['name'] ?? ''),
            array_filter(
                $categories,
                static fn (array $category): bool => (int)($category['level'] ?? 0) > self::ROOT_CATALOG_LEVEL
            )
        );
    }

    /**
     * @param int[] $categoryIds
     * @return array<int, array<string, mixed>>
     */
    private function categoriesById(array $categoryIds, int $adminUserId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        if ($ids === []) {
            return [];
        }
        $result = $this->apiClient->get('categories/list', $this->apiClient->buildSearchCriteria(
            [['field' => 'entity_id', 'value' => implode(',', $ids), 'condition_type' => 'in']],
            count($ids)
        ), $adminUserId);
        $categories = array_filter((array)($result['items'] ?? []), 'is_array');

        return array_intersect_key(
            array_combine(
                array_map(static fn (array $category): int => (int)($category['id'] ?? 0), $categories),
                $categories
            ),
            array_flip($ids)
        );
    }

    /**
     * @return array{code: string, label: string, input: string, is_promo: bool, options: array<string, string>}|null
     */
    private function readAttribute(string $code, int $adminUserId): ?array
    {
        $result = $this->apiClient->get('products/attributes/' . rawurlencode($code), [], $adminUserId);
        if (isset($result['error']) || !isset($result['attribute_code'])) {
            return null;
        }
        $options = array_filter(
            array_filter((array)($result['options'] ?? []), 'is_array'),
            static fn (array $option): bool => trim((string)($option['value'] ?? '')) !== ''
        );

        return [
            'code' => (string)$result['attribute_code'],
            'label' => (string)($result['default_frontend_label'] ?? $result['attribute_code']),
            'input' => (string)($result['frontend_input'] ?? ''),
            'is_promo' => (string)($result['is_used_for_promo_rules'] ?? '') === self::PROMO_RULES_FLAG,
            'options' => array_column(
                array_map(
                    static fn (array $option): array => [
                        'value' => (string)$option['value'],
                        'label' => (string)($option['label'] ?? $option['value']),
                    ],
                    $options
                ),
                'label',
                'value'
            ),
        ];
    }
}
