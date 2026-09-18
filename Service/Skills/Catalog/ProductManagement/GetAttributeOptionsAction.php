<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductManagement;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class GetAttributeOptionsAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient
    ) {
    }

    public function getName(): string
    {
        return 'get_attribute_options';
    }

    public function getDescription(): string
    {
        return 'Get selectable options (label + ID) of a product attribute, e.g. color or size';
    }

    public function getParameterSchema(): array
    {
        return [
            'attribute_code' => [
                'type' => 'string',
                'description' => 'Attribute code, e.g. "color" or "size"',
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
            'attribute_code' => [PiiClass::PUBLIC],
            'id' => [PiiClass::PUBLIC],
            'label' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $code = trim((string)($params['attribute_code'] ?? ''));
        if (!$code) {
            return ['error' => 'attribute_code is required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $result = $this->apiClient->get('products/attributes/' . urlencode($code) . '/options', [], $adminUserId);

        if (isset($result['error'])) {
            return ['error' => 'Failed to load options for "' . $code . '": ' . $result['error']];
        }

        $options = [];
        foreach ($result as $option) {
            if (!is_array($option) || ($option['value'] ?? '') === '') {
                continue;
            }
            $options[] = [
                'id' => $option['value'],
                'label' => $option['label'] ?? '',
            ];
        }

        return ['attribute_code' => $code, 'options' => $options];
    }
}
