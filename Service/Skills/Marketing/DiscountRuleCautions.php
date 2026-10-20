<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing;

use MagoAssistant\Mago\Api\InternalApiClientInterface;

/**
 * The caution card's words for a new cart or catalog price rule: who it reaches and for how long,
 * with the websites and customer groups by name, guests called out, and the dates it runs.
 *
 * Both rule actions default to every website and every customer group, guests included, active
 * at once with no end date. Spelled out, that default is what the admin weighs before allowing it.
 */
class DiscountRuleCautions
{
    private const GUEST_GROUP_ID = 0;
    private const ADMIN_WEBSITE_ID = 0;
    private const GROUP_PAGE_SIZE = 100;

    public function __construct(
        private readonly InternalApiClientInterface $apiClient
    ) {
    }

    /**
     * @param int[]|null $websiteIds null when the rule takes every website
     * @param int[]|null $customerGroupIds null when the rule takes every customer group
     * @return string[]
     */
    public function describeScope(
        ?array $websiteIds,
        ?array $customerGroupIds,
        string $fromDate,
        string $toDate,
        int $adminUserId
    ): array {
        return [
            $this->describeWebsites($websiteIds, $adminUserId),
            $this->describeCustomerGroups($customerGroupIds, $adminUserId),
            $this->describeDates($fromDate, $toDate),
        ];
    }

    /**
     * A discount amount as the admin would type it: 20 rather than 20.0, 12.5 rather than 12.50
     */
    public function formatAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.');
    }

    /**
     * @param int[]|null $websiteIds
     */
    private function describeWebsites(?array $websiteIds, int $adminUserId): string
    {
        $names = $this->websiteNames($adminUserId);
        if ($websiteIds === null) {
            return $names === []
                ? 'Applies on every website.'
                : 'Applies on every website: ' . implode(', ', $names) . '.';
        }

        $chosen = array_map(
            static fn (int $id): string => $names[$id] ?? 'website ' . $id,
            array_map('intval', $websiteIds)
        );

        return $chosen === []
            ? 'Applies on no website.'
            : 'Applies on ' . (count($chosen) === 1 ? 'website ' : 'websites ') . implode(', ', $chosen) . '.';
    }

    /**
     * @param int[]|null $customerGroupIds
     */
    private function describeCustomerGroups(?array $customerGroupIds, int $adminUserId): string
    {
        if ($customerGroupIds === null) {
            return 'Applies to every customer group, guests (NOT LOGGED IN) included.';
        }

        $ids = array_map('intval', $customerGroupIds);
        $names = $this->customerGroupNames($adminUserId);
        $chosen = implode(', ', array_map(static fn (int $id): string => $names[$id] ?? 'group ' . $id, $ids));
        if ($chosen === '') {
            return 'Applies to no customer group.';
        }

        $groups = (count($ids) === 1 ? 'Applies to customer group ' : 'Applies to customer groups ') . $chosen;

        return in_array(self::GUEST_GROUP_ID, $ids, true)
            ? $groups . ', so guests get it too.'
            : $groups . '.';
    }

    private function describeDates(string $fromDate, string $toDate): string
    {
        // When a catalog rule starts changing prices depends on its indexer, so the dates are all
        // this says about timing
        return match (true) {
            $fromDate === '' && $toDate === '' => 'No start or end date: it runs until someone switches it off.',
            $toDate === '' => 'Starts on ' . $fromDate . ', with no end date.',
            $fromDate === '' => 'No start date, ends on ' . $toDate . '.',
            default => 'Runs from ' . $fromDate . ' until ' . $toDate . '.',
        };
    }

    /**
     * @return array<int, string> Website name by id, the admin website left out
     */
    private function websiteNames(int $adminUserId): array
    {
        $result = $this->apiClient->get('store/websites', [], $adminUserId);
        if (isset($result['error'])) {
            return [];
        }

        $websites = array_filter(
            $result,
            static fn (mixed $website): bool => is_array($website)
                && (int)($website['id'] ?? self::ADMIN_WEBSITE_ID) !== self::ADMIN_WEBSITE_ID
        );

        return array_column(
            array_map(
                static fn (array $website): array => [
                    'id' => (int)$website['id'],
                    'name' => (string)($website['name'] ?? $website['code'] ?? $website['id']),
                ],
                $websites
            ),
            'name',
            'id'
        );
    }

    /**
     * @return array<int, string> Customer group code by id
     */
    private function customerGroupNames(int $adminUserId): array
    {
        $result = $this->apiClient->get(
            'customerGroups/search',
            $this->apiClient->buildSearchCriteria([], self::GROUP_PAGE_SIZE),
            $adminUserId
        );
        if (isset($result['error'])) {
            return [];
        }

        return array_column(
            array_map(
                static fn (array $group): array => [
                    'id' => (int)($group['id'] ?? 0),
                    'code' => (string)($group['code'] ?? ''),
                ],
                array_filter($result['items'] ?? [], 'is_array')
            ),
            'code',
            'id'
        );
    }
}
