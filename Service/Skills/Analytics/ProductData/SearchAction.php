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

class SearchAction implements ActionInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'search';
    }

    public function getDescription(): string
    {
        return 'Search products by name/keyword';
    }

    public function getParameterSchema(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'Search query or SKU depending on action',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results (default: 20, max: 50)',
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
            'query' => [PiiClass::PUBLIC],
            'count' => [PiiClass::PUBLIC],
            'total_count' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'price' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
            'admin_url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $query = $params['query'] ?? '';
        if (!$query) {
            return ['error' => 'Search query is required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $limit = min(max((int)($params['limit'] ?? self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);
        $searchParams = $this->apiClient->buildSearchCriteria(
            [['field' => 'name', 'value' => '%' . $query . '%', 'condition_type' => 'like']],
            $limit
        );
        $result = $this->apiClient->get('products', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $products = [];
        foreach ($result['items'] ?? [] as $item) {
            $product = [
                'sku' => $item['sku'] ?? '',
                'name' => $item['name'] ?? '',
                'price' => (float)($item['price'] ?? 0),
                'status' => ($item['status'] ?? 2) == 1 ? 'enabled' : 'disabled',
                'type' => $item['type_id'] ?? '',
            ];
            if (isset($item['id'])) {
                $product['admin_url'] = $this->secureAdminUrl->getUrl(
                    'catalog/product/edit',
                    ['id' => (int)$item['id']]
                );
            }
            $products[] = $product;
        }

        return [
            'query' => $query,
            'count' => count($products),
            'total_count' => (int)($result['total_count'] ?? count($products)),
            'products' => $products,
        ];
    }
}
