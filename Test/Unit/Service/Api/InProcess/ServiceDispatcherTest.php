<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\ErrorMapper;
use MagoAssistant\Mago\Service\Api\InProcess\FollowUp\ServiceCallFollowUpInterface;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\DesignChangeRefusedException;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\ServiceCallGuardInterface;
use MagoAssistant\Mago\Service\Api\InProcess\PastLastPageNormalizer;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;
use MagoAssistant\Mago\Service\Api\InProcess\RouteAuthorizer;
use MagoAssistant\Mago\Service\Api\InProcess\ServiceDispatcher;
use MagoAssistant\Mago\Service\Api\InProcess\StoreEmulation;
use MagoAssistant\Mago\Service\Api\InProcess\TransactionBoundary;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAdminAuthorizationFactory;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConnectionTransaction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeExceptionMasker;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInputArraySizeLimitValue;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLocaleResolver;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeObjectManager;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeRouteResolver;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeServiceInputProcessor;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeServiceOutputConverter;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeStore;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeStoreManager;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTransactionalService;
use PHPUnit\Framework\Attributes\Test;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

final class ServiceDispatcherTest extends TestCase
{
    private const SERVICE_CLASS = 'Vendor\Module\Api\ThingRepositoryInterface';
    private const ACL_RESOURCE = 'Vendor_Module::things';

    #[Test]
    public function itRollsBackTheTransactionAServiceLeftOpenWhenAPhpErrorStoppedIt(): void
    {
        $transaction = new FakeConnectionTransaction();
        $service = new FakeTransactionalService($transaction, new \Error('Call to a member function getId() on null'));

        $result = $this->dispatcher($transaction, $service)->dispatch($this->call());

        self::assertSame(['error' => FakeExceptionMasker::INTERNAL_ERROR], $result);
        self::assertSame(0, $transaction->getLevel());
    }

    #[Test]
    public function itLeavesTheTransactionTheChatHadOpenAloneWhenAServiceFails(): void
    {
        $transaction = new FakeConnectionTransaction(1);
        $service = new FakeTransactionalService($transaction, new \Error('Call to a member function getId() on null'));

        $this->dispatcher($transaction, $service)->dispatch($this->call());

        self::assertSame(1, $transaction->getLevel());
    }

    #[Test]
    public function itAnswersWithWhatTheServiceReturned(): void
    {
        $transaction = new FakeConnectionTransaction();

        $result = $this->dispatcher($transaction, new FakeTransactionalService($transaction))->dispatch($this->call());

        self::assertSame(['result' => 'mug'], $result);
        self::assertSame(0, $transaction->rollBacks());
    }

    /**
     * The service call succeeded; a follow-up that fails afterwards (a stock reindex) is logged, not
     * reported as a failed call the model would then retry.
     */
    #[Test]
    public function aFailingFollowUpDoesNotTurnASuccessfulCallIntoAnError(): void
    {
        $transaction = new FakeConnectionTransaction();
        $followUp = new class implements ServiceCallFollowUpInterface {
            public function afterCall(ResolvedRoute $route, array $arguments): void
            {
                throw new \RuntimeException('indexer offline');
            }
        };

        $result = $this->dispatcher($transaction, new FakeTransactionalService($transaction), [$followUp])
            ->dispatch($this->call());

        self::assertSame(['result' => 'mug'], $result);
    }

    /**
     * A guard stands in for a before-plugin on the service, so it gets the arguments the service would
     * get, and a refusal means the service is never called.
     */
    #[Test]
    public function itRefusesTheCallBeforeTheServiceRunsWhenAGuardRefusesTheConvertedArguments(): void
    {
        $transaction = new FakeConnectionTransaction();
        $guard = new class implements ServiceCallGuardInterface {
            /** @var array<int, mixed> */
            public array $seenArguments = [];

            public function guard(ResolvedRoute $route, array $arguments, AuthorizationInterface $authorization): void
            {
                $this->seenArguments = $arguments;
                throw new DesignChangeRefusedException(__('Refused'));
            }
        };

        $service = new class {
            public bool $isCalled = false;

            public function save(string $name): string
            {
                $this->isCalled = true;

                return $name;
            }
        };

        $result = $this->dispatcher($transaction, $service, [], [$guard])->dispatch($this->call());

        self::assertSame(['mug'], $guard->seenArguments);
        self::assertSame(['error' => 'Refused'], $result);
        self::assertFalse($service->isCalled);
    }

    /**
     * Outside webapi_rest Magento resets a page past the last one to page 1; REST answers it empty.
     */
    #[Test]
    public function itAnswersASearchPastTheLastPageWithoutItems(): void
    {
        $transaction = new FakeConnectionTransaction();
        $service = new class {
            /**
             * @return array<string, mixed>
             */
            public function save(string $name): array
            {
                return [
                    'items' => [['name' => $name]],
                    'search_criteria' => ['page_size' => 20, 'current_page' => 999999],
                    'total_count' => 1,
                ];
            }
        };

        $result = $this->dispatcher($transaction, $service)->dispatch($this->call());

        self::assertSame([], $result['items']);
        self::assertSame(1, $result['total_count']);
    }

    /**
     * @param ServiceCallFollowUpInterface[] $followUps
     * @param ServiceCallGuardInterface[] $guards
     */
    private function dispatcher(
        FakeConnectionTransaction $transaction,
        object $service,
        array $followUps = [],
        array $guards = []
    ): ServiceDispatcher {
        return new ServiceDispatcher(
            new StoreEmulation(new FakeStoreManager(new FakeStore(1, 'default')), new FakeLocaleResolver()),
            new FakeAdminAuthorizationFactory([self::ACL_RESOURCE]),
            new FakeRouteResolver(
                new ResolvedRoute(self::SERVICE_CLASS, 'save', '/V1/things', [self::ACL_RESOURCE], ['name' => 'mug'])
            ),
            new RouteAuthorizer(),
            new FakeServiceInputProcessor(),
            new FakeInputArraySizeLimitValue(),
            new FakeServiceOutputConverter(),
            new PastLastPageNormalizer(),
            new FakeObjectManager([self::SERVICE_CLASS => $service]),
            new ErrorMapper(new FakeExceptionMasker()),
            new TransactionBoundary($transaction),
            new ErrorReporter(new ErrorLogger(new FakeLogger(), new Json()), new PiiHeuristic()),
            $guards,
            $followUps
        );
    }

    private function call(): ApiCall
    {
        return new ApiCall(ApiCall::METHOD_POST, 'things', [], ['name' => 'mug'], 7, null);
    }
}
