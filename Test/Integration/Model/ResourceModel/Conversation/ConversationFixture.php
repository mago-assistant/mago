<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Model\ResourceModel\Conversation;

use Magento\Framework\App\ResourceConnection;

/**
 * An admin user of its own with conversations, messages and usage, written straight to the tables
 * and removed again, so the conversations grid tests never touch the conversations of a real admin.
 * The admin's first name, which the grid shows as the admin, is its username.
 */
final class ConversationFixture
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly int $adminUserId,
        private readonly string $adminUsername
    ) {
    }

    public function createAdmin(): void
    {
        $this->resourceConnection->getConnection()->insert($this->resourceConnection->getTableName('admin_user'), [
            'user_id' => $this->adminUserId,
            'username' => $this->adminUsername,
            'email' => $this->adminUsername . '@example.com',
            'firstname' => $this->adminUsername,
            'lastname' => 'Test',
            'password' => 'not-a-password',
            'is_active' => 0,
        ]);
    }

    public function createConversation(string $title, int $messageCount, int $totalTokens): int
    {
        $connection = $this->resourceConnection->getConnection();
        $conversationTable = $this->resourceConnection->getTableName('mago_conversation');
        $connection->insert($conversationTable, ['admin_user_id' => $this->adminUserId, 'title' => $title]);
        $conversationId = (int)$connection->lastInsertId($conversationTable);

        foreach (range(1, $messageCount) as $index) {
            $connection->insert($this->resourceConnection->getTableName('mago_message'), [
                'conversation_id' => $conversationId,
                'role' => $index % 2 === 1 ? 'user' : 'assistant',
                'content' => 'Message ' . $index,
            ]);
        }
        $connection->insert($this->resourceConnection->getTableName('mago_usage_log'), [
            'admin_user_id' => $this->adminUserId,
            'conversation_id' => $conversationId,
            'provider' => 'anthropic',
            'model' => 'claude-test',
            'input_tokens' => (int)($totalTokens * 0.9),
            'output_tokens' => (int)($totalTokens * 0.1),
            'total_tokens' => $totalTokens,
            'skill_names' => 'cache_manager',
        ]);

        return $conversationId;
    }

    /**
     * @return string[]
     */
    public function titlesOfRemainingConversations(): array
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->fetchCol(
            $connection->select()
                ->from($this->resourceConnection->getTableName('mago_conversation'), 'title')
                ->where('admin_user_id = ?', $this->adminUserId)
                ->order('entity_id ASC')
        );
    }

    public function countUsageRows(): int
    {
        $connection = $this->resourceConnection->getConnection();

        return (int)$connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('mago_usage_log'), 'COUNT(*)')
                ->where('admin_user_id = ?', $this->adminUserId)
        );
    }

    public function removeEverything(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $conversationTable = $this->resourceConnection->getTableName('mago_conversation');
        $ids = $connection->fetchCol(
            $connection->select()->from($conversationTable, 'entity_id')->where('admin_user_id = ?', $this->adminUserId)
        );
        if ($ids !== []) {
            $connection->delete($this->resourceConnection->getTableName('mago_usage_log'), ['conversation_id IN (?)' => $ids]);
            $connection->delete($this->resourceConnection->getTableName('mago_message'), ['conversation_id IN (?)' => $ids]);
            $connection->delete($conversationTable, ['entity_id IN (?)' => $ids]);
        }
        $connection->delete($this->resourceConnection->getTableName('mago_usage_log'), ['admin_user_id = ?' => $this->adminUserId]);
        $connection->delete($this->resourceConnection->getTableName('admin_user'), ['user_id = ?' => $this->adminUserId]);
    }
}
