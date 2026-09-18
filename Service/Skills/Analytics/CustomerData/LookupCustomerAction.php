<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\CustomerData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class LookupCustomerAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'lookup_customer';
    }

    public function getDescription(): string
    {
        return 'Search for a customer by name or email';
    }

    public function getParameterSchema(): array
    {
        return [
            'search' => [
                'type' => 'string',
                'description' => 'Customer name or email to search for',
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
        // Direct identifiers are never sent; the bare id is tokenised so the assistant can still
        // refer to the row; city/country stay public so "which customers are in X" keeps working
        // (#97: the identifiers beside them are stripped, so they are not linkable).
        return [
            'entity_id' => [PiiClass::TOKENISE, 'customer'],
            'name' => [PiiClass::STRIP],
            'email' => [PiiClass::STRIP],
            'telephone' => [PiiClass::STRIP],
            'country' => [PiiClass::PUBLIC],
            'city' => [PiiClass::PUBLIC],
            'registered' => [PiiClass::PUBLIC],
            // The miss message quotes what the admin searched for, which is a name or an address.
            // An empty results list already says "nothing found".
            'message' => [PiiClass::STRIP],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $search = $params['search'] ?? '';
        if (empty(trim($search))) {
            return ['error' => 'search parameter is required for lookup_customer'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $limit = max(1, min((int)($params['limit'] ?? 10), 10));

        if (str_contains($search, '@')) {
            $searchParams = $this->apiClient->buildSearchCriteria(
                [['field' => 'email', 'value' => '%' . trim($search) . '%', 'condition_type' => 'like']],
                $limit,
                1,
                [['field' => 'created_at', 'direction' => 'DESC']]
            );
        } else {
            $searchParams = $this->apiClient->buildSearchCriteria(
                [['field' => 'firstname', 'value' => '%' . trim($search) . '%', 'condition_type' => 'like']],
                $limit,
                1,
                [['field' => 'created_at', 'direction' => 'DESC']]
            );
        }

        $result = $this->apiClient->get('customers/search', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $items = $result['items'] ?? [];

        if (!str_contains($search, '@') && empty($items)) {
            $searchParams = $this->apiClient->buildSearchCriteria(
                [['field' => 'lastname', 'value' => '%' . trim($search) . '%', 'condition_type' => 'like']],
                $limit,
                1,
                [['field' => 'created_at', 'direction' => 'DESC']]
            );
            $result = $this->apiClient->get('customers/search', $searchParams, $adminUserId);
            $items = $result['items'] ?? [];
        }

        if (empty($items)) {
            return ['results' => [], 'message' => 'No customers found matching "' . $search . '"'];
        }

        $customers = [];
        foreach ($items as $customer) {
            $customerId = (int)($customer['id'] ?? 0);
            $address = $customer['addresses'][0] ?? [];

            $customers[] = [
                'entity_id' => $customerId,
                'name' => trim(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? '')),
                'email' => $customer['email'] ?? '',
                'country' => $address['country_id'] ?? null,
                'city' => $address['city'] ?? null,
                'telephone' => $address['telephone'] ?? null,
                'registered' => $customer['created_at'] ?? '',
                'admin_url' => $this->secureAdminUrl->getUrl('customer/index/edit', ['id' => $customerId]),
            ];
        }

        return ['results' => $customers];
    }
}
