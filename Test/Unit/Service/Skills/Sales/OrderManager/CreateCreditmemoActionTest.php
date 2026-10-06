<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use Magento\Framework\Stdlib\DateTime\DateTime;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CreateCreditmemoAction;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CustomerNotificationGuard;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\OrderResolver;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The refund payload, and the texts the admin confirms on and the model reads. The action posts to
 * order/{id}/refund, the offline refund, so none of the texts may claim money goes back through
 * the payment provider.
 */
final class CreateCreditmemoActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    #[Test]
    public function itSendsTheAdjustmentsAsCreditmemoArguments(): void
    {
        $apiClient = $this->apiClient();

        $result = $this->action($apiClient)->execute([
            'order_number' => '000000008',
            'adjustment_positive' => '2.5',
            'adjustment_negative' => 1,
        ], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertSame(
            ['notify' => false, 'arguments' => ['adjustment_positive' => 2.5, 'adjustment_negative' => 1.0]],
            $this->refundPayload($apiClient)
        );
    }

    #[Test]
    public function itSendsNoArgumentsForAFullRefund(): void
    {
        $apiClient = $this->apiClient();

        $this->action($apiClient)->execute(['order_number' => '000000008'], self::ADMIN_USER_ID);

        self::assertSame(['notify' => false], $this->refundPayload($apiClient));
    }

    private function apiClient(): FakeInternalApiClient
    {
        return (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'orders', [
                'items' => [['entity_id' => 8, 'status' => 'processing', 'increment_id' => '000000008']],
            ])
            ->withResponse(FakeInternalApiClient::POST, 'order/8/refund', ['result' => 3]);
    }

    #[Test]
    public function itTellsTheAdminNoMoneyIsRefundedThroughThePaymentProvider(): void
    {
        $impacts = implode(' ', $this->textsOnly()->getImpacts(['order_number' => '000000549'], 1));

        self::assertStringContainsString('Order #000000549 gets an offline credit memo', $impacts);
        self::assertStringContainsString('No money is refunded through the payment provider', $impacts);
        self::assertStringNotContainsString('original payment method', $impacts);
    }

    #[Test]
    public function itDescribesTheToolAsAnOfflineCreditMemo(): void
    {
        $description = $this->textsOnly()->getDescription();

        self::assertStringContainsString('offline credit memo', $description);
        self::assertStringContainsString('no money is refunded through the payment provider', $description);
    }

    #[Test]
    public function itInstructsTheModelNeverToClaimTheCustomerWasRefunded(): void
    {
        $instructions = $this->textsOnly()->getInstructions();

        self::assertStringContainsString('does not refund any money through the payment provider', $instructions);
        self::assertStringContainsString('Never say the customer was refunded', $instructions);
    }

    /**
     * The texts under test touch none of the collaborators
     */
    private function textsOnly(): CreateCreditmemoAction
    {
        return (new \ReflectionClass(CreateCreditmemoAction::class))->newInstanceWithoutConstructor();
    }

    /**
     * @return array<string, mixed>
     */
    private function refundPayload(FakeInternalApiClient $apiClient): array
    {
        return $apiClient->callsOf(FakeInternalApiClient::POST)[0]['payload'];
    }

    private function action(FakeInternalApiClient $apiClient): CreateCreditmemoAction
    {
        return new CreateCreditmemoAction(
            $apiClient,
            $this->createStub(SecureAdminUrl::class),
            new OrderResolver($apiClient),
            new CustomerNotificationGuard(
                new FakeCache(),
                (new FakeConfigRepository())->withCustomerNotificationInterval(60),
                (new \ReflectionClass(DateTime::class))->newInstanceWithoutConstructor()
            )
        );
    }
}
