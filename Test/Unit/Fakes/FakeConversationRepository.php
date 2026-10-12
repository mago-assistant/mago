<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\ConversationRepositoryInterface;
use MagoAssistant\Mago\Model\Conversation\ConversationNotFoundException;

/**
 * Conversations and messages in memory, with the same ownership and claim rules as the real
 * repository. A message's age is set with ageMessage(), so expiry needs no clock.
 */
final class FakeConversationRepository implements ConversationRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $conversations = [];

    /** @var array<int, array<string, mixed>> */
    private array $messages = [];

    /** @var array<int, int> Seconds since each message was written */
    private array $ages = [];

    /**
     * @param array<int, array<string, mixed>> $conversations Keyed by conversation id
     * @param array<int, list<array<string, mixed>>> $messages A test's starting messages, keyed by conversation id
     */
    public function __construct(array $conversations = [], array $messages = [])
    {
        $this->conversations = $conversations;
        foreach ($messages as $conversationId => $conversationMessages) {
            foreach ($conversationMessages as $message) {
                $messageId = count($this->messages) + 1;
                // Kept as the test wrote it, apart from where it belongs
                $this->messages[$messageId] = $message + ['conversation_id' => $conversationId];
                $this->ages[$messageId] = 0;
            }
        }
    }

    public function create(int $adminUserId, string $title = 'New Chat'): int
    {
        $conversationId = count($this->conversations) + 1;
        $this->conversations[$conversationId] = [
            'entity_id' => $conversationId,
            'admin_user_id' => $adminUserId,
            'title' => $title,
        ];

        return $conversationId;
    }

    public function updateTitle(int $conversationId, string $title): void
    {
        $this->conversations[$conversationId]['title'] = $title;
    }

    public function getById(int $conversationId): array
    {
        return $this->conversations[$conversationId]
            ?? throw new ConversationNotFoundException('Conversation not found: ' . $conversationId);
    }

    public function getByIdForUser(int $conversationId, int $adminUserId): array
    {
        $conversation = $this->getById($conversationId);
        if ((int)($conversation['admin_user_id'] ?? 0) !== $adminUserId) {
            throw new ConversationNotFoundException('Conversation not found: ' . $conversationId);
        }

        return $conversation;
    }

    public function getListByUser(int $adminUserId): array
    {
        return array_values(array_filter(
            $this->conversations,
            static fn (array $conversation): bool => (int)($conversation['admin_user_id'] ?? 0) === $adminUserId
        ));
    }

    public function delete(int $conversationId, ?int $adminUserId = null): void
    {
        unset($this->conversations[$conversationId]);
        $this->messages = array_filter(
            $this->messages,
            static fn (array $message): bool => $message['conversation_id'] !== $conversationId
        );
    }

    public function addMessage(
        int $conversationId,
        string $role,
        string $content,
        ?array $toolCalls = null,
        bool $pendingConfirmation = false,
        ?string $toolCallId = null
    ): int {
        $messageId = count($this->messages) + 1;
        $this->messages[$messageId] = [
            'entity_id' => $messageId,
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            'tool_calls' => $toolCalls === null ? null : (string)json_encode($toolCalls),
            'tool_call_id' => $toolCallId,
            'pending_confirmation' => $pendingConfirmation ? 1 : 0,
        ];
        $this->ages[$messageId] = 0;

        return $messageId;
    }

    public function getMessages(int $conversationId): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (array $message): bool => $message['conversation_id'] === $conversationId
        ));
    }

    public function getMessageById(int $messageId): array
    {
        return $this->messages[$messageId]
            ?? throw new ConversationNotFoundException('Message not found: ' . $messageId);
    }

    public function getMessageForUser(int $messageId, int $adminUserId): array
    {
        $message = $this->getMessageById($messageId);
        $this->getByIdForUser((int)$message['conversation_id'], $adminUserId);

        return $message;
    }

    public function claimPendingConfirmation(int $messageId, int $adminUserId, ?int $maxAgeSeconds = null): bool
    {
        $message = $this->getMessageForUser($messageId, $adminUserId);
        if ($message['pending_confirmation'] !== 1) {
            return false;
        }
        if ($maxAgeSeconds !== null && $this->ages[$messageId] > $maxAgeSeconds) {
            return false;
        }
        $this->messages[$messageId]['pending_confirmation'] = 0;

        return true;
    }

    public function resolveConfirmation(
        int $messageId,
        bool $confirmed,
        ?int $adminUserId = null,
        ?array $toolCalls = null
    ): void {
        $this->messages[$messageId]['pending_confirmation'] = 0;
        if ($toolCalls !== null) {
            $this->messages[$messageId]['tool_calls'] = (string)json_encode($toolCalls);
        }
    }

    public function ageMessage(int $messageId, int $seconds): void
    {
        $this->ages[$messageId] = $seconds;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function messagesWithRole(int $conversationId, string $role): array
    {
        return array_values(array_filter(
            $this->getMessages($conversationId),
            static fn (array $message): bool => $message['role'] === $role
        ));
    }
}
