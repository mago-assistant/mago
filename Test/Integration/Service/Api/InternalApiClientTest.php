<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Api;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\AsyncQueueInterface;
use MagoAssistant\Mago\Service\Api\InProcess\ConnectionTransaction;
use MagoAssistant\Mago\Service\Api\InProcess\ConnectionTransactionInterface;
use MagoAssistant\Mago\Service\Api\InProcess\ExceptionMasker;
use MagoAssistant\Mago\Service\Api\InProcess\ExceptionMaskerInterface;
use MagoAssistant\Mago\Service\Api\InProcess\RouteResolver;
use MagoAssistant\Mago\Service\Api\InProcess\WebapiAsyncQueue;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs real routes in-process against the installed store. The preferences the client needs are
 * set here too, so the suite also runs from a worktree whose di.xml the store does not load. Writes
 * happen inside a transaction that is rolled back.
 */
final class InternalApiClientTest extends TestCase
{
    private ObjectManagerInterface $objectManager;
    private InternalApiClient $client;
    private int $adminUserId;

    protected function setUp(): void
    {
        $this->objectManager = MagentoObjectManager::get();
        $this->objectManager->configure([
            'preferences' => [
                ExceptionMaskerInterface::class => ExceptionMasker::class,
                ConnectionTransactionInterface::class => ConnectionTransaction::class,
                AsyncQueueInterface::class => WebapiAsyncQueue::class,
            ],
        ]);
        $this->client = $this->objectManager->create(InternalApiClient::class);
        $this->adminUserId = $this->getActiveAdminUserId();
    }

    #[Test]
    public function itPrefersAnExactRouteOverOneWithAPathParameter(): void
    {
        $route = $this->routeResolver()->resolve($this->call(ApiCall::METHOD_GET, 'cmsBlock/search'));

        self::assertSame(BlockRepositoryInterface::class, $route->serviceClass);
        self::assertSame('getList', $route->serviceMethod);
    }

    #[Test]
    public function itPassesAPathParameterToTheService(): void
    {
        $route = $this->routeResolver()->resolve($this->call(ApiCall::METHOD_GET, 'cmsBlock/5'));

        self::assertSame('getById', $route->serviceMethod);
        self::assertSame('5', $route->inputData['blockId']);
    }

    #[Test]
    public function itPutsThePathIdIntoTheBodyOfAnUpdate(): void
    {
        $route = $this->routeResolver()->resolve(
            new ApiCall(ApiCall::METHOD_PUT, 'cmsPage/7', [], ['page' => ['title' => 'New']], $this->adminUserId, null)
        );

        self::assertSame(PageRepositoryInterface::class, $route->serviceClass);
        self::assertSame('7', (string)$route->inputData['page']['id']);
    }

    /**
     * The chat itself runs inside a request (the admin page, or POST /V1/mago/chat) whose query and
     * post data must never end up in a tool's call.
     */
    #[Test]
    public function itIgnoresTheQueryOfTheRequestRunningTheChat(): void
    {
        $outerQuery = $_GET;
        $_GET = ['searchCriteria' => ['pageSize' => 1], 'injected' => 'yes'];

        try {
            $route = $this->routeResolver()->resolve(
                new ApiCall(ApiCall::METHOD_GET, 'cmsBlock/search', ['searchCriteria[pageSize]' => 3], [], 1, null)
            );
        } finally {
            $_GET = $outerQuery;
        }

        self::assertSame('3', (string)$route->inputData['searchCriteria']['pageSize']);
        self::assertArrayNotHasKey('injected', $route->inputData);
    }

    #[Test]
    public function itSearchesWithTheFlatCriteriaBuildSearchCriteriaReturns(): void
    {
        $result = $this->client->get('cmsPage/search', $this->client->buildSearchCriteria([], 2), $this->adminUserId);

        self::assertArrayNotHasKey('error', $result);
        self::assertSame(2, $result['search_criteria']['page_size']);
    }

    #[Test]
    public function itReportsAnUnknownRouteAsNotFound(): void
    {
        self::assertSame(['error' => 'Resource not found'], $this->client->get('nothing/here', [], $this->adminUserId));
    }

    #[Test]
    public function itRefusesAnAdminUserThatDoesNotExist(): void
    {
        self::assertSame(
            ['error' => 'You do not have permission to access this data. The admin user is not active.'],
            $this->client->get('store/websites', [], 999999)
        );
    }

    #[Test]
    public function itCreatesACmsPageForAllStoreViews(): void
    {
        $connection = $this->objectManager->get(ResourceConnection::class)->getConnection();
        $connection->beginTransaction();

        try {
            $result = $this->client->post('cmsPage', ['page' => [
                'identifier' => 'mago-integration-in-process',
                'title' => 'In-process',
                'content' => '<p>In-process</p>',
                'active' => true,
            ]], $this->adminUserId, 'all');
            $storeIds = $connection->fetchCol(
                'SELECT store_id FROM ' . $connection->getTableName('cms_page_store') . ' WHERE page_id = ?',
                [(int)($result['id'] ?? 0)]
            );
        } finally {
            $connection->rollBack();
        }

        self::assertSame('mago-integration-in-process', $result['identifier'] ?? $result);
        self::assertSame(['0'], array_map('strval', $storeIds));
    }

    private function routeResolver(): RouteResolver
    {
        return $this->objectManager->create(RouteResolver::class);
    }

    private function call(string $method, string $endpoint): ApiCall
    {
        return new ApiCall($method, $endpoint, [], [], $this->adminUserId, null);
    }

    private function getActiveAdminUserId(): int
    {
        $userId = (int)$this->objectManager->get(UserCollectionFactory::class)->create()
            ->addFieldToFilter('is_active', 1)
            ->getFirstItem()
            ->getId();
        if ($userId === 0) {
            self::markTestSkipped('The store has no active admin user.');
        }

        return $userId;
    }
}
