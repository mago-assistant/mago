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

class LookupOrderAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'lookup_order';
    }

    public function getDescription(): string
    {
        return 'Find a specific order by order number';
    }

    public function getParameterSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID for lookup_order action (e.g. "000000549")',
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
        // Name and email are direct identifiers and never sent; the order ids are tokenised so
        // follow-up actions can still target the order.
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'entity_id' => [PiiClass::TOKENISE, 'order'],
            'order_number' => [PiiClass::TOKENISE, 'order'],
            'total' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'customer' => [PiiClass::TOKENISE, 'name'],
            'email' => [PiiClass::TOKENISE, 'email'],
            'date' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'qty' => [PiiClass::PUBLIC],
            'price' => [PiiClass::PUBLIC],
            'row_total' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $orderNumber = $params['order_number'] ?? '';
        if (empty($orderNumber)) {
            return ['error' => 'order_number parameter is required for lookup_order'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $searchParams = $this->apiClient->buildSearchCriteria(
            [['field' => 'increment_id', 'value' => $orderNumber, 'condition_type' => 'eq']],
            1
        );
        $result = $this->apiClient->get('orders', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $items = $result['items'] ?? [];
        if (empty($items)) {
            return ['error' => 'Order not found: ' . $orderNumber];
        }

        $order = reset($items);
        $entityId = (int)($order['entity_id'] ?? 0);

        $orderItems = [];
        foreach ($order['items'] ?? [] as $item) {
            if (($item['product_type'] ?? '') === 'configurable') {
                continue;
            }
            $orderItems[] = [
                'sku' => $item['sku'] ?? '',
                'name' => $item['name'] ?? '',
                'qty' => (int)($item['qty_ordered'] ?? 0),
                'price' => round((float)($item['price'] ?? 0), 2),
                'row_total' => round((float)($item['row_total_incl_tax'] ?? $item['row_total'] ?? 0), 2),
            ];
        }

        return [
            'order' => [
                'entity_id' => $entityId,
                'order_number' => $order['increment_id'] ?? '',
                'total' => round((float)($order['grand_total'] ?? 0), 2),
                'status' => $order['status'] ?? '',
                'customer' => trim(($order['customer_firstname'] ?? '') . ' ' . ($order['customer_lastname'] ?? '')),
                'email' => $order['customer_email'] ?? '',
                'items' => $orderItems,
                'date' => $order['created_at'] ?? '',
                'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
            ],
        ];
    }
}
