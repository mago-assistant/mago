<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use Magento\Framework\Stdlib\DateTime\DateTime;
use MagoAssistant\Mago\Api\Skill\ConditionallyIrreversibleActionInterface;
use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CreateInvoiceAction;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CustomerNotificationGuard;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\OrderResolver;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CreateInvoiceActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    /**
     * getImpacts() reads only its parameters, never a constructor dependency.
     */
    private function action(): CreateInvoiceAction
    {
        return (new \ReflectionClass(CreateInvoiceAction::class))->newInstanceWithoutConstructor();
    }

    /**
     * The e-mail line comes from the notification guard, which describes it without touching its dependencies.
     */
    private function actionWithNotificationGuard(): CreateInvoiceAction
    {
        return new CreateInvoiceAction(
            (new \ReflectionClass(InternalApiClient::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(SecureAdminUrl::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(OrderResolver::class))->newInstanceWithoutConstructor(),
            new CustomerNotificationGuard(
                new FakeCache(),
                new FakeConfigRepository(),
                (new \ReflectionClass(DateTime::class))->newInstanceWithoutConstructor()
            )
        );
    }

    #[Test]
    public function itIsAnIrreversibleAction(): void
    {
        self::assertInstanceOf(IrreversibleActionInterface::class, $this->action());
    }

    #[Test]
    public function itStaysIrreversibleWhetherOrNotTheCustomerIsEmailed(): void
    {
        self::assertNotInstanceOf(ConditionallyIrreversibleActionInterface::class, $this->action());
    }

    #[Test]
    public function anEmailedInvoiceAddsTheEmailToTheInvoiceImpacts(): void
    {
        $impacts = $this->actionWithNotificationGuard()->getImpacts(
            ['order_number' => '000000549', 'notify_customer' => true],
            self::ADMIN_USER_ID
        );

        self::assertStringContainsString('cannot be deleted', $impacts[0]);
        self::assertStringContainsString('charged now', $impacts[1]);
        self::assertSame(
            'The customer of order #000000549 receives an e-mail about the invoice; a sent e-mail cannot be recalled.',
            $impacts[2]
        );
    }

    #[Test]
    public function anInvoiceWithoutEmailDoesNotMentionAnEmail(): void
    {
        $impacts = $this->actionWithNotificationGuard()->getImpacts(
            ['order_number' => '000000549'],
            self::ADMIN_USER_ID
        );

        self::assertStringNotContainsString('e-mail', implode("\n", $impacts));
    }

    #[Test]
    public function itWarnsThatAnInvoiceCannotBeUndoneAndNamesTheOrder(): void
    {
        $impacts = $this->action()->getImpacts(['order_number' => '000000549'], self::ADMIN_USER_ID);

        self::assertStringContainsString('000000549', $impacts[0]);
        self::assertStringContainsString('credit memo', $impacts[0]);
    }

    #[Test]
    public function captureDefaultsToOnAndIsWarned(): void
    {
        $impacts = $this->action()->getImpacts(['order_number' => '000000549'], self::ADMIN_USER_ID);

        self::assertStringContainsString('charged now', implode("\n", $impacts));
    }

    #[Test]
    public function anOfflineInvoiceIsStillIrreversibleButDoesNotChargeTheCustomer(): void
    {
        $impacts = $this->action()->getImpacts(
            ['order_number' => '000000549', 'capture' => false],
            self::ADMIN_USER_ID
        );

        self::assertStringContainsString('cannot be deleted', implode("\n", $impacts));
        self::assertStringNotContainsString('charged now', implode("\n", $impacts));
    }
}
