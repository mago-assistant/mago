<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use Magento\Framework\Stdlib\DateTime\DateTime;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\AddCommentAction;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CustomerNotificationGuard;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\OrderResolver;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AddCommentActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    #[Test]
    public function aRepeatedCustomerEmailIsRefusedWithoutCallingTheApi(): void
    {
        $apiClient = $this->createMock(InternalApiClient::class);
        $apiClient->expects(self::once())
            ->method('post')
            ->with('orders/8/comments', self::anything(), self::ADMIN_USER_ID)
            ->willReturn([]);
        $action = $this->actionWith($apiClient, new FakeCache());

        $results = [];
        for ($i = 0; $i < 100; $i++) {
            $results[] = $action->execute($this->params(['notify_customer' => true]), self::ADMIN_USER_ID);
        }

        self::assertTrue($results[0]['success']);
        self::assertStringStartsWith('Not sent:', $results[1]['error']);
        self::assertStringStartsWith('Not sent:', $results[99]['error']);
    }

    #[Test]
    public function aCommentWithoutEmailIsNeverLimited(): void
    {
        $apiClient = $this->createMock(InternalApiClient::class);
        $apiClient->expects(self::exactly(3))->method('post')->willReturn([]);
        $action = $this->actionWith($apiClient, new FakeCache());

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($action->execute($this->params(), self::ADMIN_USER_ID)['success']);
        }
    }

    #[Test]
    public function aFailedSendDoesNotCountAsSent(): void
    {
        $cache = new FakeCache();
        $apiClient = $this->createStub(InternalApiClient::class);
        $apiClient->method('post')->willReturn(['error' => 'Unable to send mail. Please try again later.']);

        $this->actionWith($apiClient, $cache)->execute($this->params(['notify_customer' => true]), self::ADMIN_USER_ID);

        self::assertTrue($cache->isEmpty());
    }

    #[Test]
    public function onlyACommentThatEmailsTheCustomerAsksForTheIrreversibleCard(): void
    {
        $action = $this->actionWith($this->createStub(InternalApiClient::class), new FakeCache());
        $skill = new FakeSkill('order_manager', new FakeAuthorization(), ['add_comment' => $action]);

        self::assertFalse($skill->isIrreversibleAction(['action' => 'add_comment'] + $this->params()));
        self::assertTrue($skill->isIrreversibleAction(
            ['action' => 'add_comment'] + $this->params(['notify_customer' => true])
        ));
        self::assertSame(
            [
                'The customer of order #000000008 receives an e-mail about this comment; '
                . 'a sent e-mail cannot be recalled.',
            ],
            $skill->getImpacts(
                ['action' => 'add_comment'] + $this->params(['notify_customer' => true]),
                self::ADMIN_USER_ID
            )
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function params(array $overrides = []): array
    {
        return $overrides + [
            'order_number' => '000000008',
            'comment' => 'Your order is on its way.',
        ];
    }

    private function actionWith(InternalApiClient $apiClient, FakeCache $cache): AddCommentAction
    {
        $orderResolver = $this->createStub(OrderResolver::class);
        $orderResolver->method('resolve')->willReturn([
            'entity_id' => 8,
            'status' => 'processing',
            'increment_id' => '000000008',
        ]);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_790_000_000);

        return new AddCommentAction(
            $apiClient,
            $this->createStub(SecureAdminUrl::class),
            $orderResolver,
            new CustomerNotificationGuard(
                $cache,
                (new FakeConfigRepository())->withCustomerNotificationInterval(60),
                $dateTime
            )
        );
    }
}
