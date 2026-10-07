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
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
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
     * @throws AiNotConfiguredException When nothing usable is configured
     */
    public function resolve(): AiClientInterface
    {
        $serviceId = $this->configRepository->getAiServiceId();

        try {
            return $serviceId === ''
                ? $this->clientFactory->create()
                : $this->clientFactory->createById($serviceId);
        } catch (LocalizedException $e) {
            // Only the factory's own setup messages; a subclass (or a replacement factory that
            // probes the endpoint) may carry request details and is reported generically.
            if ($e::class !== LocalizedException::class) {
                throw $e;
            }
            throw new AiNotConfiguredException(new Phrase($e->getRawMessage(), $e->getParameters()), $e);
        }
    }

    /**
     * Send a conversation and wait for the whole reply.
     *
     * @param AiClientInterface $client
     * @param array<int,array<string,mixed>> $messages
     * @param array<int,array<string,mixed>> $tools
     * @return array{content: string, tool_calls: array<int,array<string,mixed>>, usage: array<string,int|null>}
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
     * @return array{content: string, tool_calls: array<int,array<string,mixed>>, usage: array<string,int|null>}
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
     * @return array{content: string, tool_calls: array<int,array<string,mixed>>, usage: array<string,int|null>}
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
                // Null, not zero, when the provider does not report them: "no cache hits" and "no
                // cache figures" are different facts, and only the first one means caching missed.
                'cache_read_tokens' => $this->cacheTokens($usage, 'getCacheReadTokens'),
                'cache_write_tokens' => $this->cacheTokens($usage, 'getCacheWriteTokens'),
            ],
        ];
    }

    /**
     * Read one cache figure off the usage, if this version of MageOS_AiBase reports it.
     *
     * The cache getters arrived after the first AiBase release, and this module still accepts it, so
     * the call is guarded rather than assumed.
     *
     * @param object|null $usage
     * @param string $getter
     */
    private function cacheTokens(?object $usage, string $getter): ?int
    {
        if ($usage === null || !method_exists($usage, $getter)) {
            return null;
        }

        $tokens = $usage->{$getter}();

        return is_int($tokens) ? $tokens : null;
    }
}
