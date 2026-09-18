<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\CustomerData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class RecentSignupsAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly PeriodParser $periodParser,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'recent_signups';
    }

    public function getDescription(): string
    {
        return 'New customers in period';
    }

    public function getParameterSchema(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Time period: "7days", "30days", "this_month", "this_year"',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results (default: 10)',
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
        // The signup rows carry no direct identifiers; the bare id is tokenised so the assistant
        // can still refer to the customer.
        return [
            'period' => [PiiClass::PUBLIC],
            'total_new' => [PiiClass::PUBLIC],
            'customer_id' => [PiiClass::TOKENISE, 'customer'],
            'group_id' => [PiiClass::PUBLIC],
            'registered' => [PiiClass::PUBLIC],
            'store_id' => [PiiClass::PUBLIC],
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

        $period = $params['period'] ?? '30days';
        $limit = (int)($params['limit'] ?? 10);
        $from = $this->periodParser->getFromDate($period);

        $countParams = $this->apiClient->buildSearchCriteria(
            [['field' => 'created_at', 'value' => $from, 'condition_type' => 'gteq']],
            1
        );
        $countResult = $this->apiClient->get('customers/search', $countParams, $adminUserId);
        $totalNew = $countResult['total_count'] ?? 0;

        $searchParams = $this->apiClient->buildSearchCriteria(
            [['field' => 'created_at', 'value' => $from, 'condition_type' => 'gteq']],
            $limit,
            1,
            [['field' => 'created_at', 'direction' => 'DESC']]
        );
        $result = $this->apiClient->get('customers/search', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $signups = [];
        foreach ($result['items'] ?? [] as $customer) {
            $customerId = (int)($customer['id'] ?? 0);
            $signups[] = [
                'customer_id' => $customerId,
                'group_id' => (int)($customer['group_id'] ?? 0),
                'registered' => $customer['created_at'] ?? '',
                'store_id' => (int)($customer['store_id'] ?? 0),
                'admin_url' => $this->secureAdminUrl->getUrl('customer/index/edit', ['id' => $customerId]),
            ];
        }

        return ['period' => $period, 'total_new' => $totalNew, 'recent' => $signups];
    }
}
