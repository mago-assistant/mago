<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use Magento\Framework\Stdlib\DateTime\DateTime;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CustomerNotificationGuard;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\OrderResolver;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\ResendConfirmationAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResendConfirmationActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const PARAMS = ['order_number' => '000000008'];

    #[Test]
    public function itSendsTheConfirmationOnceAndRefusesTheRepeats(): void
    {
        $apiClient = $this->createMock(InternalApiClient::class);
        $apiClient->expects(self::once())
            ->method('post')
            ->with('orders/8/emails', [], self::ADMIN_USER_ID)
            ->willReturn(['result' => true]);
        $action = $this->actionWith($apiClient, new FakeCache());

        $results = [];
        for ($i = 0; $i < 100; $i++) {
            $results[] = $action->execute(self::PARAMS, self::ADMIN_USER_ID);
        }

        self::assertSame('Order confirmation sent again for order #000000008', $results[0]['message']);
        self::assertStringStartsWith(
            'Not sent: Mago already sent the customer of order #000000008 the confirmation e-mail',
            $results[99]['error']
        );
    }

    #[Test]
    public function aConfirmationMagentoDidNotSendIsAnErrorAndDoesNotCount(): void
    {
        $cache = new FakeCache();
        $apiClient = $this->createStub(InternalApiClient::class);
        $apiClient->method('post')->willReturn(['result' => false]);

        $result = $this->actionWith($apiClient, $cache)->execute(self::PARAMS, self::ADMIN_USER_ID);

        self::assertStringStartsWith('Magento did not send the order confirmation', $result['error']);
        self::assertTrue($cache->isEmpty());
    }

    #[Test]
    public function itAlwaysAsksForTheIrreversibleCard(): void
    {
        $action = $this->actionWith($this->createStub(InternalApiClient::class), new FakeCache());
        $skill = new FakeSkill('order_manager', new FakeAuthorization(), ['resend_confirmation' => $action]);
        $input = ['action' => 'resend_confirmation'] + self::PARAMS;

        self::assertTrue($skill->isIrreversibleAction($input));
        self::assertSame(
            [
                'The customer of order #000000008 receives an e-mail about their order (the order confirmation); '
                . 'a sent e-mail cannot be recalled.',
            ],
            $skill->getImpacts($input, self::ADMIN_USER_ID)
        );
    }

    private function actionWith(InternalApiClient $apiClient, FakeCache $cache): ResendConfirmationAction
    {
        $orderResolver = $this->createStub(OrderResolver::class);
        $orderResolver->method('resolve')->willReturn([
            'entity_id' => 8,
            'status' => 'processing',
            'increment_id' => '000000008',
        ]);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_790_000_000);

        return new ResendConfirmationAction(
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
