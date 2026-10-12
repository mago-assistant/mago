<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Conversation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepositoryInterface;

/**
 * Retention purge for conversation history (issue #108, GDPR art. 5(1)(e)/17). Conversations not
 * touched within the retention window are deleted; the mago_message and mago_pii_token foreign keys
 * cascade, so the stored messages and the per-conversation PII vault go with them in the same
 * statement. The mago_usage_log foreign key is ON DELETE SET NULL, so its debug payloads (the full
 * prompt, including conversation text) are scrubbed for the same cutoff first, before the delete
 * detaches them. Deleting by updated_at, not created_at, so a long but still-used conversation survives.
 *
 * Flagged answers hold their own copy of the conversation, so the same window applies to them,
 * counted from the moment the flag was made: flagging an answer in an old conversation keeps that
 * copy for the full window, and deleting the conversation does not shorten or lengthen it.
 */
class ConversationCleaner
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ConfigRepositoryInterface $configRepository
    ) {
    }

    /**
     * @return int Number of conversations deleted
     */
    public function clean(): int
    {
        $days = $this->configRepository->getHistoryRetentionDays();
        if ($days === 0) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_conversation');
        $cutoff = (new \DateTimeImmutable(sprintf('-%d days', $days), new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $expiredConversationIds = $connection->select()
            ->from($table, 'entity_id')
            ->where('updated_at < ?', $cutoff);

        $this->scrubUsageLogPayloads($connection, $expiredConversationIds);
        $this->deleteFlags($connection, $cutoff);

        return $connection->delete($table, ['updated_at < ?' => $cutoff]);
    }

    /**
     * Null the debug payloads of usage-log rows tied to the conversations about to be deleted. Once
     * the conversation is gone the ON DELETE SET NULL leaves conversation_id null, so neither this
     * purge nor Delete/MassDelete could reach the prompt text again; scrub it while it is still linked.
     */
    private function scrubUsageLogPayloads(AdapterInterface $connection, Select $expiredConversationIds): void
    {
        $connection->update(
            $this->resourceConnection->getTableName('mago_usage_log'),
            ['request_payload' => null, 'response_payload' => null],
            ['conversation_id IN (?)' => $expiredConversationIds]
        );
    }

    private function deleteFlags(AdapterInterface $connection, string $cutoff): void
    {
        $connection->delete($this->resourceConnection->getTableName('mago_flag'), ['created_at < ?' => $cutoff]);
    }
}
