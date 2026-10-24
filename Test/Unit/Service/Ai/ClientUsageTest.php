<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Ai;

use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Model\Chat\ChatResponse;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;
use MagoAssistant\Mago\Service\Ai\Client;
use MagoAssistant\Mago\Service\Ai\RequestFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientUsageTest extends TestCase
{
    #[Test]
    public function itReportsWhatTheProviderServedFromItsPromptCache(): void
    {
        $usage = $this->chatWith(new TokenUsage(1000, 50, null, 800, null, 100))['usage'];

        $this->assertSame(800, $usage['cache_read_tokens']);
        $this->assertSame(100, $usage['cache_write_tokens']);
    }

    #[Test]
    public function itKeepsAZeroCacheReadApartFromACacheFigureTheProviderNeverReported(): void
    {
        $reportedZero = $this->chatWith(new TokenUsage(1000, 50, null, 0, null, 0))['usage'];
        $notReported = $this->chatWith(new TokenUsage(1000, 50))['usage'];

        $this->assertSame(0, $reportedZero['cache_read_tokens']);
        $this->assertNull($notReported['cache_read_tokens']);
        $this->assertNull($notReported['cache_write_tokens']);
    }

    #[Test]
    public function itStillReportsThePromptAndCompletionTokens(): void
    {
        $usage = $this->chatWith(new TokenUsage(1000, 50, null, 800))['usage'];

        $this->assertSame(1000, $usage['input_tokens']);
        $this->assertSame(50, $usage['output_tokens']);
    }

    /**
     * @return array<string,mixed>
     */
    private function chatWith(TokenUsage $usage): array
    {
        $aiClient = $this->createMock(AiClientInterface::class);
        $aiClient->method('chat')->willReturn(new ChatResponse('ok', [], $usage));

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('create')->willReturn($this->createMock(ChatRequestInterface::class));

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getMaxTokens')->willReturn(1000);

        $client = new Client($this->createMock(AiClientFactoryInterface::class), $config, $requestFactory);

        return $client->chat($aiClient, [['role' => 'user', 'content' => 'Hi']], []);
    }
}
