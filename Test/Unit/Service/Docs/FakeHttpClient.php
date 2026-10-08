<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Docs;

use Magento\Framework\HTTP\AsyncClient\HttpException;
use Magento\Framework\HTTP\AsyncClient\HttpResponseDeferredInterface;
use Magento\Framework\HTTP\AsyncClient\Request;
use Magento\Framework\HTTP\AsyncClient\Response;
use Magento\Framework\HTTP\AsyncClientInterface;

/**
 * Answers from canned responses per URL and records every request it gets; an unknown URL is a 404
 */
final class FakeHttpClient implements AsyncClientInterface
{
    private const HTTP_NOT_FOUND = 404;

    /** @var array<string, Response> */
    private array $responses = [];

    /** @var array<string, true> */
    private array $failingUrls = [];

    /** @var list<Request> */
    private array $requests = [];

    public function withResponse(string $url, int $statusCode, string $body): self
    {
        $this->responses[$url] = new Response($statusCode, [], $body);

        return $this;
    }

    public function failingOn(string $url): self
    {
        $this->failingUrls[$url] = true;

        return $this;
    }

    public function request(Request $request): HttpResponseDeferredInterface
    {
        $this->requests[] = $request;
        $url = $request->getUrl();

        if (isset($this->failingUrls[$url])) {
            return FakeHttpResponseDeferred::failed(new HttpException('cURL error 28: Operation timed out'));
        }

        return FakeHttpResponseDeferred::resolved(
            $this->responses[$url] ?? new Response(self::HTTP_NOT_FOUND, [], '')
        );
    }

    /**
     * @return list<Request>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }
}
