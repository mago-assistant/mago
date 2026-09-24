<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Ai;

use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;
use MagoAssistant\Mago\Service\Privacy\EgressTripwire;

/**
 * The assistant's one way to reach an AI provider.
 *
 * MageOS_AiBase owns the provider differences; this owns the two things that are this module's
 * own: which configured service the assistant runs on, and the array shape the chat panel and the
 * conversation store have always spoken. Keeping that shape here is what lets the SSE contract the
 * admin panel reads stay exactly as it was.
 */
class Client
{
    /**
     * @param AiClientFactoryInterface $clientFactory
     * @param ConfigRepository $configRepository
     * @param RequestFactory $requestFactory
     * @param EgressTripwire|null $tripwire
     */
    public function __construct(
        private readonly AiClientFactoryInterface $clientFactory,
        private readonly ConfigRepository $configRepository,
        private readonly RequestFactory $requestFactory,
        private readonly ?EgressTripwire $tripwire = null
    ) {
    }

    /**
     * Resolve the service the administrator picked, or the first usable one.
     *
     * @return AiClientInterface
     * @throws \Magento\Framework\Exception\LocalizedException When nothing usable is configured
     */
    public function resolve(): AiClientInterface
    {
        $serviceId = $this->configRepository->getAiServiceId();

        return $serviceId === ''
            ? $this->clientFactory->create()
            : $this->clientFactory->createById($serviceId);
    }

    /**
     * Send a conversation and wait for the whole reply.
     *
     * @param AiClientInterface $client
     * @param array<int,array<string,mixed>> $messages
     * @param array<int,array<string,mixed>> $tools
     * @return array{content: string, tool_calls: array<int,array<string,mixed>>, usage: array<string,int>}
     */
    public function chat(AiClientInterface $client, array $messages, array $tools): array
    {
        $this->tripwire?->inspect($messages);
        $request = $this->requestFactory->create($messages, $tools);

        return $this->toArray($client->chat($request, $this->buildOptions()));
    }

    /**
     * Send a conversation and hand every delta to the caller as it arrives.
     *
     * The generator's return value is the same turn a buffered call would have produced, so the
     * accumulated text and the completed tool calls come back without being stitched together here.
     *
     * @param AiClientInterface $client
     * @param array<int,array<string,mixed>> $messages
     * @param array<int,array<string,mixed>> $tools
     * @param callable $onChunk Receives (string $event, array $payload)
     * @return array{content: string, tool_calls: array<int,array<string,mixed>>, usage: array<string,int>}
     */
    public function stream(AiClientInterface $client, array $messages, array $tools, callable $onChunk): array
    {
        $this->tripwire?->inspect($messages);
        $request = $this->requestFactory->create($messages, $tools);
        $stream = $client->streamChat($request, $this->buildOptions());

        foreach ($stream as $chunk) {
            if ($chunk->getType() === StreamChunkType::Text) {
                $onChunk('text', ['text' => $chunk->getText()]);
                continue;
            }

            $toolCall = $chunk->getType() === StreamChunkType::ToolCall ? $chunk->getToolCall() : null;
            if ($toolCall !== null) {
                $onChunk('tool_call', [
                    'id' => $toolCall->getId(),
                    'name' => $toolCall->getName(),
                    'input' => $toolCall->getArguments(),
                ]);
            }
        }

        return $this->toArray($stream->getReturn());
    }

    /**
     * Provider options for a call.
     *
     * Deliberately no temperature. This assistant picks tools and reports store facts, and
     * randomness there buys nothing but worse tool selection and invented order numbers. Leaving it
     * out also means the models that reject the parameter outright never see it, so no list of
     * which ones those are has to be kept anywhere. A skill that genuinely wants variety can pass
     * its own temperature at the call site, where it applies.
     *
     * @return array<string,mixed>
     */
    private function buildOptions(): array
    {
        return ['max_tokens' => $this->configRepository->getMaxTokens()];
    }

    /**
     * @param ChatResponseInterface $response
     * @return array{content: string, tool_calls: array<int,array<string,mixed>>, usage: array<string,int>}
     */
    private function toArray(ChatResponseInterface $response): array
    {
        $usage = $response->getUsage();

        return [
            'content' => $response->getText(),
            'tool_calls' => array_map(
                static fn ($toolCall): array => [
                    'id' => $toolCall->getId(),
                    'name' => $toolCall->getName(),
                    'input' => $toolCall->getArguments(),
                ],
                $response->getToolCalls()
            ),
            'usage' => [
                'input_tokens' => $usage?->getPromptTokens() ?? 0,
                'output_tokens' => $usage?->getCompletionTokens() ?? 0,
            ],
        ];
    }
}
