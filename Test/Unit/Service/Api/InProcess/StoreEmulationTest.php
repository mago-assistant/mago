<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess;

use Magento\Framework\Webapi\Exception as WebapiException;
use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\StoreEmulation;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLocaleResolver;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeStore;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeStoreManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StoreEmulationTest extends TestCase
{
    private FakeStoreManager $storeManager;
    private FakeLocaleResolver $localeResolver;
    private StoreEmulation $storeEmulation;

    protected function setUp(): void
    {
        $this->storeManager = new FakeStoreManager(new FakeStore(1, 'default'), new FakeStore(2, 'luma'));
        $this->localeResolver = new FakeLocaleResolver('en_US', [1 => 'en_GB', 2 => 'nl_NL']);
        $this->storeEmulation = new StoreEmulation($this->storeManager, $this->localeResolver);
    }

    #[Test]
    public function itRunsACallWithoutAStoreCodeInTheDefaultStoreView(): void
    {
        $storeCode = $this->storeEmulation->run($this->call(null), $this->currentStoreCode(...));

        self::assertSame('default', $storeCode);
    }

    #[Test]
    public function itRunsACallForAllStoreViewsInTheAdminStore(): void
    {
        $storeCode = $this->storeEmulation->run($this->call('all'), $this->currentStoreCode(...));

        self::assertSame('admin', $storeCode);
    }

    #[Test]
    public function itRunsACallInTheStoreViewItNamesWithThatStoreViewsLocale(): void
    {
        $locale = $this->storeEmulation->run($this->call('luma'), fn (): string => $this->localeResolver->getLocale());

        self::assertSame('nl_NL', $locale);
    }

    #[Test]
    public function itPutsTheChatsStoreAndLocaleBackAfterTheCall(): void
    {
        $this->storeEmulation->run($this->call('luma'), fn (): bool => true);

        self::assertSame('admin', $this->storeManager->getStore()->getCode());
        self::assertSame('en_US', $this->localeResolver->getLocale());
    }

    #[Test]
    public function itPutsTheChatsStoreAndLocaleBackWhenTheCallFails(): void
    {
        $failure = null;

        try {
            $this->storeEmulation->run($this->call('luma'), static fn (): never => throw new \RuntimeException('boom'));
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertSame('boom', $failure?->getMessage());
        self::assertSame('admin', $this->storeManager->getStore()->getCode());
        self::assertSame('en_US', $this->localeResolver->getLocale());
    }

    #[Test]
    public function itRefusesAnUnknownStoreCodeWithoutRunningTheCall(): void
    {
        $hasRun = false;

        try {
            $this->storeEmulation->run($this->call('nope'), function () use (&$hasRun): void {
                $hasRun = true;
            });
            self::fail('An unknown store code must be refused');
        } catch (WebapiException $exception) {
            self::assertSame('Specified request cannot be processed.', $exception->getRawMessage());
        }

        self::assertFalse($hasRun);
        self::assertSame('admin', $this->storeManager->getStore()->getCode());
    }

    private function call(?string $storeCode): ApiCall
    {
        return new ApiCall(ApiCall::METHOD_GET, 'products', [], [], 1, $storeCode);
    }

    private function currentStoreCode(): string
    {
        return (string)$this->storeManager->getStore()->getCode();
    }
}
