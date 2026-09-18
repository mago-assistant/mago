<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\SalesData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class RecentOrdersAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'recent_orders';
    }

    public function getDescription(): string
    {
        return 'Latest orders with status and totals';
    }

    public function getParameterSchema(): array
    {
        return [
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results to return (default: 10)',
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
        // The order ids are tokenised so follow-up actions can still target the order.
        return [
            'entity_id' => [PiiClass::TOKENISE, 'order'],
            'order_number' => [PiiClass::TOKENISE, 'order'],
            'total' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'items' => [PiiClass::PUBLIC],
            'store_id' => [PiiClass::PUBLIC],
            'date' => [PiiClass::PUBLIC],
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

        $limit = (int)($params['limit'] ?? 10);

        $searchParams = $this->apiClient->buildSearchCriteria(
            [],
            $limit,
            1,
            [['field' => 'created_at', 'direction' => 'DESC']]
        );
        $result = $this->apiClient->get('orders', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $orders = [];
        foreach ($result['items'] ?? [] as $order) {
            $entityId = (int)($order['entity_id'] ?? 0);
            $orders[] = [
                'entity_id' => $entityId,
                'order_number' => $order['increment_id'] ?? '',
                'total' => round((float)($order['grand_total'] ?? 0), 2),
                'status' => $order['status'] ?? '',
                'items' => (int)($order['total_item_count'] ?? 0),
                'store_id' => (int)($order['store_id'] ?? 0),
                'date' => $order['created_at'] ?? '',
                'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
            ];
        }

        return ['recent_orders' => $orders];
    }
}
