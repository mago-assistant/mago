<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductManagement;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class ListAttributeSetsAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient
    ) {
    }

    public function getName(): string
    {
        return 'list_attribute_sets';
    }

    public function getDescription(): string
    {
        return 'List product attribute sets with their IDs and the dropdown attributes (e.g. color, size) '
            . 'each set contains, so you can pick a set that supports the wanted configurable variants';
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
            'id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'variant_attributes' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $result = $this->apiClient->get(
            'products/attribute-sets/sets/list',
            $this->apiClient->buildSearchCriteria([], 50),
            $adminUserId
        );

        if (isset($result['error'])) {
            return ['error' => 'Failed to load attribute sets: ' . $result['error']];
        }

        $sets = [];
        foreach ($result['items'] ?? [] as $set) {
            $setId = (int)($set['attribute_set_id'] ?? 0);
            $sets[] = [
                'id' => $setId,
                'name' => $set['attribute_set_name'] ?? '',
                'variant_attributes' => $this->getVariantAttributeCodes($setId, $adminUserId),
            ];
        }

        return ['attribute_sets' => $sets];
    }

    /**
     * Mirrors Magento's ConfigurableAttributeHandler: only global, user-defined dropdown attributes
     * can be configurable super attributes, so only those are reported.
     *
     * @return string[]
     */
    private function getVariantAttributeCodes(int $setId, int $adminUserId): array
    {
        if (!$setId) {
            return [];
        }

        $attributes = $this->apiClient->get('products/attribute-sets/' . $setId . '/attributes', [], $adminUserId);
        if (isset($attributes['error'])) {
            return [];
        }

        $codes = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $input = $attribute['frontend_input'] ?? '';
            if (($input === 'select' || $input === 'swatch_visual' || $input === 'swatch_text')
                && !empty($attribute['is_user_defined'])
                && ($attribute['scope'] ?? 'global') === 'global'
            ) {
                $codes[] = (string)$attribute['attribute_code'];
            }
        }

        return $codes;
    }
}
