<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\SalesData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class CustomerOrdersAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly PeriodParser $periodParser,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'customer_orders';
    }

    public function getDescription(): string
    {
        return 'Find orders for a specific customer, by customer_id or by name or email address. '
            . 'A guest order has no customer_id, so a name or email is the only way to reach it.';
    }

    public function getParameterSchema(): array
    {
        return [
            'customer_id' => [
                'type' => 'integer',
                'description' => 'Customer entity_id to look up orders for. Leave it out and pass '
                    . '"customer" instead when you only know a name or an email address.',
            ],
            'customer' => [
                'type' => 'string',
                'description' => 'Customer name or email address, for when there is no customer_id. '
                    . 'Guest orders are only reachable this way, since they have no customer record.',
            ],
            'period' => [
                'type' => 'string',
                'description' => 'Time period: "today", "yesterday", "7days", "30days", "this_month", "last_month", "this_year", "all" for no lower bound, "YYYY-MM" for a specific month, or "YYYY-MM-DD:YYYY-MM-DD" for a custom range',
            ],
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
        // The bare customer and order ids are tokenised so the assistant can still refer to them.
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'customer_id' => [PiiClass::TOKENISE, 'customer'],
            'customer' => [PiiClass::TOKENISE, 'name'],
            'searched_for' => [PiiClass::TOKENISE, 'name'],
            'period' => [PiiClass::PUBLIC],
            'total_orders' => [PiiClass::PUBLIC],
            'entity_id' => [PiiClass::TOKENISE, 'order'],
            'order_number' => [PiiClass::TOKENISE, 'order'],
            'total' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'items' => [PiiClass::PUBLIC],
            'date' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $customerId = (int)($params['customer_id'] ?? 0);
        $customer = trim((string)($params['customer'] ?? ''));
        if ($customerId <= 0 && $customer === '') {
            return ['error' => 'customer_orders needs either a customer_id or a customer name or email'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $period = $params['period'] ?? '30days';
        $limit = (int)($params['limit'] ?? 10);
        [$from, $to] = $this->periodParser->parse($period);

        $result = null;
        foreach ($this->customerFilters($customerId, $customer) as $customerFilter) {
            $searchParams = $this->apiClient->buildSearchCriteria(
                [
                    $customerFilter,
                    ['field' => 'created_at', 'value' => $from, 'condition_type' => 'gteq'],
                    ['field' => 'created_at', 'value' => $to, 'condition_type' => 'lteq'],
                ],
                $limit,
                1,
                [['field' => 'created_at', 'direction' => 'DESC']]
            );
            $result = $this->apiClient->get('orders', $searchParams, $adminUserId);

            if (isset($result['error']) || !empty($result['items'])) {
                break;
            }
        }

        if (isset($result['error'])) {
            return $result;
        }

        $orders = [];
        foreach ($result['items'] ?? [] as $order) {
            $entityId = (int)($order['entity_id'] ?? 0);
            $orders[] = [
                'entity_id' => $entityId,
                'order_number' => $order['increment_id'] ?? '',
                'customer' => trim(($order['customer_firstname'] ?? '') . ' ' . ($order['customer_lastname'] ?? '')),
                'total' => round((float)($order['grand_total'] ?? 0), 2),
                'status' => $order['status'] ?? '',
                'items' => (int)($order['total_item_count'] ?? 0),
                'date' => $order['created_at'] ?? '',
                'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
            ];
        }

        return [
            // A guest has no customer_id. Returning a zero would mint a token standing for a row
            // that does not exist, so the key is simply absent and the buyer's name identifies the
            // orders instead.
            'customer_id' => $customerId > 0 ? $customerId : null,
            'searched_for' => $customer !== '' ? $customer : null,
            'period' => $period,
            'total_orders' => count($orders),
            'orders' => $orders,
        ];
    }

    /**
     * The filters to try, in order. The search criteria helper ANDs every filter it is given, so a
     * name that could be a first name or a last name is two queries rather than one OR.
     *
     * @return array<int,array{field:string,value:string,condition_type:string}>
     */
    private function customerFilters(int $customerId, string $customer): array
    {
        if ($customerId > 0) {
            return [['field' => 'customer_id', 'value' => (string)$customerId, 'condition_type' => 'eq']];
        }

        if (str_contains($customer, '@')) {
            return [['field' => 'customer_email', 'value' => $customer, 'condition_type' => 'eq']];
        }

        $last = strrchr($customer, ' ');
        $surname = $last === false ? $customer : trim($last);

        return [
            ['field' => 'customer_lastname', 'value' => '%' . $surname . '%', 'condition_type' => 'like'],
            ['field' => 'customer_firstname', 'value' => '%' . $customer . '%', 'condition_type' => 'like'],
        ];
    }
}
