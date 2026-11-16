<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

/**
 * The attributes shown as filters, with their order, where they show and which attribute sets have them
 */
class ListFiltersAction implements ActionInterface
{
    /** catalog_eav_attribute.is_filterable */
    public const FILTERABLE = [0 => 'no', 1 => 'with_results', 2 => 'no_results'];

    public function __construct(
        private readonly FilterAttributes $filterAttributes,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'list_filters';
    }

    public function getDescription(): string
    {
        return 'Attributes shown as filters on category and search pages, in filter order, with their attribute sets';
    }

    public function getParameterSchema(): array
    {
        return [];
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
            PiiClass::ANY => [PiiClass::PUBLIC],
            'admin_url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return 'Show a table in filter order: label (linked with admin_url), code, filterable, in search, '
            . 'position, attribute sets. filterable "with_results" shows options that have products, "no_results" '
            . 'shows every option. Filters with the same position are ordered by label. attribute_sets "all" means '
            . 'every attribute set has it.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $attributes = $this->filterAttributes->attributes(true);
        [$sets, $setCount] = $this->filterAttributes->attributeSets(array_keys($attributes));

        $filters = [];
        foreach ($attributes as $attributeId => $attribute) {
            $attributeSets = $sets[$attributeId] ?? [];
            $filters[] = [
                'label' => $attribute['label'],
                'code' => $attribute['code'],
                'input' => $attribute['input'],
                'filterable' => self::FILTERABLE[$attribute['is_filterable']] ?? 'no',
                'filterable_in_search' => $attribute['is_filterable_in_search'],
                'position' => $attribute['position'],
                'attribute_sets' => $setCount > 0 && count($attributeSets) === $setCount
                    ? 'all'
                    : implode(', ', $attributeSets),
                'admin_url' => $this->secureAdminUrl->getUrl(
                    'catalog/product_attribute/edit',
                    ['attribute_id' => $attributeId]
                ),
            ];
        }

        return ['filter_count' => count($filters), 'filters' => $filters];
    }
}
