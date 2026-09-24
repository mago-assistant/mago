<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductManagement;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class AddConfigurableVariantsAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly CreateProductAction $createProductAction
    ) {
    }

    public function getName(): string
    {
        return 'add_configurable_variants';
    }

    public function getDescription(): string
    {
        return 'Create child products for a configurable parent and link them as variants';
    }

    public function getParameterSchema(): array
    {
        return [
            'parent_sku' => [
                'type' => 'string',
                'description' => 'SKU of the existing configurable parent product',
            ],
            'super_attributes' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Attribute codes the variants differ on, e.g. ["color", "size"]',
            ],
            'variants' => [
                'type' => 'array',
                'items' => ['type' => 'object'],
                'description' => 'One entry per variant: {"attributes": {"color": "Red", "size": "M"}, '
                    . '"price": 29.99, "qty": 10, "sku": "optional-child-sku"}. '
                    . 'Attribute values are option labels (or option IDs).',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        // Catalog data only. The _links url is a relative admin route without the secret key
        // (the signed variant is the centrally stripped admin_url), so it stays public.
        return [
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'label' => [PiiClass::PUBLIC],
            'reused' => [PiiClass::PUBLIC],
            'created_so_far' => [PiiClass::PUBLIC],
            'url' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return <<<'TEXT'
The parent must already exist as a configurable product. Every variant needs a value for every
super attribute. Attribute values may be option labels ("Red") or option IDs ("49"); labels are
resolved automatically and must match existing options — check with get_attribute_options first.
Child SKUs default to "<parent_sku>-<value1>-<value2>". Children are created as simple products,
not visible individually.
TEXT;
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $parentSku = trim((string)($params['parent_sku'] ?? ''));
        $attributeCodes = array_values(array_filter(array_map('strval', $params['super_attributes'] ?? [])));
        $variants = $params['variants'] ?? [];

        if (!$parentSku || !$attributeCodes || !$variants) {
            return ['error' => 'parent_sku, super_attributes and variants are all required'];
        }

        $parent = $this->apiClient->get('products/' . urlencode($parentSku), [], $adminUserId);
        if (isset($parent['error'])) {
            return ['error' => 'Configurable parent not found: ' . $parentSku];
        }
        if (($parent['type_id'] ?? '') !== 'configurable') {
            return ['error' => 'Product "' . $parentSku . '" is not a configurable product'];
        }

        $attributeSetId = (int)($parent['attribute_set_id'] ?? 4);
        $setAttributes = $this->apiClient->get(
            'products/attribute-sets/' . $attributeSetId . '/attributes',
            [],
            $adminUserId
        );
        $setCodes = array_column($setAttributes, 'attribute_code');
        foreach ($attributeCodes as $code) {
            if ($setCodes && !in_array($code, $setCodes, true)) {
                return ['error' => 'Attribute "' . $code . '" is not part of attribute set ' . $attributeSetId
                    . ' used by "' . $parentSku . '". Use list_attribute_sets and recreate the parent with an '
                    . 'attribute set that contains this attribute, or pick a different attribute.'];
            }
        }

        $attributes = [];
        foreach ($attributeCodes as $code) {
            $meta = $this->apiClient->get('products/attributes/' . urlencode($code), [], $adminUserId);
            if (isset($meta['error']) || empty($meta['attribute_id'])) {
                return ['error' => 'Attribute not found: ' . $code];
            }
            $optionsByLabel = [];
            $optionIds = [];
            foreach ($meta['options'] ?? [] as $option) {
                if (($option['value'] ?? '') === '') {
                    continue;
                }
                $optionsByLabel[mb_strtolower(trim((string)$option['label']))] = (string)$option['value'];
                $optionIds[(string)$option['value']] = true;
            }
            $attributes[$code] = [
                'id' => (int)$meta['attribute_id'],
                'by_label' => $optionsByLabel,
                'ids' => $optionIds,
            ];
        }

        $created = [];
        $usedOptionIds = array_fill_keys($attributeCodes, []);

        foreach ($variants as $variant) {
            $resolved = [];
            $labelParts = [];
            foreach ($attributeCodes as $code) {
                $value = trim((string)($variant['attributes'][$code] ?? ''));
                if ($value === '') {
                    return ['error' => 'Variant is missing a value for attribute "' . $code . '"'];
                }
                $optionId = $attributes[$code]['by_label'][mb_strtolower($value)]
                    ?? (isset($attributes[$code]['ids'][$value]) ? $value : null);
                if ($optionId === null) {
                    return ['error' => 'Unknown option "' . $value . '" for attribute "' . $code
                        . '". Use get_attribute_options to list valid options.'];
                }
                $resolved[$code] = $optionId;
                $usedOptionIds[$code][$optionId] = true;
                $labelParts[] = $value;
            }

            $childSku = trim((string)($variant['sku'] ?? ''))
                ?: $parentSku . '-' . $this->slugify(implode('-', $labelParts));

            $existing = $this->apiClient->get('products/' . urlencode($childSku), [], $adminUserId);
            if (!isset($existing['error'])) {
                if (($existing['type_id'] ?? '') !== 'simple') {
                    return ['error' => 'SKU "' . $childSku . '" exists but is not a simple product'];
                }
                $created[] = ['sku' => $childSku, 'label' => implode(' / ', $labelParts), 'reused' => true];
                continue;
            }

            $result = $this->createProductAction->execute([
                'sku' => $childSku,
                'name' => ($parent['name'] ?? $parentSku) . ' ' . implode(' ', $labelParts),
                'product_type' => 'simple',
                'price' => (float)($variant['price'] ?? $parent['price'] ?? 0),
                'attribute_set_id' => (int)($parent['attribute_set_id'] ?? 4),
                'qty' => (float)($variant['qty'] ?? 0),
                'visible' => false,
                'custom_attributes' => $resolved,
                'ignore_similar' => true,
            ], $adminUserId);

            if (isset($result['error']) || !empty($result['not_created'])) {
                return [
                    'error' => 'Failed to create variant "' . $childSku . '": ' . ($result['error'] ?? 'not created'),
                    'created_so_far' => array_column($created, 'sku'),
                ];
            }

            $created[] = ['sku' => $childSku, 'label' => implode(' / ', $labelParts)];
        }

        foreach ($attributeCodes as $position => $code) {
            $values = [];
            foreach (array_keys($usedOptionIds[$code]) as $optionId) {
                $values[] = ['value_index' => (int)$optionId];
            }
            $result = $this->apiClient->post('configurable-products/' . urlencode($parentSku) . '/options', [
                'option' => [
                    'attribute_id' => (string)$attributes[$code]['id'],
                    'label' => $code,
                    'position' => $position,
                    'values' => $values,
                ],
            ], $adminUserId);
            if (isset($result['error']) && stripos($result['error'], 'already') === false) {
                return ['error' => 'Failed to set option "' . $code . '" on parent: ' . $result['error']];
            }
        }

        foreach ($created as $child) {
            $result = $this->apiClient->post('configurable-products/' . urlencode($parentSku) . '/child', [
                'childSku' => $child['sku'],
            ], $adminUserId);
            if (isset($result['error']) && stripos($result['error'], 'already') === false) {
                return ['error' => 'Failed to link variant "' . $child['sku'] . '": ' . $result['error']];
            }
        }

        return [
            'success' => true,
            'message' => count($created) . ' variant(s) created and linked to "' . $parentSku . '"',
            'variants' => $created,
            '_links' => [
                [
                    'label' => 'Edit ' . ($parent['name'] ?? $parentSku),
                    'url' => 'catalog/product/edit/id/' . ($parent['id'] ?? ''),
                ],
            ],
        ];
    }

    private function slugify(string $value): string
    {
        return strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));
    }
}
