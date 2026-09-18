<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\CustomerData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class CountAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient
    ) {
    }

    public function getName(): string
    {
        return 'count';
    }

    public function getDescription(): string
    {
        return 'Total customer count';
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
            'total_customers' => [PiiClass::PUBLIC],
            'new_today' => [PiiClass::PUBLIC],
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

        $searchParams = $this->apiClient->buildSearchCriteria([], 1);
        $result = $this->apiClient->get('customers/search', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $total = $result['total_count'] ?? 0;

        $today = (new \DateTimeImmutable())->format('Y-m-d 00:00:00');
        $todayParams = $this->apiClient->buildSearchCriteria(
            [['field' => 'created_at', 'value' => $today, 'condition_type' => 'gteq']],
            1
        );
        $todayResult = $this->apiClient->get('customers/search', $todayParams, $adminUserId);
        $newToday = $todayResult['total_count'] ?? 0;

        return ['total_customers' => $total, 'new_today' => $newToday];
    }
}
