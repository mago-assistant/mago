<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\ProductData;

use MagoAssistant\Mago\Api\Data\ExportColumn;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Api\Skill\ExportableActionInterface;
use MagoAssistant\Mago\Service\Export\ExportRefusedException;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Service\Skills\ResultLimit;

class SearchAction implements ExportableActionInterface
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
                'description' => 'Number of results (default: 20, max: ' . self::MAX_LIMIT . ')',
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

        $limit = ResultLimit::fromParams($params, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $result = $this->apiClient->get('products', $this->apiClient->buildSearchCriteria(
            $this->nameFilter((string)$query),
            $limit
        ), $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $products = [];
        foreach ($result['items'] ?? [] as $item) {
            $product = $this->row($item);
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

    public function getExportTitle(array $params): string
    {
        return (string)__('Full product search results');
    }

    public function countExportRows(array $params, int $adminUserId): int
    {
        $query = (string)($params['query'] ?? '');
        if ($query === '' || !$adminUserId) {
            return 0;
        }

        $result = $this->apiClient->get('products', $this->apiClient->buildSearchCriteria(
            $this->nameFilter($query),
            1
        ), $adminUserId);
        if (isset($result['error'])) {
            throw new ExportRefusedException((string)$result['error']);
        }

        return (int)($result['total_count'] ?? 0);
    }

    public function getExportColumns(): array
    {
        return [
            new ExportColumn('sku', (string)__('SKU')),
            new ExportColumn('name', (string)__('Product name')),
            new ExportColumn('price', (string)__('Price'), true),
            new ExportColumn('status', (string)__('Status')),
            new ExportColumn('type', (string)__('Type')),
        ];
    }

    public function exportRows(array $params, int $adminUserId, int $maxRows): iterable
    {
        $query = (string)($params['query'] ?? '');
        if ($query === '' || !$adminUserId) {
            return;
        }

        $pageSize = max(1, min(ResultLimit::EXPORT_PAGE_SIZE, $maxRows));
        $rowCount = 0;
        for ($page = 1; $rowCount < $maxRows; $page++) {
            $result = $this->apiClient->get('products', $this->apiClient->buildSearchCriteria(
                $this->nameFilter($query),
                $pageSize,
                $page,
                [['field' => 'entity_id', 'direction' => 'ASC']]
            ), $adminUserId);
            if (isset($result['error'])) {
                throw new ExportRefusedException((string)$result['error']);
            }
            $items = (array)($result['items'] ?? []);
            foreach ($items as $item) {
                yield $this->row((array)$item);
                if (++$rowCount === $maxRows) {
                    return;
                }
            }
            if (count($items) < $pageSize || $rowCount >= (int)($result['total_count'] ?? 0)) {
                return;
            }
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function nameFilter(string $query): array
    {
        return [['field' => 'name', 'value' => '%' . $query . '%', 'condition_type' => 'like']];
    }

    /**
     * @param array<array-key, mixed> $item A product from the products API
     * @return array{sku: string, name: string, price: float, status: string, type: string}
     */
    private function row(array $item): array
    {
        return [
            'sku' => (string)($item['sku'] ?? ''),
            'name' => (string)($item['name'] ?? ''),
            'price' => (float)($item['price'] ?? 0),
            'status' => ($item['status'] ?? 2) == 1 ? 'enabled' : 'disabled',
            'type' => (string)($item['type_id'] ?? ''),
        ];
    }
}
