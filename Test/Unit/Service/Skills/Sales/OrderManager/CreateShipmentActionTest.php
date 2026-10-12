<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use Magento\Framework\Stdlib\DateTime\DateTime;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CreateShipmentAction;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CustomerNotificationGuard;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\OrderResolver;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CreateShipmentActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    #[Test]
    public function itDoesNotEmailTheCustomerUnlessAsked(): void
    {
        $apiClient = (new FakeInternalApiClient())->withResponse(FakeInternalApiClient::POST, 'order/8/ship', ['result' => 3]);
        $action = $this->actionWith($apiClient);

        $result = $action->execute(['order_number' => '000000008'], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertFalse($action->isIrreversible(['order_number' => '000000008']));
        self::assertSame(['notify' => false], $apiClient->calls()[0]['payload']);
        self::assertSame(self::ADMIN_USER_ID, $apiClient->calls()[0]['admin_user_id']);
    }

    #[Test]
    public function aSecondShipmentEmailForTheSameOrderIsRefused(): void
    {
        $apiClient = (new FakeInternalApiClient())->withResponse(FakeInternalApiClient::POST, 'order/8/ship', ['result' => 3]);
        $action = $this->actionWith($apiClient);
        $params = ['order_number' => '000000008', 'notify_customer' => true];

        self::assertTrue($action->execute($params, self::ADMIN_USER_ID)['success']);
        self::assertStringStartsWith(
            'Not sent: Mago already sent the customer of order #000000008 the shipment e-mail',
            $action->execute($params, self::ADMIN_USER_ID)['error']
        );
        self::assertCount(1, $apiClient->calls());
        self::assertSame(['notify' => true], $apiClient->calls()[0]['payload']);
    }

    private function actionWith(FakeInternalApiClient $apiClient): CreateShipmentAction
    {
        $orderResolver = $this->createStub(OrderResolver::class);
        $orderResolver->method('resolve')->willReturn([
            'entity_id' => 8,
            'status' => 'processing',
            'increment_id' => '000000008',
        ]);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_790_000_000);

        return new CreateShipmentAction(
            $apiClient,
            $this->createStub(SecureAdminUrl::class),
            $orderResolver,
            new CustomerNotificationGuard(
                new FakeCache(),
                (new FakeConfigRepository())->withCustomerNotificationInterval(60),
                $dateTime
            )
        );
    }
}
