<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use Magento\Framework\Stdlib\DateTime\DateTime;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CustomerNotificationGuard;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CustomerNotificationGuardTest extends TestCase
{
    private const ORDER_ID = 8;
    private const ORDER_NUMBER = '000000008';
    private const NOW = 1_790_000_000;

    /** @var FakeCache */
    private FakeCache $cache;

    /** @var int */
    private int $now = self::NOW;

    protected function setUp(): void
    {
        $this->cache = new FakeCache();
        $this->now = self::NOW;
    }

    #[Test]
    public function itAllowsTheFirstEmailOfAnOrder(): void
    {
        self::assertNull($this->guard()->findRefusal(
            self::ORDER_ID,
            self::ORDER_NUMBER,
            CustomerNotificationGuard::KIND_COMMENT
        ));
    }

    #[Test]
    public function itRefusesTheSameKindOfEmailWithinTheInterval(): void
    {
        $guard = $this->guard();
        $guard->recordSent(self::ORDER_ID, CustomerNotificationGuard::KIND_COMMENT);
        $this->now += 59 * 60;

        $refusal = $guard->findRefusal(self::ORDER_ID, self::ORDER_NUMBER, CustomerNotificationGuard::KIND_COMMENT);

        self::assertNotNull($refusal);
        self::assertStringStartsWith(
            'Not sent: Mago already sent the customer of order #000000008 the comment e-mail 59 minute(s) ago.',
            $refusal['error']
        );
        self::assertStringContainsString('Do not retry.', $refusal['error']);
    }

    #[Test]
    public function itAllowsTheEmailAgainOnceTheIntervalHasPassed(): void
    {
        $guard = $this->guard();
        $guard->recordSent(self::ORDER_ID, CustomerNotificationGuard::KIND_COMMENT);
        $this->now += 60 * 60;

        self::assertNull($guard->findRefusal(
            self::ORDER_ID,
            self::ORDER_NUMBER,
            CustomerNotificationGuard::KIND_COMMENT
        ));
    }

    #[Test]
    public function itCountsEachKindAndOrderApart(): void
    {
        $guard = $this->guard();
        $guard->recordSent(self::ORDER_ID, CustomerNotificationGuard::KIND_INVOICE);

        self::assertNull($guard->findRefusal(
            self::ORDER_ID,
            self::ORDER_NUMBER,
            CustomerNotificationGuard::KIND_SHIPMENT
        ));
        self::assertNull($guard->findRefusal(9, '000000009', CustomerNotificationGuard::KIND_INVOICE));
    }

    #[Test]
    public function itKeepsTheRecordOnlyAsLongAsTheInterval(): void
    {
        $this->guard(15)->recordSent(self::ORDER_ID, CustomerNotificationGuard::KIND_SHIPMENT);

        self::assertSame(900, $this->cache->lifetimeOf('mago_customer_notification_shipment_order_8'));
    }

    #[Test]
    public function aZeroIntervalLiftsTheLimit(): void
    {
        $guard = $this->guard(0);
        $guard->recordSent(self::ORDER_ID, CustomerNotificationGuard::KIND_COMMENT);

        self::assertTrue($this->cache->isEmpty());
        self::assertNull($guard->findRefusal(
            self::ORDER_ID,
            self::ORDER_NUMBER,
            CustomerNotificationGuard::KIND_COMMENT
        ));
    }

    private function guard(int $interval = 60): CustomerNotificationGuard
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        return new CustomerNotificationGuard(
            $this->cache,
            (new FakeConfigRepository())->withCustomerNotificationInterval($interval),
            $dateTime
        );
    }
}
