<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Flag;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepositoryInterface;

/**
 * Copies a flagged turn out of the conversation at the moment it is flagged.
 *
 * A flag that only pointed at mago_message and mago_usage_log would be empty exactly when it is
 * needed: the usage payloads are written only while debug logging is on, and UsageLogCleaner nulls
 * them again after the retention period. So the evidence is copied into the flag row instead, and
 * survives both the purge and the conversation being deleted.
 */
class SnapshotBuilder
{
    /** Messages before the flagged one that are copied along, so the turn has its question */
    private const CONTEXT_MESSAGES = 6;

    private const ROLE_USER = 'user';
    private const ROLE_ASSISTANT = 'assistant';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Json $json,
        private readonly ConfigRepositoryInterface $configRepository,
        private readonly SnapshotSizeLimit $sizeLimit
    ) {
    }

    /**
     * @return array<string, mixed>|null Null when the message does not exist, is not an answer, or is
     *     not in a conversation of this admin: a flag must not be a way to copy someone else's
     *     conversation
     */
    public function build(int $messageId, int $adminUserId): ?array
    {
        $connection = $this->resourceConnection->getConnection();

        $message = $connection->fetchRow(
            $connection->select()
                ->from(['message' => $this->resourceConnection->getTableName('mago_message')])
                ->join(
                    ['conversation' => $this->resourceConnection->getTableName('mago_conversation')],
                    'conversation.entity_id = message.conversation_id',
                    []
                )
                ->where('message.entity_id = ?', $messageId)
                ->where('conversation.admin_user_id = ?', $adminUserId)
        );

        if (!$message || $message['role'] !== self::ROLE_ASSISTANT) {
            return null;
        }

        $conversationId = (int)$message['conversation_id'];

        return $this->sizeLimit->fit([
            'flagged_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'flagged_by_admin_user_id' => $adminUserId,
            'environment' => $this->environment(),
            'conversation' => $this->conversation($conversationId),
            'answer' => $this->message($message),
            'context' => $this->context($conversationId, $messageId),
            'usage' => $this->turnUsage($conversationId, $messageId, (string)$message['created_at'])?->toArray(),
        ]);
    }

    /**
     * The provider calls of the flagged turn, or null when the turn made none (a slash command).
     */
    private function turnUsage(int $conversationId, int $messageId, string $answeredAt): ?TurnUsage
    {
        $connection = $this->resourceConnection->getConnection();
        $turnStart = $this->turnStart($conversationId, $messageId);
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName('mago_usage_log'))
                ->where('conversation_id = ?', $conversationId)
                ->where($this->startCondition($turnStart), (string)($turnStart['created_at'] ?? $answeredAt))
                ->where('created_at <= ?', $answeredAt)
                ->order('entity_id ASC')
        );

        return TurnUsage::fromRows($rows, $this->decode(...));
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        return [
            'module_version' => $this->configRepository->getExtensionVersion(),
            'magento_version' => $this->configRepository->getMagentoVersion(),
            'php_version' => PHP_VERSION,
            'debug_logging' => $this->configRepository->isDebugEnabled(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversation(int $conversationId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName('mago_conversation'))
                ->where('entity_id = ?', $conversationId)
        );

        return [
            'id' => $conversationId,
            'title' => (string)($row['title'] ?? ''),
            'started_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    /**
     * The messages leading up to the answer, oldest first, so the flag carries the question too.
     *
     * @return list<array<string, mixed>>
     */
    private function context(int $conversationId, int $messageId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName('mago_message'))
                ->where('conversation_id = ?', $conversationId)
                ->where('entity_id < ?', $messageId)
                ->order('entity_id DESC')
                ->limit(self::CONTEXT_MESSAGES)
        );

        $messages = [];
        foreach (array_reverse($rows) as $row) {
            $messages[] = $this->message($row);
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function message(array $row): array
    {
        return [
            'id' => (int)$row['entity_id'],
            'role' => (string)$row['role'],
            'content' => (string)($row['content'] ?? ''),
            'tool_calls' => $this->decode((string)($row['tool_calls'] ?? '')),
            'tool_call_id' => $row['tool_call_id'] ?? null,
            'page_context' => $this->decode((string)($row['page_context'] ?? '')),
            'created_at' => (string)$row['created_at'],
        ];
    }

    /**
     * The message the flagged turn began with: the latest user message, or assistant message awaiting
     * confirmation, before the answer. Usage rows only carry a conversation and a timestamp, so this
     * is the lower bound that keeps an earlier turn's provider calls out of the flag.
     *
     * @return array{created_at: string, role: string}|null
     */
    private function turnStart(int $conversationId, int $messageId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName('mago_message'), ['created_at', 'role'])
                ->where('conversation_id = ?', $conversationId)
                ->where('entity_id < ?', $messageId)
                ->where('role IN (?)', [self::ROLE_USER, self::ROLE_ASSISTANT])
                ->order('entity_id DESC')
                ->limit(1)
        );

        return is_array($row) ? ['created_at' => (string)$row['created_at'], 'role' => (string)$row['role']] : null;
    }

    /**
     * Timestamps are whole seconds. A question is stored before its provider calls, so a call in the
     * same second belongs to the turn. An answer awaiting confirmation is stored after the calls that
     * proposed the write, so a call in its second belongs to the turn before.
     *
     * @param array{created_at: string, role: string}|null $turnStart
     */
    private function startCondition(?array $turnStart): string
    {
        return ($turnStart['role'] ?? null) === self::ROLE_ASSISTANT ? 'created_at > ?' : 'created_at >= ?';
    }

    /**
     * Stored JSON as an array, null where the column was empty, or the raw string when it is not
     * JSON after all.
     */
    private function decode(string $value): mixed
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return $this->json->unserialize($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
