<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Ai;

use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Exceptions\AiRequestNotSentException;
use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\Mago\Service\Ai\AiNotConfiguredException;
use MagoAssistant\Mago\Service\Ai\Client;
use MagoAssistant\Mago\Service\Ai\RequestFactory;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientResolveTest extends TestCase
{
    /**
     * The client factory explains a missing or unusable service before any request is made, so its
     * message is the setup instruction the admin needs and is marked as such
     */
    #[Test]
    public function aServiceThatCannotBeResolvedIsReportedAsNotConfigured(): void
    {
        $factory = $this->createStub(AiClientFactoryInterface::class);
        $factory->method('create')->willThrowException(new LocalizedException(__('No AI service configured.')));

        $this->expectException(AiNotConfiguredException::class);
        $this->expectExceptionMessage('No AI service configured.');

        $this->client($factory)->resolve();
    }

    #[Test]
    public function anyOtherFailureIsNotPassedOffAsASetupMessage(): void
    {
        $factory = $this->createStub(AiClientFactoryInterface::class);
        $factory->method('create')->willThrowException(new \RuntimeException('SQLSTATE[HY000]: connection lost'));

        $this->expectException(\RuntimeException::class);

        $this->client($factory)->resolve();
    }

    /**
     * A LocalizedException subclass is not one of the factory's setup messages
     */
    #[Test]
    public function aLocalizedSubclassIsNotPassedOffAsASetupMessage(): void
    {
        $factory = $this->createStub(AiClientFactoryInterface::class);
        $factory->method('create')->willThrowException(new AiRequestNotSentException(__('Request to %1 failed', 'x')));

        $this->expectException(AiRequestNotSentException::class);

        $this->client($factory)->resolve();
    }

    private function client(AiClientFactoryInterface $factory): Client
    {
        return new Client($factory, new FakeConfigRepository(), $this->createStub(RequestFactory::class));
    }
}
