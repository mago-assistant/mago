<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Docs;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Docs\DocsSyncService;
use MagoAssistant\Mago\Service\Docs\ExlMarkdownNormalizer;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocsSyncServiceTest extends TestCase
{
    private const REPO = 'AdobeDocs/commerce-admin.en';
    private const REF = 'main';
    private const OLD_SHA = 'old-sha';
    private const NEW_SHA = 'new-sha';

    private FakeDocRepository $docRepository;

    private FakeFlagManager $flagManager;

    private FakeLogger $logger;

    protected function setUp(): void
    {
        $this->docRepository = (new FakeDocRepository())->withRows([['path' => 'help/old.md']]);
        $this->flagManager = new FakeFlagManager();
        $this->flagManager->saveFlag('mago_docs_source_sha', self::OLD_SHA);
        $this->logger = new FakeLogger();
    }

    #[Test]
    public function aCompleteFetchReplacesTheCorpusAndRecordsTheCommit(): void
    {
        $source = $this->sourceWithTwoFiles();

        $result = $this->syncService($source)->sync();

        self::assertSame(['indexed' => 2, 'sha' => self::NEW_SHA], $result);
        self::assertCount(2, $this->docRepository->getRows());
        self::assertSame(self::NEW_SHA, $this->flagManager->getFlagData('mago_docs_source_sha'));
        self::assertNull($this->flagManager->getFlagData('mago_docs_last_error'));
    }

    #[Test]
    public function aFileThatFailsToFetchKeepsTheCorpusAndLeavesTheCommitForTheNextRun(): void
    {
        $source = $this->sourceWithTwoFiles()->failingOn('help/catalog/products.md');

        $result = $this->syncService($source)->sync();

        self::assertStringContainsString('help/catalog/products.md', (string)$result['error']);
        self::assertSame([['path' => 'help/old.md']], $this->docRepository->getRows());
        self::assertSame(self::OLD_SHA, $this->flagManager->getFlagData('mago_docs_source_sha'));
        self::assertStringContainsString('reference', (string)$this->flagManager->getFlagData('mago_docs_last_error'));
        self::assertStringContainsString('help/catalog/products.md', implode("\n", $this->logger->getMessages()));
    }

    private function sourceWithTwoFiles(): FakeDocsSource
    {
        return (new FakeDocsSource(self::NEW_SHA))
            ->withFile('help/catalog/categories.md', "---\ntitle: Categories\n---\n\nCategories group products.")
            ->withFile('help/catalog/products.md', "---\ntitle: Products\n---\n\nProducts are what you sell.");
    }

    private function syncService(FakeDocsSource $source): DocsSyncService
    {
        return new DocsSyncService(
            (new FakeConfigRepository())->withDocsSource(self::REPO, self::REF),
            $source,
            new ExlMarkdownNormalizer(),
            $this->docRepository,
            $this->flagManager,
            new ErrorReporter(new ErrorLogger($this->logger, new Json()), new PiiHeuristic()),
            new FakeLockManager()
        );
    }
}
