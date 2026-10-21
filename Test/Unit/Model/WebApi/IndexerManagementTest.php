<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\WebApi;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Magento\Indexer\Model\Processor\MakeSharedIndexValid;
use MagoAssistant\Mago\Api\Data\IndexerResultInterface;
use MagoAssistant\Mago\Api\Data\IndexerResultInterfaceFactory;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Model\Data\IndexerResult;
use MagoAssistant\Mago\Model\WebApi\IndexerManagement;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\InputException;
use MagoAssistant\Mago\Service\Skills\Configuration\IndexerManager;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;

final class IndexerManagementTest extends TestCase
{
    private FakeLogger $logger;
    private MakeSharedIndexValid&MockObject $makeSharedIndexValid;

    protected function setUp(): void
    {
        $this->logger = new FakeLogger();
        $this->makeSharedIndexValid = $this->createMock(MakeSharedIndexValid::class);
    }

    #[Test]
    public function aFailingIndexerDoesNotStopTheOthers(): void
    {
        $search = $this->indexer('catalogsearch_fulltext');
        $search->method('reindexAll')->willThrowException(new \RuntimeException('OpenSearch is down'));
        $price = $this->indexer('catalog_product_price');
        $price->expects(self::once())->method('reindexAll');

        $result = $this->management([$search, $price])->reindexAll();

        self::assertSame(
            ['catalogsearch_fulltext' => 'failed', 'catalog_product_price' => 'rebuilt'],
            $this->results($result)
        );
        self::assertCount(1, $this->logger->getMessages());
        self::assertStringStartsWith(
            'IndexerManagement::rebuild catalogsearch_fulltext: [',
            $this->logger->getMessages()[0]
        );
        self::assertStringContainsString('OpenSearch is down', $this->logger->getMessages()[0]);
    }

    #[Test]
    public function aFailingSharedIndexValidationDoesNotStopTheOthers(): void
    {
        $stock = $this->indexer('cataloginventory_stock');
        $price = $this->indexer('catalog_product_price');
        $price->expects(self::once())->method('reindexAll');
        $this->makeSharedIndexValid->method('execute')->willThrowException(new \RuntimeException('Deadlock'));

        $result = $this->management([$stock, $price], ['cataloginventory_stock' => 'inventory'])->reindexAll();

        self::assertSame(
            ['cataloginventory_stock' => 'failed', 'catalog_product_price' => 'rebuilt'],
            $this->results($result)
        );
    }

#[Test]
    public function aReindexWithoutIndexersIsRefusedInsteadOfRebuildingEverything(): void
    {
        $management = $this->management([$this->indexer('catalog_product_price')]);

        $this->expectException(InputException::class);

        $management->reindex([]);
    }

    #[Test]
    public function namingEveryIndexerNeedsTheReindexAllGrant(): void
    {
        $management = $this->management(
            [$this->indexer('catalog_product_price'), $this->indexer('cataloginventory_stock')],
            [],
            false
        );

        $this->expectException(AuthorizationException::class);

        $management->reindex(['catalog_product_price', 'cataloginventory_stock']);
    }

    #[Test]
    public function namingEveryIndexerWorksWithTheReindexAllGrant(): void
    {
        $management = $this->management(
            [$this->indexer('catalog_product_price'), $this->indexer('cataloginventory_stock')]
        );

        $results = $management->reindex(['catalog_product_price', 'cataloginventory_stock']);

        self::assertCount(2, $results);
    }

    #[Test]
    public function namingSomeIndexersNeedsNoReindexAllGrant(): void
    {
        $management = $this->management(
            [$this->indexer('catalog_product_price'), $this->indexer('cataloginventory_stock')],
            [],
            false
        );

        $results = $management->reindex(['catalog_product_price', 'catalog_product_price']);

        self::assertCount(1, $results);
    }

        private function indexer(string $id): IndexerInterface&MockObject
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getId')->willReturn($id);
        $indexer->method('getTitle')->willReturn($id);
        $indexer->method('getStatus')->willReturn(StateInterface::STATUS_VALID);

        return $indexer;
    }

    /**
     * @param IndexerInterface[] $indexers
     * @param array<string, string> $sharedIndexes
     */
    private function management(
        array $indexers,
        array $sharedIndexes = [],
        bool $canReindexAll = true
    ): IndexerManagement {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($indexers);
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getIndexer')->willReturnCallback(
            static fn (string $id): array => ['shared_index' => $sharedIndexes[$id] ?? null]
        );
        $resultFactory = $this->createMock(IndexerResultInterfaceFactory::class);
        $resultFactory->method('create')->willReturnCallback(
            static fn (): IndexerResult => new IndexerResult()
        );

        return new IndexerManagement(
            $collectionFactory,
            $config,
            $this->makeSharedIndexValid,
            $resultFactory,
            new ErrorReporter(new ErrorLogger($this->logger, new Json()), new PiiHeuristic()),
            $this->createMock(UserContextInterface::class),
            (new FakeConfigRepository())->withReindexAllowed(true),
            new FakeAclAuthorization($canReindexAll ? [IndexerManager::REINDEX_ALL_RESOURCE] : [])
        );
    }

    /**
     * @param IndexerResultInterface[] $results
     * @return array<string, string>
     */
    private function results(array $results): array
    {
        $byId = [];
        foreach ($results as $result) {
            $byId[$result->getId()] = $result->getResult();
        }

        return $byId;
    }
}
