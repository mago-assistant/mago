<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Attribute as AttributeResource;
use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Turns an attribute into a filter or back, or moves it
 *
 * Saves through the attribute resource model, as the attribute form does, without touching the options
 * or the store labels; the attribute's own save marks the indexers that need a rebuild.
 */
class UpdateFilterAction implements ValidatingActionInterface
{
    public function __construct(
        private readonly FilterAttributes $filterAttributes,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly AttributeResource $attributeResource
    ) {
    }

    public function getName(): string
    {
        return 'update_filter';
    }

    public function getDescription(): string
    {
        return 'Make an attribute a filter or not, on category and on search pages, or change its position';
    }

    public function getParameterSchema(): array
    {
        return [
            'attribute' => [
                'type' => 'string',
                'description' => 'Attribute code, from list_filters or audit_filters',
            ],
            'filterable' => [
                'type' => 'string',
                'enum' => array_values(ListFiltersAction::FILTERABLE),
                'description' => 'update_filter: filter on category pages. "with_results" shows options that have '
                    . 'products (usual), "no_results" shows every option, "no" removes the filter',
            ],
            'filterable_in_search' => [
                'type' => 'boolean',
                'description' => 'update_filter: filter on search result pages',
            ],
            'position' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => 'update_filter: filter order, lowest first; equal positions are ordered by label',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getInstructions(): string
    {
        return 'Send only the fields the user asked to change. To put a filter after another, read the positions '
            . 'with list_filters and give it a position between; when several filters share a position, move the '
            . 'ones in the way as well, one call each.';
    }

    public function findRefusal(array $params): ?array
    {
        try {
            $this->changes($params);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }

        return null;
    }

    public function execute(array $params, int $adminUserId): array
    {
        try {
            [$attribute, $changes] = $this->changes($params);
            $model = $this->attributeRepository->get($attribute['code']);
            foreach ($changes as $field => $value) {
                $model->setData($field, $value);
            }
            $this->attributeResource->save($model);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'attribute' => $attribute['code'],
            'changed' => array_keys($changes),
            'message' => 'Saved. The storefront shows it after a reindex of catalog_product_attribute and '
                . 'catalogsearch_fulltext and a cache flush.',
        ];
    }

    /**
     * The attribute and the fields to set, after every check
     *
     * @param array $params
     * @return array{0: array, 1: array<string, mixed>}
     * @throws LocalizedException
     */
    private function changes(array $params): array
    {
        $code = trim((string)($params['attribute'] ?? ''));
        $attribute = $code === '' ? null : $this->filterAttributes->attribute($code);
        if ($attribute === null) {
            throw new LocalizedException(
                __('There is no product attribute "%1". Use list_filters for the codes.', $code)
            );
        }

        $changes = [];
        if (isset($params['filterable'])) {
            $level = array_search($params['filterable'], ListFiltersAction::FILTERABLE, true);
            if ($level === false) {
                throw new LocalizedException(__('"filterable" must be with_results, no_results or no.'));
            }
            $changes['is_filterable'] = $level;
        }
        if (isset($params['filterable_in_search'])) {
            $changes['is_filterable_in_search'] = (int)filter_var(
                $params['filterable_in_search'],
                FILTER_VALIDATE_BOOLEAN
            );
        }
        if (isset($params['position'])) {
            if (!is_numeric($params['position']) || (int)$params['position'] < 0) {
                throw new LocalizedException(__('"position" must be a whole number of 0 or more.'));
            }
            $changes['position'] = (int)$params['position'];
        }
        if ($changes === []) {
            throw new LocalizedException(__('Nothing to change: give filterable, filterable_in_search or position.'));
        }
        $becomesFilter = ($changes['is_filterable'] ?? 0) > 0 || ($changes['is_filterable_in_search'] ?? 0) > 0;
        if ($becomesFilter && !in_array($attribute['input'], FilterAttributes::FILTERABLE_INPUTS, true)) {
            throw new LocalizedException(__(
                'Attribute "%1" is a %2 field; only dropdown, multiple select, yes/no, swatch and price attributes '
                . 'can be filters.',
                $attribute['code'],
                $attribute['input']
            ));
        }

        return [$attribute, $changes];
    }
}
