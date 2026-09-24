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

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Json $json,
        private readonly ConfigRepositoryInterface $configRepository
    ) {
    }

    /**
     * @return array<string, mixed>|null Null when the message does not exist or is not an answer
     */
    public function build(int $messageId, int $adminUserId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $messageTable = $this->resourceConnection->getTableName('mago_message');

        $message = $connection->fetchRow(
            $connection->select()->from($messageTable)->where('entity_id = ?', $messageId)
        );

        if (!$message || $message['role'] !== 'assistant') {
            return null;
        }

        $conversationId = (int)$message['conversation_id'];

        return [
            'flagged_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'flagged_by_admin_user_id' => $adminUserId,
            'environment' => $this->environment(),
            'conversation' => $this->conversation($conversationId),
            'answer' => $this->message($message),
            'context' => $this->context($conversationId, $messageId),
            'usage' => $this->usage($conversationId, (string)$message['created_at']),
        ];
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
     * The provider side of the same turn: which model answered, what it cost, and — only when debug
     * logging was on at the time — the payloads that went over the wire.
     *
     * @return array<string, mixed>|null
     */
    private function usage(int $conversationId, string $answeredAt): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName('mago_usage_log'))
                ->where('conversation_id = ?', $conversationId)
                ->where('created_at <= ?', $answeredAt)
                ->order('entity_id DESC')
                ->limit(1)
        );

        if (!$row) {
            return null;
        }

        return [
            'provider' => (string)$row['provider'],
            'model' => (string)$row['model'],
            'input_tokens' => (int)$row['input_tokens'],
            'output_tokens' => (int)$row['output_tokens'],
            'skill_names' => (string)($row['skill_names'] ?? ''),
            'request_payload' => $this->decode((string)($row['request_payload'] ?? '')),
            'response_payload' => $this->decode((string)($row['response_payload'] ?? '')),
            'logged_at' => (string)$row['created_at'],
        ];
    }

    /**
     * Stored JSON as an array, or null where the column was empty or is not JSON after all.
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
