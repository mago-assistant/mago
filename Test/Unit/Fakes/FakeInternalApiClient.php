<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Service\Api\SearchCriteriaQuery;

final class FakeInternalApiClient implements InternalApiClientInterface
{
    public const GET = 'GET';
    public const POST = 'POST';
    public const POST_ASYNC = 'POST_ASYNC';
    public const PUT = 'PUT';
    public const DELETE = 'DELETE';

    /** @var array<string, array<string, mixed>> */
    private array $responses = [];

    /** @var array<string, array<string, mixed>> */
    private array $methodResponses = [];

    /** @var list<array{method: string, endpoint: string, payload: array<string, mixed>, admin_user_id: int, store_code: ?string}> */
    private array $calls = [];

    /**
     * @param array<string, mixed> $response
     */
    public function withResponse(string $method, string $endpoint, array $response): self
    {
        $this->responses[$method . ' ' . $endpoint] = $response;

        return $this;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function withResponseForEvery(string $method, array $response): self
    {
        $this->methodResponses[$method] = $response;

        return $this;
    }

    /**
     * @return list<array{method: string, endpoint: string, payload: array<string, mixed>, admin_user_id: int, store_code: ?string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<array{method: string, endpoint: string, payload: array<string, mixed>, admin_user_id: int, store_code: ?string}>
     */
    public function callsOf(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }

    public function get(string $endpoint, array $params, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->record(self::GET, $endpoint, $params, $adminUserId, $storeCode);
    }

    public function post(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->record(self::POST, $endpoint, $body, $adminUserId, $storeCode);
    }

    public function postAsync(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->record(self::POST_ASYNC, $endpoint, $body, $adminUserId, $storeCode);
    }

    public function put(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->record(self::PUT, $endpoint, $body, $adminUserId, $storeCode);
    }

    public function delete(string $endpoint, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->record(self::DELETE, $endpoint, [], $adminUserId, $storeCode);
    }

    public function buildSearchCriteria(
        array $filters = [],
        int $pageSize = 100,
        int $currentPage = 1,
        ?array $sortOrders = null
    ): array {
        return (new SearchCriteriaQuery())->build($filters, $pageSize, $currentPage, $sortOrders);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function record(string $method, string $endpoint, array $payload, int $adminUserId, ?string $storeCode): array
    {
        $this->calls[] = [
            'method' => $method,
            'endpoint' => $endpoint,
            'payload' => $payload,
            'admin_user_id' => $adminUserId,
            'store_code' => $storeCode,
        ];

        return $this->responses[$method . ' ' . $endpoint] ?? $this->methodResponses[$method] ?? [];
    }
}
