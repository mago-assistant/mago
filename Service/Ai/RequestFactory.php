<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Ai;

use MageOS\AiBase\Api\ChatRequestBuilderInterface;
use MageOS\AiBase\Api\ChatRequestBuilderInterfaceFactory;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\ToolCallInterface;
use MageOS\AiBase\Api\Data\ToolCallInterfaceFactory;

/**
 * Turns the conversation arrays this module passes around into a provider-neutral chat request.
 *
 * Conversations are stored and replayed as plain arrays, and the admin panel posts them back the
 * same way, so the array is the format that survives a page reload. This is the one place that
 * knows how it maps onto MageOS_AiBase, which is what keeps every provider difference out of
 * ChatService.
 */
class RequestFactory
{
    /**
     * @param ChatRequestBuilderInterfaceFactory $builderFactory
     * @param ToolCallInterfaceFactory $toolCallFactory
     * @param ToolOutputEnvelope $toolOutputEnvelope
     */
    public function __construct(
        private readonly ChatRequestBuilderInterfaceFactory $builderFactory,
        private readonly ToolCallInterfaceFactory $toolCallFactory,
        private readonly ToolOutputEnvelope $toolOutputEnvelope
    ) {
    }

    /**
     * Build a request from a conversation and the tools currently on offer.
     *
     * @param array<int,array<string,mixed>> $messages Conversation, oldest first
     * @param array<int,array<string,mixed>> $tools Definitions from ToolRegistry::getToolDefinitions()
     * @return ChatRequestInterface
     */
    public function create(array $messages, array $tools): ChatRequestInterface
    {
        $builder = $this->builderFactory->create();

        foreach ($tools as $tool) {
            $builder = $builder->withTool(
                (string)($tool['name'] ?? ''),
                (string)($tool['description'] ?? ''),
                (array)($tool['parameters'] ?? [])
            );
        }

        $calledTools = $this->mapToolNamesByCallId($messages);

        foreach ($messages as $message) {
            $builder = $this->appendMessage($builder, $message, $calledTools);
        }

        return $builder->build();
    }

    /**
     * Index every tool call in the conversation by its id, so a result can be paired back to it.
     *
     * A replayed tool result carries its `tool_call_id` and nothing else: the panel never needed
     * the tool's name to render it, so the conversation store never kept one on that row. The
     * provider is handed a whole call rather than an id, so the name is read back off the assistant
     * turn that asked for it, which is the only place it survives.
     *
     * @param array<int,array<string,mixed>> $messages
     * @return array<string,string> Call id => tool name
     */
    private function mapToolNamesByCallId(array $messages): array
    {
        $names = [];
        foreach ($messages as $message) {
            foreach ((array)($message['tool_calls'] ?? []) as $call) {
                $id = (string)($call['id'] ?? '');
                if ($id !== '') {
                    $names[$id] = (string)($call['name'] ?? '');
                }
            }
        }

        return $names;
    }

    /**
     * Rebuild a tool call from a stored assistant turn or tool result.
     *
     * Arguments are absent on a result by design rather than lost: they were already sent with the
     * assistant turn above it, and repeating them would put the same call in the transcript twice.
     *
     * @param array<string,mixed> $data
     * @param array<string,string> $calledTools Call id => tool name
     * @return ToolCallInterface
     */
    private function toToolCall(array $data, array $calledTools): ToolCallInterface
    {
        $id = (string)($data['tool_call_id'] ?? $data['id'] ?? '');

        return $this->toolCallFactory->create([
            'id' => $id,
            'name' => (string)($data['name'] ?? $calledTools[$id] ?? ''),
            'arguments' => (array)($data['input'] ?? []),
        ]);
    }

    /**
     * @param ChatRequestBuilderInterface $builder
     * @param array<string,mixed> $message
     * @param array<string,string> $calledTools Call id => tool name
     * @return ChatRequestBuilderInterface
     */
    private function appendMessage(
        ChatRequestBuilderInterface $builder,
        array $message,
        array $calledTools
    ): ChatRequestBuilderInterface {
        $content = (string)($message['content'] ?? '');
        $toCall = fn (array $data): ToolCallInterface => $this->toToolCall($data, $calledTools);

        return match ($message['role'] ?? '') {
            'system' => $builder->withSystemMessage($content),
            'assistant' => $builder->withAssistantMessage(
                $content,
                array_map($toCall, (array)($message['tool_calls'] ?? []))
            ),
            'tool' => $builder->withToolResult(
                $toCall($message),
                $this->toolOutputEnvelope->wrap((string)($message['tool_call_id'] ?? ''), $content)
            ),
            default => $builder->withUserMessage($content),
        };
    }
}
