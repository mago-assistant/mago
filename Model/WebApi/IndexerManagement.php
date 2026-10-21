<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\WebApi;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\StateInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Magento\Indexer\Model\Processor\MakeSharedIndexValid;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;
use MagoAssistant\Mago\Api\Data\IndexerResultInterface;
use MagoAssistant\Mago\Api\Data\IndexerResultInterfaceFactory;
use MagoAssistant\Mago\Api\WebApi\IndexerManagementInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Skills\Configuration\IndexerManager;

class IndexerManagement implements IndexerManagementInterface
{
    private const RESULT_REBUILT = 'rebuilt';
    private const RESULT_SHARED = 'shared';
    private const RESULT_LOCKED = 'locked';
    private const RESULT_FAILED = 'failed';

    public function __construct(
        private readonly CollectionFactory $indexerCollectionFactory,
        private readonly ConfigInterface $config,
        private readonly MakeSharedIndexValid $makeSharedIndexValid,
        private readonly IndexerResultInterfaceFactory $indexerResultFactory,
        private readonly ErrorReporter $errorReporter,
        private readonly UserContextInterface $userContext,
        private readonly ConfigRepository $configRepository,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    public function reindexAll(): array
    {
        $this->requireAdminUser();
        $this->requireReindexAllowed();
        return $this->rebuild($this->getIndexers());
    }

    public function reindex(array $indexerIds): array
    {
        $this->requireAdminUser();
        $this->requireReindexAllowed();
        if ($indexerIds === []) {
            throw new InputException(__(
                'Name at least one indexer. Rebuilding every indexer is POST /V1/mago/indexers/reindex-all.'
            ));
        }
        $indexers = $this->getIndexers($indexerIds);
        $this->requireReindexAllGrantForEveryIndexer($indexers);

        return $this->rebuild($indexers);
    }

    /**
     * Naming every indexer is a reindex-all by another route, so it needs the same grant: without
     * this, Magento_Indexer::invalidate alone would rebuild everything synchronously.
     *
     * @param IndexerInterface[] $indexers
     * @throws AuthorizationException
     */
    private function requireReindexAllGrantForEveryIndexer(array $indexers): void
    {
        if (count($indexers) < count($this->getIndexers())
            || $this->authorization->isAllowed(IndexerManager::REINDEX_ALL_RESOURCE)) {
            return;
        }

        throw new AuthorizationException(__(
            'Rebuilding every indexer needs the "Reindex All Indexers" permission.'
        ));
    }

    /**
     * @return void
     * @throws AuthorizationException
     */
    private function requireAdminUser(): void
    {
        $userType = $this->userContext->getUserType();
        if ($userType !== null && (int)$userType !== UserContextInterface::USER_TYPE_ADMIN) {
            throw new AuthorizationException(__('This endpoint requires an admin user token.'));
        }
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    private function requireReindexAllowed(): void
    {
        if (!$this->configRepository->isReindexAllowed()) {
            throw new LocalizedException(__(
                'Reindexing is disabled. Enable it in '
                . 'Stores > Configuration > Mago Assistant > Tools > Allow reindexing.'
            ));
        }
    }

    /**
     * @param IndexerInterface[] $indexers
     * @return IndexerResultInterface[]
     */
    private function rebuild(array $indexers): array
    {
        $result = [];
        $rebuiltSharedIndexes = [];

        foreach ($indexers as $indexer) {
            $indexerId = $indexer->getId();
            if ($indexer->getStatus() === StateInterface::STATUS_WORKING) {
                $result[] = $this->toResult($indexer, self::RESULT_LOCKED);
                continue;
            }

            $sharedIndex = $this->getSharedIndex($indexerId);
            if ($sharedIndex && in_array($sharedIndex, $rebuiltSharedIndexes)) {
                $result[] = $this->toResult($indexer, self::RESULT_SHARED);
                continue;
            }

            try {
                $indexer->reindexAll();
                if ($sharedIndex && $this->makeSharedIndexValid->execute($sharedIndex)) {
                    $rebuiltSharedIndexes[] = $sharedIndex;
                }
                $result[] = $this->toResult($indexer, self::RESULT_REBUILT);
            } catch (\Throwable $e) {
                $this->errorReporter->log('IndexerManagement::rebuild ' . $indexerId, $e);
                $result[] = $this->toResult($indexer, self::RESULT_FAILED);
            }
        }

        return $result;
    }

    private function toResult(IndexerInterface $indexer, string $result): IndexerResultInterface
    {
        $indexerResult = $this->indexerResultFactory->create();
        $indexerResult->setId($indexer->getId());
        $indexerResult->setTitle($indexer->getTitle());
        $indexerResult->setResult($result);

        return $indexerResult;
    }

    /**
     * @param string[] $indexerIds
     * @return IndexerInterface[]
     * @throws InputException
     */
    private function getIndexers(array $indexerIds = []): array
    {
        $available = [];
        foreach ($this->indexerCollectionFactory->create() as $indexer) {
            $available[$indexer->getId()] = $indexer;
        }
        if ($indexerIds === []) {
            return array_values($available);
        }

        $unknown = array_diff($indexerIds, array_keys($available));
        if ($unknown !== []) {
            $known = implode(', ', array_keys($available));
            $message = __(
                'Unknown indexer(s): %1. Available: %2',
                implode(', ', $unknown),
                $known
            );

            throw new InputException($message);
        }

        $indexers = [];
        foreach (array_unique($indexerIds) as $indexerId) {
            $indexers[] = $available[$indexerId];
        }

        return $indexers;
    }

    private function getSharedIndex(string $indexerId): string
    {
        return $this->config->getIndexer($indexerId)['shared_index'] ?? '';
    }
}
