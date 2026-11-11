<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\HTTP\Client\Curl;

/**
 * Answers every GET with one canned response and records the cURL options it was given, without
 * opening a connection.
 */
final class FakeCurl extends Curl
{
    /** @var array<int, mixed> */
    private array $options = [];

    /** @var list<string> */
    private array $requestedUrls = [];

    public function __construct(
        private readonly int $status,
        private readonly string $body
    ) {
        parent::__construct();
    }

    /**
     * @return array<int, mixed>
     */
    public function getRecordedOptions(): array
    {
        return $this->options;
    }

    /**
     * @return list<string>
     */
    public function getRequestedUrls(): array
    {
        return $this->requestedUrls;
    }

    /**
     * @param array<int, mixed> $arr
     */
    public function setOptions($arr): void
    {
        $this->options = $arr;
    }

    /**
     * @param int $name
     * @param mixed $value
     */
    public function setOption($name, $value): void
    {
        $this->options[$name] = $value;
    }

    /**
     * @param string $uri
     */
    public function get($uri): void
    {
        $this->requestedUrls[] = $uri;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
