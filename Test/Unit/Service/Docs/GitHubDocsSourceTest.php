<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Docs;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Docs\GitHubDocsSource;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GitHubDocsSourceTest extends TestCase
{
    private const REPO = 'AdobeDocs/commerce-admin.en';
    private const REF = 'main';
    private const TREE_URL = 'https://api.github.com/repos/AdobeDocs/commerce-admin.en/git/trees/main?recursive=1';
    private const RAW_URL = 'https://raw.githubusercontent.com/AdobeDocs/commerce-admin.en/main/help/catalog/products.md';

    private FakeHttpClient $httpClient;

    private FakeLogger $logger;

    protected function setUp(): void
    {
        $this->httpClient = new FakeHttpClient();
        $this->logger = new FakeLogger();
    }

    #[Test]
    public function itReturnsTheBodyOfADownloadedFile(): void
    {
        $this->httpClient->withResponse(self::RAW_URL, 200, 'Products are what you sell.');

        $body = $this->docsSource()->fetchRaw(self::REPO, self::REF, 'help/catalog/products.md');

        self::assertSame('Products are what you sell.', $body);
    }

    #[Test]
    public function itIdentifiesItselfAndAsksForTheExpectedContentType(): void
    {
        $this->httpClient->withResponse(self::RAW_URL, 200, '');

        $this->docsSource()->fetchRaw(self::REPO, self::REF, 'help/catalog/products.md');

        self::assertSame(
            ['User-Agent' => 'MagoAssistant-Mago', 'Accept' => 'text/plain'],
            $this->httpClient->getRequests()[0]->getHeaders()
        );
    }

    #[Test]
    public function itReturnsNullAndLogsTheStatusWhenGitHubAnswersWithAnError(): void
    {
        $this->httpClient->withResponse(self::RAW_URL, 403, 'rate limited');

        $body = $this->docsSource()->fetchRaw(self::REPO, self::REF, 'help/catalog/products.md');

        self::assertNull($body);
        self::assertStringContainsString('HTTP 403 for ' . self::RAW_URL, implode("\n", $this->logger->getMessages()));
    }

    #[Test]
    public function itReturnsNullAndLogsTheErrorWhenTheRequestFails(): void
    {
        $this->httpClient->failingOn(self::RAW_URL);

        $body = $this->docsSource()->fetchRaw(self::REPO, self::REF, 'help/catalog/products.md');

        self::assertNull($body);
        self::assertStringContainsString('Operation timed out', implode("\n", $this->logger->getMessages()));
    }

    #[Test]
    public function itListsOnlyTheMarkdownFilesUnderHelp(): void
    {
        $this->httpClient->withResponse(self::TREE_URL, 200, (string)json_encode([
            'sha' => 'tree-sha',
            'tree' => [
                ['type' => 'blob', 'path' => 'help/catalog/products.md'],
                ['type' => 'blob', 'path' => 'help/catalog/image.png'],
                ['type' => 'tree', 'path' => 'help/catalog'],
                ['type' => 'blob', 'path' => 'README.md'],
            ],
        ]));

        $tree = $this->docsSource()->fetchTree(self::REPO, self::REF);

        self::assertSame(['sha' => 'tree-sha', 'paths' => ['help/catalog/products.md']], $tree);
    }

    private function docsSource(): GitHubDocsSource
    {
        $errorLogger = new ErrorLogger($this->logger, new Json());

        return new GitHubDocsSource(
            new Json(),
            $this->httpClient,
            $errorLogger,
            new ErrorReporter($errorLogger, new PiiHeuristic())
        );
    }
}
