<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\ProductData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class GetBySkuAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'get_by_sku';
    }

    public function getDescription(): string
    {
        return 'Get single product details by SKU';
    }

    public function getParameterSchema(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'Search query or SKU depending on action',
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
            'sku' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'price' => [PiiClass::PUBLIC],
            'special_price' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
            'description' => [PiiClass::PUBLIC],
            'short_description' => [PiiClass::PUBLIC],
            'meta_title' => [PiiClass::PUBLIC],
            'meta_description' => [PiiClass::PUBLIC],
            'url_key' => [PiiClass::PUBLIC],
            'id' => [PiiClass::TOKENISE, 'entity'],
            'admin_url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $sku = $params['query'] ?? '';
        if (!$sku) {
            return ['error' => 'SKU is required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $result = $this->apiClient->get('products/' . urlencode($sku), [], $adminUserId);

        if (isset($result['error'])) {
            return ['error' => 'Product not found: ' . $sku];
        }

        $customAttributes = [];
        foreach ($result['custom_attributes'] ?? [] as $attr) {
            $customAttributes[$attr['attribute_code']] = $attr['value'];
        }

        return $this->product($result, $customAttributes) + $this->links($result);
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $customAttributes
     * @return array<string,mixed>
     */
    private function product(array $result, array $customAttributes): array
    {
        return [
            'sku' => $result['sku'] ?? '',
            'name' => $result['name'] ?? '',
            'price' => (float)($result['price'] ?? 0),
            'special_price' => isset($customAttributes['special_price'])
                ? (float)$customAttributes['special_price']
                : null,
            'status' => ($result['status'] ?? 2) == 1 ? 'enabled' : 'disabled',
            'type' => $result['type_id'] ?? '',
            'description' => $customAttributes['description'] ?? '',
            'short_description' => $customAttributes['short_description'] ?? '',
            'meta_title' => $customAttributes['meta_title'] ?? '',
            'meta_description' => $customAttributes['meta_description'] ?? '',
            'url_key' => $customAttributes['url_key'] ?? '',
        ];
    }

    /**
     * The product id and its edit page, so the model can link the product directly or hand the id to
     * admin_navigator. Nothing when the API returned no id.
     *
     * @param array<string,mixed> $result
     * @return array{id?:int,admin_url?:string}
     */
    private function links(array $result): array
    {
        $id = (int)($result['id'] ?? 0);
        if ($id === 0) {
            return [];
        }

        return [
            'id' => $id,
            'admin_url' => $this->secureAdminUrl->getUrl('catalog/product/edit', ['id' => $id]),
        ];
    }
}
