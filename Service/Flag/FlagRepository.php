<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Flag;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Flagged answers, stored and read the way the rest of this module talks to its tables: through the
 * connection, without an ORM model in between.
 */
class FlagRepository
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly SnapshotBuilder $snapshotBuilder,
        private readonly Json $json
    ) {
    }

    /**
     * Flag an answer, or return the existing flag when it already carries one.
     *
     * The snapshot is taken here and never refreshed: a flag is what the answer looked like when
     * someone thought it was wrong, not what the conversation looks like now.
     *
     * @return array{id: int, created: bool}|null Null when the message is not an answer
     */
    public function flag(int $messageId, int $adminUserId, string $note = ''): ?array
    {
        $existing = $this->findByMessage($messageId);
        if ($existing) {
            return ['id' => (int)$existing['entity_id'], 'created' => false];
        }

        $snapshot = $this->snapshotBuilder->build($messageId, $adminUserId);
        if ($snapshot === null) {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->table(), [
            'message_id' => $messageId,
            'conversation_id' => $snapshot['conversation']['id'],
            'admin_user_id' => $adminUserId,
            'status' => self::STATUS_OPEN,
            'note' => $note !== '' ? $note : null,
            'snapshot' => (string)$this->json->serialize($snapshot),
            // Copied out of the snapshot so the grid can filter and sort on them in SQL.
            // The snapshot stays the record; these three are its index.
            'answer_preview' => $this->preview((string)($snapshot['answer']['content'] ?? '')),
            'model' => $this->modelLabel($snapshot),
            'skills' => mb_substr((string)($snapshot['usage']['skill_names'] ?? ''), 0, 255) ?: null,
        ]);

        return ['id' => (int)$connection->lastInsertId($this->table()), 'created' => true];
    }

    /**
     * Remove the flag on a message. Returns whether there was one.
     */
    public function unflag(int $messageId): bool
    {
        return $this->resourceConnection->getConnection()
            ->delete($this->table(), ['message_id = ?' => $messageId]) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByMessage(int $messageId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->table())->where('message_id = ?', $messageId)
        );

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $flagId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->table())->where('entity_id = ?', $flagId)
        );

        return $row ?: null;
    }

    /**
     * Which of the given messages carry a flag, so the panel can draw the history with its flags on.
     *
     * @param list<int> $messageIds
     * @return list<int>
     */
    public function flaggedAmong(array $messageIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $messageIds)));
        if ($ids === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $flagged = [];
        foreach ($connection->fetchCol(
            $connection->select()->from($this->table(), ['message_id'])->where('message_id IN (?)', $ids)
        ) as $messageId) {
            $flagged[] = (int)$messageId;
        }

        return $flagged;
    }

    public function setStatus(int $flagId, string $status): void
    {
        if (!in_array($status, [self::STATUS_OPEN, self::STATUS_RESOLVED], true)) {
            return;
        }

        $this->resourceConnection->getConnection()
            ->update($this->table(), ['status' => $status], ['entity_id = ?' => $flagId]);
    }

    public function setNote(int $flagId, string $note): void
    {
        $this->resourceConnection->getConnection()
            ->update($this->table(), ['note' => $note !== '' ? $note : null], ['entity_id = ?' => $flagId]);
    }

    /**
     * @param array<mixed> $flagIds Whatever the caller was handed; only usable ids survive
     */
    public function delete(array $flagIds): int
    {
        $ids = [];
        foreach ($flagIds as $flagId) {
            if ((int)$flagId > 0) {
                $ids[] = (int)$flagId;
            }
        }

        if ($ids === []) {
            return 0;
        }

        return $this->resourceConnection->getConnection()->delete($this->table(), ['entity_id IN (?)' => $ids]);
    }

    /**
     * The stored snapshot as an array.
     *
     * @return array<string, mixed>
     */
    public function snapshot(array $flag): array
    {
        $stored = (string)($flag['snapshot'] ?? '');
        if (trim($stored) === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($stored);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The opening of the answer, short enough for a grid cell.
     */
    private function preview(string $content): ?string
    {
        $content = trim((string)preg_replace('/\s+/', ' ', $content));
        if ($content === '') {
            return null;
        }

        return mb_strlen($content) > 250 ? mb_substr($content, 0, 250) . '…' : $content;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function modelLabel(array $snapshot): ?string
    {
        $label = trim(
            (string)($snapshot['usage']['provider'] ?? '') . ' ' . (string)($snapshot['usage']['model'] ?? '')
        );

        return $label !== '' ? mb_substr($label, 0, 120) : null;
    }

    private function table(): string
    {
        return $this->resourceConnection->getTableName('mago_flag');
    }
}
