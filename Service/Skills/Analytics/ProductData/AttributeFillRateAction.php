<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\ProductData;

use MagoAssistant\Mago\Api\Data\ExportColumn;
use MagoAssistant\Mago\Api\Skill\ExportableActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\ActiveStores;
use MagoAssistant\Mago\Service\Skills\ResultLimit;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Issue #319: which product attributes are filled for the fewest products, a data quality check
 * before feeds or layered navigation filters go live.
 *
 * @phpstan-import-type FillRow from AttributeFillRateReport
 * @phpstan-import-type FillReport from AttributeFillRateReport
 */
class AttributeFillRateAction implements ExportableActionInterface
{
    private const DEFAULT_LIMIT = 25;

    public function __construct(
        private readonly AttributeFillRateReport $report,
        private readonly ActiveStores $activeStores
    ) {
    }

    public function getName(): string
    {
        return 'attribute_fill_rate';
    }

    public function getDescription(): string
    {
        return 'Per product attribute, how many products that can have it (attribute set and product type) '
            . 'have a value, least filled first by fill rate. By default the merchant\'s own attributes and '
            . 'every product, read on the default scope; attribute_set, store, include_system and only_enabled '
            . 'narrow or widen it';
    }

    public function getParameterSchema(): array
    {
        return [
            'attribute_set' => [
                'type' => 'string',
                'description' => 'attribute_fill_rate: attribute set name or id to check. Omit for every attribute set.',
            ],
            'store' => [
                'type' => 'string',
                'description' => 'attribute_fill_rate: store view code to read its values with the default as fallback, for the products in '
                    . 'its website. Omit for the default scope values of every product.',
            ],
            'include_system' => [
                'type' => 'boolean',
                'description' => 'attribute_fill_rate: also Magento\'s own attributes, such as description, '
                    . 'meta_description and weight. Default false: only attributes the merchant created.',
            ],
            'only_enabled' => [
                'type' => 'boolean',
                'description' => 'attribute_fill_rate: only count enabled products. Default false.',
            ],
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => ResultLimit::MAX,
                'description' => 'Maximum number of rows to list. Default ' . self::DEFAULT_LIMIT . '.',
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
        return array_fill_keys([
            'attribute_set',
            'store_code',
            'store_name',
            'scope',
            'include_system',
            'only_enabled',
            'product_count',
            'attribute_count',
            'attributes_without_products',
            'shown',
            'is_partial',
            'attribute_code',
            'label',
            'input_type',
            'value_scope',
            'is_required',
            'is_custom',
            'filled_count',
            'applicable_count',
            'empty_count',
            'fill_rate',
        ], [PiiClass::PUBLIC]);
    }

    public function getInstructions(): string
    {
        return 'Lead with the scope (default scope or the store view), the attribute set, product_count and whether '
            . 'only enabled products and only the merchant\'s own attributes were counted. Then a table least filled '
            . 'first: label with attribute_code, filled_count of applicable_count and fill_rate as a percentage. '
            . 'applicable_count is the products whose attribute set and type can have the attribute, not the whole '
            . 'catalog. attributes_without_products counts attributes no product in scope can have; they are not '
            . 'listed. When include_system is false, say Magento\'s own attributes (description, meta fields, weight) '
            . 'can be added. When is_partial is true, say the table shows part of the attributes and the download '
            . 'holds all of them.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $resolved = $this->resolve($params);
        if (is_string($resolved)) {
            return ['error' => $resolved];
        }

        $report = $this->report->build($resolved['scope']);
        $rows = $this->leastFilledFirst($report['attributes']);
        $shown = array_slice($rows, 0, ResultLimit::fromParams($params, self::DEFAULT_LIMIT));

        return [
            'attribute_set' => $resolved['attribute_set'],
            'store_code' => $resolved['store'] === null ? '' : (string)$resolved['store']->getCode(),
            'store_name' => $resolved['store'] === null ? '' : (string)$resolved['store']->getName(),
            'scope' => $resolved['store'] === null ? 'default' : 'store_view',
            'include_system' => $resolved['scope']->isIncludingSystem,
            'only_enabled' => $resolved['scope']->isOnlyEnabled,
            'product_count' => $report['product_count'],
            'attribute_count' => count($rows),
            'attributes_without_products' => $report['attributes_without_products'],
            'shown' => count($shown),
            'is_partial' => count($shown) < count($rows),
            'attributes' => $shown,
        ];
    }

    public function getExportTitle(array $params): string
    {
        return (string)__('Attribute fill rate');
    }

    public function countExportRows(array $params, int $adminUserId): int
    {
        return count($this->exportableRows($params));
    }

    public function getExportColumns(): array
    {
        return [
            new ExportColumn('attribute_code', (string)__('Attribute code')),
            new ExportColumn('label', (string)__('Label')),
            new ExportColumn('input_type', (string)__('Input type')),
            new ExportColumn('value_scope', (string)__('Scope')),
            new ExportColumn('filled_count', (string)__('Products with a value'), true),
            new ExportColumn('applicable_count', (string)__('Products that can have it'), true),
            new ExportColumn('empty_count', (string)__('Products without a value'), true),
            new ExportColumn('fill_rate', (string)__('Fill rate (%)'), true),
        ];
    }

    public function exportRows(array $params, int $adminUserId, int $maxRows): iterable
    {
        return array_slice($this->exportableRows($params), 0, max(0, $maxRows));
    }

    /**
     * @param array<array-key,mixed> $params
     * @return list<FillRow>
     */
    private function exportableRows(array $params): array
    {
        $resolved = $this->resolve($params);

        return is_string($resolved) ? [] : $this->leastFilledFirst($this->report->build($resolved['scope'])['attributes']);
    }

    /**
     * @param array<array-key,mixed> $params
     * @return string|array{scope:AttributeFillRateScope,store:?StoreInterface,attribute_set:string} The error when
     *     the store view or attribute set is unknown
     */
    private function resolve(array $params): string|array
    {
        $storeCode = trim((string)($params['store'] ?? ''));
        $store = $storeCode === '' ? null : ($this->activeStores->select($storeCode)[0] ?? null);
        if ($storeCode !== '' && $store === null) {
            return 'Unknown store view: ' . $storeCode;
        }

        $setInput = trim((string)($params['attribute_set'] ?? ''));
        $sets = $this->report->attributeSets();
        $setId = $setInput === '' ? null : $this->attributeSetId($setInput, $sets);
        if ($setInput !== '' && $setId === null) {
            return 'Unknown attribute set: ' . $setInput . '. Attribute sets: ' . implode(', ', $sets);
        }

        return [
            'scope' => new AttributeFillRateScope(
                $store === null ? AttributeFillRateScope::DEFAULT_STORE_ID : (int)$store->getId(),
                $store === null ? 0 : (int)$store->getWebsiteId(),
                $setId,
                $this->flag($params, 'only_enabled'),
                $this->flag($params, 'include_system')
            ),
            'store' => $store,
            'attribute_set' => $setId === null ? '' : $sets[$setId],
        ];
    }

    /**
     * @param array<int,string> $sets
     */
    private function attributeSetId(string $input, array $sets): ?int
    {
        if (ctype_digit($input) && isset($sets[(int)$input])) {
            return (int)$input;
        }
        $matching = array_keys(array_filter($sets, static fn (string $name): bool => strcasecmp($name, $input) === 0));

        return $matching[0] ?? null;
    }

    /**
     * @param array<array-key,mixed> $params
     */
    private function flag(array $params, string $name): bool
    {
        return filter_var($params[$name] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * @param list<FillRow> $rows
     * @return list<FillRow>
     */
    private function leastFilledFirst(array $rows): array
    {
        usort(
            $rows,
            static fn (array $a, array $b): int => [$a['filled_count'] * $b['applicable_count'], $a['attribute_code']]
                <=> [$b['filled_count'] * $a['applicable_count'], $b['attribute_code']]
        );

        return $rows;
    }
}
