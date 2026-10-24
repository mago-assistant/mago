<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

final readonly class ApiCall
{
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_DELETE = 'DELETE';

    private const PATH_PREFIX = '/V1/';

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    public function __construct(
        public string $httpMethod,
        public string $endpoint,
        public array $query,
        public array $body,
        public int $adminUserId,
        public ?string $storeCode
    ) {
    }

    public function getPath(): string
    {
        return self::PATH_PREFIX . ltrim($this->endpoint, '/');
    }

    public function hasBody(): bool
    {
        return $this->httpMethod === self::METHOD_POST || $this->httpMethod === self::METHOD_PUT;
    }
}
