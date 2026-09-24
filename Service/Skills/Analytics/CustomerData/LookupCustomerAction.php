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
        return 'Search the customer accounts for a name or email. Registered accounts only: '
            . 'someone who ordered as a guest has no account and will not be found here, so a '
            . 'question about a named person\'s orders goes to sales_data customer_orders instead';
    }

    public function getParameterSchema(): array
    {
        return [
            'search' => [
                'type' => 'string',
                'description' => 'Customer name, email address or customer id to search for. Required by lookup_customer '
                    . 'and used by no other action, so do not pick lookup_customer when the question '
                    . 'names nobody to search for.',
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
        // Direct identifiers are tokenised, not dropped: the provider sees [name_1] and the panel
        // shows the admin the real value. City and country stay public so "which customers are in
        // X" keeps working.
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'entity_id' => [PiiClass::TOKENISE, 'customer'],
            'name' => [PiiClass::TOKENISE, 'name'],
            'email' => [PiiClass::TOKENISE, 'email'],
            'telephone' => [PiiClass::TOKENISE, 'phone'],
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

        if (ctype_digit(trim($search))) {
            // The assistant refers to a customer by the id it was given, so the admin asks about
            // "customer 32". Searching that as a name finds nobody, which reads as "this customer
            // does not exist" for a customer we just showed them.
            $searchParams = $this->apiClient->buildSearchCriteria(
                [['field' => 'entity_id', 'value' => trim($search), 'condition_type' => 'eq']],
                $limit,
                1,
                [['field' => 'created_at', 'direction' => 'DESC']]
            );
        } elseif (str_contains($search, '@')) {
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
