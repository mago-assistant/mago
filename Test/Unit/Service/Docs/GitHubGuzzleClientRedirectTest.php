<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Docs;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Security audit 2026-11-02, finding 3: the docs client only ever asks GitHub, so an answer that
 * redirects to another host must not make the server call that host.
 */
final class GitHubGuzzleClientRedirectTest extends TestCase
{
    private const DI_XML = __DIR__ . '/../../../../etc/di.xml';
    private const CLIENT_NAME = 'MagoAssistant\Mago\Service\Docs\GitHubGuzzleClient';
    private const RAW_URL = 'https://raw.githubusercontent.com/mage-os/mirror-commerce-admin.en/main/help/a.md';
    private const FOREIGN_URL = 'https://internal.example.test/secret';
    private const HTTP_FOUND = 302;
    private const HTTP_OK = 200;

    #[Test]
    public function itNeverRequestsAHostThatGitHubRedirectsTo(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(self::HTTP_FOUND, ['Location' => self::FOREIGN_URL]),
            new Response(self::HTTP_OK, [], 'internal'),
        ]));
        $handler->push(Middleware::history($history));

        try {
            (new Client(['handler' => $handler] + $this->getConfiguredClientOptions()))->get(self::RAW_URL);
        } catch (GuzzleException) {
            self::assertNotSame([], $history);
        }

        self::assertSame(
            ['raw.githubusercontent.com'],
            array_map(
                fn (array $transaction): string => $this->getHost($transaction['request']),
                $history
            ),
            'The docs client followed a redirect away from GitHub.'
        );
    }

    private function getHost(RequestInterface $request): string
    {
        return $request->getUri()->getHost();
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfiguredClientOptions(): array
    {
        $document = new \DOMDocument();
        $document->load(self::DI_XML);
        $config = (new \DOMXPath($document))->query(
            '/config/virtualType[@name="' . self::CLIENT_NAME . '"]/arguments/argument[@name="config"]'
        );
        self::assertNotFalse($config);
        self::assertSame(1, $config->length, 'The docs Guzzle client is no longer configured in etc/di.xml.');

        return $this->getArrayValue($config->item(0));
    }

    /**
     * @return array<string, mixed>
     */
    private function getArrayValue(\DOMNode $node): array
    {
        $items = array_filter(
            iterator_to_array($node->childNodes),
            static fn (\DOMNode $child): bool => $child instanceof \DOMElement && $child->tagName === 'item'
        );

        return array_reduce(
            $items,
            fn (array $values, \DOMElement $item): array => $values + [
                $item->getAttribute('name') => $this->getItemValue($item),
            ],
            []
        );
    }

    /**
     * @return array<string, mixed>|bool|float|string
     */
    private function getItemValue(\DOMElement $item): array|bool|float|string
    {
        return match ($item->getAttribute('xsi:type')) {
            'array' => $this->getArrayValue($item),
            'number' => (float)trim($item->textContent),
            'boolean' => trim($item->textContent) === 'true',
            default => trim($item->textContent),
        };
    }
}
