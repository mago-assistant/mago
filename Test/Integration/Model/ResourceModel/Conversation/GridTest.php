<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Model\ResourceModel\Conversation;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #310: the conversations grid filters, sorts, searches and pages in SQL, through the same
 * DataProvider and collection the listing uses
 */
final class GridTest extends TestCase
{
    private const ADMIN_USER_ID = 555201;

    private ResourceConnection $resourceConnection;
    private DataProvider $dataProvider;
    private FilterBuilder $filterBuilder;

    /** @var array<string, int> conversation id by title */
    private array $conversations = [];

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
        $this->filterBuilder = $objectManager->get(FilterBuilder::class);
        $this->dataProvider = $objectManager->create(DataProvider::class, [
            'name' => 'mago_conversations_listing_data_source',
            'primaryFieldName' => 'entity_id',
            'requestFieldName' => 'id',
        ]);
        $this->deleteConversationsOfTestAdmin();

        $this->conversations = [
            'Orders on hold last week' => $this->createConversation('Orders on hold last week', 1, 100),
            'Flush the full page cache' => $this->createConversation('Flush the full page cache', 5, 3000),
            'Bestselling products in March' => $this->createConversation('Bestselling products in March', 3, 900),
        ];
        $this->addFilter('admin_user_id', (string)self::ADMIN_USER_ID, 'eq');
    }

    protected function tearDown(): void
    {
        $this->deleteConversationsOfTestAdmin();
    }

    #[Test]
    public function itSortsOnAComputedColumn(): void
    {
        $this->dataProvider->addOrder('message_count', 'DESC');

        $titles = $this->titles();

        self::assertSame(
            ['Flush the full page cache', 'Bestselling products in March', 'Orders on hold last week'],
            $titles
        );
    }

    #[Test]
    public function itFiltersOnARangeOfAComputedColumn(): void
    {
        $this->addFilter('total_tokens', '500', 'gteq');
        $this->addFilter('total_tokens', '1000', 'lteq');

        $titles = $this->titles();

        self::assertSame(['Bestselling products in March'], $titles);
    }

    #[Test]
    public function itFiltersATextColumn(): void
    {
        $this->addFilter('title', '%cache%', 'like');

        $titles = $this->titles();

        self::assertSame(['Flush the full page cache'], $titles);
    }

    #[Test]
    public function itFindsAConversationByKeyword(): void
    {
        $this->addFilter('fulltext', 'bestselling', 'fulltext');

        $titles = $this->titles();

        self::assertSame(['Bestselling products in March'], $titles);
    }

    #[Test]
    public function itReturnsOnePageButCountsEveryMatch(): void
    {
        $this->dataProvider->addOrder('entity_id', 'ASC');
        $this->dataProvider->setLimit(2, 1);

        $data = $this->dataProvider->getData();

        self::assertSame(3, $data['totalRecords']);
        self::assertSame(['Flush the full page cache'], array_column($data['items'], 'title'));
    }

    #[Test]
    public function itShowsTheUsageColumnsOfAConversation(): void
    {
        $this->addFilter('title', 'Flush the full page cache', 'eq');

        $row = $this->dataProvider->getData()['items'][0];

        self::assertSame('5', (string)$row['message_count']);
        self::assertSame('3000', (string)$row['total_tokens']);
        self::assertSame('claude-test', $row['model']);
        self::assertSame('cache_manager', $row['skills_used']);
        self::assertSame(0.0126, (float)$row['estimated_cost']);
    }

    #[Test]
    public function itFiltersOnTheJoinedAdminName(): void
    {
        $this->addFilter('admin_username', '%nobody-by-this-name%', 'like');

        $titles = $this->titles();

        self::assertSame([], $titles);
    }

    #[Test]
    public function itListsTheMostRecentlyUpdatedConversationFirstByDefault(): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName('mago_conversation'),
            ['updated_at' => '2020-01-01 00:00:00'],
            ['entity_id IN (?)' => [$this->conversations['Flush the full page cache'], $this->conversations['Bestselling products in March']]]
        );
        $this->dataProvider->addOrder('updated_at', 'DESC');

        $titles = $this->titles();

        self::assertSame('Orders on hold last week', $titles[0]);
    }

    /**
     * @return string[]
     */
    private function titles(): array
    {
        return array_column($this->dataProvider->getData()['items'], 'title');
    }

    private function addFilter(string $field, string $value, string $conditionType): void
    {
        $this->dataProvider->addFilter(
            $this->filterBuilder->setField($field)->setValue($value)->setConditionType($conditionType)->create()
        );
    }

    private function createConversation(string $title, int $messageCount, int $totalTokens): int
    {
        $connection = $this->resourceConnection->getConnection();
        $conversationTable = $this->resourceConnection->getTableName('mago_conversation');
        $connection->insert($conversationTable, ['admin_user_id' => self::ADMIN_USER_ID, 'title' => $title]);
        $conversationId = (int)$connection->lastInsertId($conversationTable);

        foreach (range(1, $messageCount) as $index) {
            $connection->insert($this->resourceConnection->getTableName('mago_message'), [
                'conversation_id' => $conversationId,
                'role' => $index % 2 === 1 ? 'user' : 'assistant',
                'content' => 'Message ' . $index,
            ]);
        }
        $connection->insert($this->resourceConnection->getTableName('mago_usage_log'), [
            'admin_user_id' => self::ADMIN_USER_ID,
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

    private function deleteConversationsOfTestAdmin(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $conversationTable = $this->resourceConnection->getTableName('mago_conversation');
        $ids = $connection->fetchCol(
            $connection->select()->from($conversationTable, 'entity_id')->where('admin_user_id = ?', self::ADMIN_USER_ID)
        );
        if ($ids === []) {
            return;
        }
        $connection->delete($this->resourceConnection->getTableName('mago_usage_log'), ['conversation_id IN (?)' => $ids]);
        $connection->delete($this->resourceConnection->getTableName('mago_message'), ['conversation_id IN (?)' => $ids]);
        $connection->delete($conversationTable, ['entity_id IN (?)' => $ids]);
    }
}
