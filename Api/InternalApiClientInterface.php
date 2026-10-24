<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api;

/**
 * Runs a Magento web API route on behalf of an admin user and hands back what the REST API would
 * have answered, decoded. A failure comes back as ['error' => message], never as an exception.
 *
 * @api
 */
interface InternalApiClientInterface
{
    /**
     * @param string $endpoint Path after /V1/
     * @param array<string, mixed> $params Query parameters
     * @param int $adminUserId
     * @param string|null $storeCode Store view code to run the call in ("all" for every store view);
     *                               null keeps Magento's default store view
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $params, int $adminUserId, ?string $storeCode = null): array;

    /**
     * @param string $endpoint Path after /V1/
     * @param array<string, mixed> $body
     * @param int $adminUserId
     * @param string|null $storeCode See get()
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array;

    /**
     * Queue the call on async.operations.all instead of running it; returns the bulk_uuid
     *
     * @param string $endpoint Path after /V1/
     * @param array<string, mixed> $body
     * @param int $adminUserId
     * @param string|null $storeCode See get()
     * @return array<string, mixed>
     */
    public function postAsync(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array;

    /**
     * @param string $endpoint Path after /V1/
     * @param array<string, mixed> $body
     * @param int $adminUserId
     * @param string|null $storeCode See get()
     * @return array<string, mixed>
     */
    public function put(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array;

    /**
     * @param string $endpoint Path after /V1/
     * @param int $adminUserId
     * @param string|null $storeCode See get()
     * @return array<string, mixed>
     */
    public function delete(string $endpoint, int $adminUserId, ?string $storeCode = null): array;

    /**
     * Build searchCriteria query params from filter arrays. Each entry is one filter group (groups
     * are ANDed); an entry that is a list of filters ORs them within its group.
     *
     * @param array<int, array<string, mixed>|list<array<string, mixed>>> $filters [['field' => 'name', 'value' => '%a%', 'condition_type' => 'like']]
     * @param int $pageSize
     * @param int $currentPage
     * @param array<int, array<string, mixed>>|null $sortOrders [['field' => 'created_at', 'direction' => 'DESC']]
     * @return array<string, mixed> Query params ready for get()
     */
    public function buildSearchCriteria(
        array $filters = [],
        int $pageSize = 100,
        int $currentPage = 1,
        ?array $sortOrders = null
    ): array;
}
