<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Flag;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Service\Error\ErrorReporter;

/**
 * Feedback on answers, stored and read the way the rest of this module talks to its tables: through
 * the connection, without an ORM model in between.
 *
 * A thumbs down is what used to be a flag: something someone thought was wrong. A thumbs up is the
 * same row with the other rating, so what worked can be read back with the same snapshot as what
 * did not.
 */
class FlagRepository
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    public const RATING_UP = 'up';
    public const RATING_DOWN = 'down';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly SnapshotBuilder $snapshotBuilder,
        private readonly Json $json,
        private readonly ErrorReporter $errorReporter
    ) {
    }

    /**
     * Rate an answer, or change the rating of the feedback it already carries.
     *
     * The snapshot is taken on the first rating and never refreshed: feedback is what the answer
     * looked like when someone rated it, not what the conversation looks like now. Changing the
     * rating or adding a note afterwards is for the admin who gave it, and only while it is open;
     * once someone resolved it, it is evidence that was worked with and stays as it was.
     *
     * @param string $note An empty note leaves the stored one alone, unless the rating changes
     * @return int|null The feedback id, or null when the message is not an answer of this admin
     * @throws \InvalidArgumentException When the rating is not one feedback can have
     */
    public function flag(
        int $messageId,
        int $adminUserId,
        string $note = '',
        string $rating = self::RATING_DOWN
    ): ?int {
        if (!in_array($rating, [self::RATING_UP, self::RATING_DOWN], true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a rating.', $rating));
        }

        $existing = $this->findByMessage($messageId);
        if ($existing !== null) {
            $this->amend($existing, $adminUserId, $note, $rating);

            return (int)$existing['entity_id'];
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
            'rating' => $rating,
            'note' => $note !== '' ? $note : null,
            'snapshot' => (string)$this->json->serialize($snapshot),
            // Copied out of the snapshot so the grid can filter and sort on them in SQL.
            // The snapshot stays the record; these three are its index.
            'answer_preview' => $this->preview((string)($snapshot['answer']['content'] ?? '')),
            'model' => $this->modelLabel($snapshot),
            'skills' => mb_substr((string)($snapshot['usage']['skill_names'] ?? ''), 0, 255) ?: null,
        ]);

        return (int)$connection->lastInsertId($this->table());
    }

    /**
     * Take back feedback from the chat panel. Only the admin who gave it can, and only while nobody
     * has resolved it yet: after that it is evidence someone worked with, and removing it is a
     * delete under Answer Feedback, behind its own ACL resource.
     */
    public function unflag(int $messageId, int $adminUserId): bool
    {
        return $this->resourceConnection->getConnection()->delete($this->table(), [
            'message_id = ?' => $messageId,
            'admin_user_id = ?' => $adminUserId,
            'status = ?' => self::STATUS_OPEN,
        ]) > 0;
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
     * How each of the given messages was rated, so the panel can draw the history with its thumbs
     * on. Messages without feedback are left out.
     *
     * @param list<int> $messageIds
     * @return array<int, string> Rating by message id
     */
    public function ratingsAmong(array $messageIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $messageIds)));
        if ($ids === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $ratings = [];
        foreach ($connection->fetchPairs(
            $connection->select()
                ->from($this->table(), ['message_id', 'rating'])
                ->where('message_id IN (?)', $ids)
        ) as $messageId => $rating) {
            $ratings[(int)$messageId] = (string)$rating;
        }

        return $ratings;
    }

    /**
     * @throws \InvalidArgumentException When the status is not one a flag can have
     */
    public function setStatus(int $flagId, string $status): void
    {
        if (!in_array($status, [self::STATUS_OPEN, self::STATUS_RESOLVED], true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a flag status.', $status));
        }

        $this->resourceConnection->getConnection()
            ->update($this->table(), ['status' => $status], ['entity_id = ?' => $flagId]);
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
     * The stored snapshot as an array. A snapshot that no longer decodes is logged and read as empty,
     * so the flag can still be opened and deleted; the view says the snapshot could not be read.
     *
     * @param array<string, mixed> $flag
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
        } catch (\InvalidArgumentException $exception) {
            $this->errorReporter->log(
                sprintf('Flag Repository: the snapshot of flag %d could not be read', (int)($flag['entity_id'] ?? 0)),
                $exception
            );

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function amend(array $existing, int $adminUserId, string $note, string $rating): void
    {
        if ((int)$existing['admin_user_id'] !== $adminUserId || $existing['status'] !== self::STATUS_OPEN) {
            return;
        }

        $changes = [];
        if ($existing['rating'] !== $rating) {
            // The note answered "what was wrong" or "what was helpful", so it belongs to the old
            // thumb and goes with it.
            $changes['rating'] = $rating;
            $changes['note'] = null;
        }
        if ($note !== '' && $existing['note'] !== $note) {
            $changes['note'] = $note;
        }

        if ($changes !== []) {
            $this->resourceConnection->getConnection()
                ->update($this->table(), $changes, ['entity_id = ?' => (int)$existing['entity_id']]);
        }
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
