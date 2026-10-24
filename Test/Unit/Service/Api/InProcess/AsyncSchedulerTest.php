<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess;

use Magento\Framework\Exception\BulkException;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\AsyncScheduler;
use MagoAssistant\Mago\Service\Api\InProcess\ErrorMapper;
use MagoAssistant\Mago\Service\Api\InProcess\OutputNormalizer;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;
use MagoAssistant\Mago\Service\Api\InProcess\RouteAuthorizer;
use MagoAssistant\Mago\Service\Api\InProcess\StoreEmulation;
use MagoAssistant\Mago\Service\Api\InProcess\TransactionBoundary;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAdminAuthorizationFactory;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAsyncQueue;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAsyncResponse;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAsyncResponseProcessor;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConnectionTransaction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeExceptionMasker;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLocaleResolver;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeRouteResolver;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeServiceInputProcessor;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeStore;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeStoreManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AsyncSchedulerTest extends TestCase
{
    private const ROUTE_PATH = '/V1/mago/indexers/reindex';
    private const ACL_RESOURCE = 'Magento_Indexer::index';
    private const ADMIN_USER_ID = 7;

    private FakeAsyncQueue $asyncQueue;
    private FakeConnectionTransaction $transaction;
    private FakeAdminAuthorizationFactory $authorizationFactory;

    protected function setUp(): void
    {
        $this->asyncQueue = new FakeAsyncQueue();
        $this->transaction = new FakeConnectionTransaction();
        $this->authorizationFactory = new FakeAdminAuthorizationFactory([self::ACL_RESOURCE]);
    }

    #[Test]
    public function itQueuesTheCallOnTheRoutesTopicWithTheServiceArguments(): void
    {
        $result = $this->scheduler()->schedule($this->call());

        self::assertSame(['bulk_uuid' => FakeAsyncQueue::BULK_UUID, 'request_items' => [], 'errors' => false], $result);
        self::assertSame(
            [['topic' => 'async./V1/mago/indexers/reindex.POST', 'arguments' => ['catalog_product_price'], 'admin_user_id' => self::ADMIN_USER_ID]],
            $this->asyncQueue->published()
        );
    }

    #[Test]
    public function itChecksTheRouteAclForTheAdminTheCallIsMadeFor(): void
    {
        $this->scheduler()->schedule($this->call());

        self::assertSame([self::ADMIN_USER_ID], $this->authorizationFactory->adminUserIds());
    }

    #[Test]
    public function itRefusesToQueueForAnAdminWhoseRoleLacksTheRoutesResource(): void
    {
        $this->authorizationFactory = new FakeAdminAuthorizationFactory([]);

        $result = $this->scheduler()->schedule($this->call());

        self::assertSame(
            ['error' => ErrorMapper::PERMISSION_DENIED . '. The admin user is not allowed to use ' . self::ACL_RESOURCE . '.'],
            $result
        );
        self::assertSame([], $this->asyncQueue->published());
    }

    #[Test]
    public function itAnswersARejectedOperationWithTheBulkMagentoStillCreated(): void
    {
        $rejection = new BulkException();
        $rejection->addData(new FakeAsyncResponse('bulk-uuid-rejected', true));
        $this->asyncQueue->givenPublishFails($rejection, new FakeConnectionTransaction());

        $result = $this->scheduler()->schedule($this->call());

        self::assertSame(['bulk_uuid' => 'bulk-uuid-rejected', 'request_items' => [], 'errors' => true], $result);
    }

    #[Test]
    public function itReportsARejectionWithoutABulkAsAnError(): void
    {
        $this->asyncQueue->givenPublishFails(new BulkException(), new FakeConnectionTransaction());

        $result = $this->scheduler()->schedule($this->call());

        self::assertArrayHasKey('error', $result);
    }

    #[Test]
    public function itExplainsThatQueuingNeedsTheAsyncModulesWhenTheStoreRemovedThem(): void
    {
        $this->asyncQueue->givenModulesMissing();

        $result = $this->scheduler()->schedule($this->call());

        self::assertStringContainsString('Magento_WebapiAsync', $result['error'] ?? '');
        self::assertSame([], $this->asyncQueue->published());
    }

    #[Test]
    public function itRollsBackTheTransactionAFailedPublishLeftOpen(): void
    {
        $this->asyncQueue->givenPublishFails(new \Error('Call to a member function getId() on null'), $this->transaction);

        $result = $this->scheduler()->schedule($this->call());

        self::assertSame(['error' => FakeExceptionMasker::INTERNAL_ERROR], $result);
        self::assertSame(0, $this->transaction->getLevel());
    }

    private function scheduler(): AsyncScheduler
    {
        return new AsyncScheduler(
            new StoreEmulation(new FakeStoreManager(new FakeStore(1, 'default')), new FakeLocaleResolver()),
            $this->authorizationFactory,
            new FakeRouteResolver(new ResolvedRoute(
                'MagoAssistant\Mago\Api\WebApi\IndexerManagementInterface',
                'reindex',
                self::ROUTE_PATH,
                [self::ACL_RESOURCE],
                ['indexerId' => 'catalog_product_price']
            )),
            new RouteAuthorizer(),
            new FakeServiceInputProcessor(),
            $this->asyncQueue,
            new FakeAsyncResponseProcessor(),
            new OutputNormalizer(new Json()),
            new ErrorMapper(new FakeExceptionMasker()),
            new TransactionBoundary($this->transaction)
        );
    }

    private function call(): ApiCall
    {
        return new ApiCall(ApiCall::METHOD_POST, 'mago/indexers/reindex', [], ['indexerId' => 'catalog_product_price'], self::ADMIN_USER_ID, null);
    }
}
