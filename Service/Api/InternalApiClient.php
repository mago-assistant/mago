<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api;

use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\AsyncScheduler;
use MagoAssistant\Mago\Service\Api\InProcess\ServiceDispatcher;

/**
 * Runs the store's web API routes in this PHP process, as the admin user a tool acts for. Nothing goes
 * over HTTP: no admin token is minted, no internal URL is called, and the admin's ACL is checked against
 * every resource of the route, as REST does.
 */
class InternalApiClient implements InternalApiClientInterface
{
    private const ASYNC_PATH_PREFIX = '/async';

    public function __construct(
        private readonly ServiceDispatcher $serviceDispatcher,
        private readonly AsyncScheduler $asyncScheduler,
        private readonly SearchCriteriaQuery $searchCriteriaQuery,
        private readonly DebugLogger $debugLogger
    ) {
    }

    public function get(string $endpoint, array $params, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->dispatch(new ApiCall(ApiCall::METHOD_GET, $endpoint, $params, [], $adminUserId, $storeCode));
    }

    public function post(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->dispatch(new ApiCall(ApiCall::METHOD_POST, $endpoint, [], $body, $adminUserId, $storeCode));
    }

    public function postAsync(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array
    {
        $call = new ApiCall(ApiCall::METHOD_POST, $endpoint, [], $body, $adminUserId, $storeCode);
        $this->logRequest(self::ASYNC_PATH_PREFIX . $call->getPath(), $call);

        return $this->logResponse($this->asyncScheduler->schedule($call));
    }

    public function put(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->dispatch(new ApiCall(ApiCall::METHOD_PUT, $endpoint, [], $body, $adminUserId, $storeCode));
    }

    public function delete(string $endpoint, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->dispatch(new ApiCall(ApiCall::METHOD_DELETE, $endpoint, [], [], $adminUserId, $storeCode));
    }

    public function buildSearchCriteria(
        array $filters = [],
        int $pageSize = 100,
        int $currentPage = 1,
        ?array $sortOrders = null
    ): array {
        return $this->searchCriteriaQuery->build($filters, $pageSize, $currentPage, $sortOrders);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function dispatch(ApiCall $call): array
    {
        $this->logRequest($call->getPath(), $call);

        return $this->logResponse($this->serviceDispatcher->dispatch($call));
    }

    private function logRequest(string $path, ApiCall $call): void
    {
        $this->debugLogger->addLog('InternalAPI Request', [
            'method' => $call->httpMethod,
            'path' => $path,
            'store_code' => $call->storeCode,
            'admin_user_id' => $call->adminUserId,
            'query_keys' => array_keys($call->query),
            'body_keys' => array_keys($call->body),
        ]);
    }

    /**
     * @param array<array-key, mixed> $response
     * @return array<array-key, mixed>
     */
    private function logResponse(array $response): array
    {
        $this->debugLogger->addLog('InternalAPI Response', isset($response['error'])
            ? ['error' => $response['error']]
            : ['result_keys' => array_slice(array_keys($response), 0, 20)]);

        return $response;
    }
}
