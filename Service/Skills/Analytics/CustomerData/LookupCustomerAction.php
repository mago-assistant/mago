<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\CustomerData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Service\Time\StoreTime;

class LookupCustomerAction implements ActionInterface
{
    private const NAME_FIELDS = ['firstname', 'middlename', 'lastname'];
    private const MAX_NAME_WORDS = 5;
    private const NEWEST_FIRST = [['field' => 'created_at', 'direction' => 'DESC']];
    private const SEARCH_REQUIRED = ['error' => 'search parameter is required for lookup_customer'];

    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly StoreTime $storeTime
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
            . 'question about a named person\'s orders goes to order_manager list_documents with customer instead. '
            . 'lookup_customer never returns a VAT or KVK number. Asked to check a customer\'s VAT or KVK '
            . 'number, do not search the customer for it: say right away that you cannot read them from the '
            . 'customer record for privacy reasons and ask the administrator for the number.';
    }

    public function getParameterSchema(): array
    {
        return [
            'search' => [
                'type' => 'string',
                'description' => 'Customer name, email address or customer id to search for; for an id, pass the '
                    . 'customer id token as it came back, or the number when the administrator typed one. Required by lookup_customer '
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
        // Direct identifiers are tokenised, not dropped: the provider sees mago://name_1 and the
        // panel shows the admin the real value. The city is part of the address, so it is tokenised
        // too; one city always gets the same token, so "which customers are in X" still groups.
        // The country stays public.
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'entity_id' => [PiiClass::TOKENISE, 'customer'],
            'name' => [PiiClass::TOKENISE, 'name'],
            'email' => [PiiClass::TOKENISE, 'email'],
            'telephone' => [PiiClass::TOKENISE, 'phone'],
            'country' => [PiiClass::PUBLIC],
            'city' => [PiiClass::TOKENISE, 'city'],
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
        $search = is_scalar($params['search'] ?? null) ? (string)$params['search'] : '';
        if (!$this->hasSearchableTerm($search)) {
            return self::SEARCH_REQUIRED;
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $limit = max(1, min((int)($params['limit'] ?? 10), 10));

        $searchParams = $this->isSingleFieldSearch($search)
            ? $this->singleFieldSearch($search, $limit)
            : $this->nameSearch($search, $limit);

        $result = $this->apiClient->get('customers/search', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $items = $result['items'] ?? [];

        if (empty($items)) {
            return ['results' => [], 'message' => 'No customers found matching "' . $search . '"'];
        }

        $customers = [];
        foreach ($items as $customer) {
            $customerId = (int)($customer['id'] ?? 0);
            $address = $customer['addresses'][0] ?? [];

            $customers[] = [
                'entity_id' => $customerId,
                'name' => implode(' ', array_filter([
                    $customer['firstname'] ?? '',
                    $customer['middlename'] ?? '',
                    $customer['lastname'] ?? '',
                ], static fn (mixed $part): bool => is_string($part) && trim($part) !== '')),
                'email' => $customer['email'] ?? '',
                'country' => $address['country_id'] ?? null,
                'city' => $address['city'] ?? null,
                'telephone' => $address['telephone'] ?? null,
                'registered' => $this->storeTime->toLocal((string)($customer['created_at'] ?? '')),
                'admin_url' => $this->secureAdminUrl->getUrl('customer/index/edit', ['id' => $customerId]),
            ];
        }

        return ['results' => $customers];
    }

    /**
     * A search of only punctuation filters nothing: Magento then returns the newest customers,
     * which reads as a match. An email search of only "@" and dots matches every address.
     *
     * @param string $search
     * @return bool
     */
    private function hasSearchableTerm(string $search): bool
    {
        if (ctype_digit(trim($search))) {
            return true;
        }

        if (str_contains($search, '@')) {
            return preg_replace('/[\s,.@]+/', '', $search) !== '';
        }

        return $this->nameWords($search) !== [];
    }

    /**
     * @param string $search
     * @return bool
     */
    private function isSingleFieldSearch(string $search): bool
    {
        return ctype_digit(trim($search)) || str_contains($search, '@');
    }

    /**
     * The assistant refers to a customer by the id it was given, so the admin asks about
     * "customer 32". Searching that as a name finds nobody, which reads as "this customer does not
     * exist" for a customer we just showed them.
     *
     * @param string $search
     * @param int $limit
     * @return array<string, mixed>
     */
    private function singleFieldSearch(string $search, int $limit): array
    {
        $filter = ctype_digit(trim($search))
            ? ['field' => 'entity_id', 'value' => trim($search), 'condition_type' => 'eq']
            : [
                'field' => 'email',
                'value' => '%' . $this->likeLiteral(trim($search)) . '%',
                'condition_type' => 'like',
            ];

        return $this->apiClient->buildSearchCriteria([$filter], $limit, 1, self::NEWEST_FIRST);
    }

    /**
     * Every word has to match the first, middle or last name: one filter group per word (groups
     * are ANDed) holding one filter per name field (filters in a group are ORed). Matching the
     * whole string against one field at a time never found "Jan Jansen" or "Sanne de Vries"
     * (issue #257), and this also finds a name typed last name first. Commas and the dot after an
     * initial separate words, so "Dekker, Haimanti" and "H. Dekker" match too.
     *
     * @param string $search
     * @param int $limit
     * @return array<string, mixed>
     */
    private function nameSearch(string $search, int $limit): array
    {
        $groups = array_map(
            fn (string $word): array => array_map(
                fn (string $field): array => [
                    'field' => $field,
                    'value' => '%' . $this->likeLiteral($word) . '%',
                    'condition_type' => 'like',
                ],
                self::NAME_FIELDS
            ),
            array_slice($this->nameWords($search), 0, self::MAX_NAME_WORDS)
        );

        return $this->apiClient->buildSearchCriteria($groups, $limit, 1, self::NEWEST_FIRST);
    }

    /**
     * @param string $search
     * @return list<string>
     */
    private function nameWords(string $search): array
    {
        $words = preg_split('/[\s,]+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $word): string => trim($word, '.'), $words),
            static fn (string $word): bool => $word !== ''
        ));
    }

    /**
     * A % or _ the admin typed is part of the name or address, not a wildcard.
     *
     * @param string $value
     * @return string
     */
    private function likeLiteral(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
