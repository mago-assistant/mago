<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Conversation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepositoryInterface;
use MagoAssistant\Mago\Service\Conversation\ConversationCleaner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationCleanerTest extends TestCase
{
    private ConfigRepositoryInterface $config;
    private AdapterInterface $connection;
    private ConversationCleaner $cleaner;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigRepositoryInterface::class);
        $this->connection = $this->createMock(AdapterInterface::class);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->cleaner = new ConversationCleaner($resource, $this->config);
    }

    #[Test]
    public function itDeletesNothingAndDoesNotTouchTheDbWhenRetentionIsZero(): void
    {
        $this->config->method('getHistoryRetentionDays')->willReturn(0);
        $this->connection->expects(self::never())->method('update');
        $this->connection->expects(self::never())->method('delete');

        self::assertSame(0, $this->cleaner->clean());
    }

    #[Test]
    public function itDeletesConversationsOlderThanTheRetentionWindow(): void
    {
        $this->config->method('getHistoryRetentionDays')->willReturn(90);
        $this->connection->method('select')->willReturn($this->selectStub());
        $this->connection->method('update')->willReturn(0);
        $deletes = $this->recordDeletes(['mago_conversation' => 7]);

        $deleted = $this->cleaner->clean();

        self::assertSame(7, $deleted);
        self::assertEqualsWithDelta(
            strtotime('-90 days'),
            strtotime($deletes->forTable('mago_conversation')[0]['updated_at < ?'] . ' UTC'),
            86400
        );
    }

    #[Test]
    public function itDeletesFlagsOlderThanTheRetentionWindowCountedFromWhenTheyWereMade(): void
    {
        $this->config->method('getHistoryRetentionDays')->willReturn(90);
        $this->connection->method('select')->willReturn($this->selectStub());
        $this->connection->method('update')->willReturn(0);
        $deletes = $this->recordDeletes(['mago_conversation' => 2]);

        $this->cleaner->clean();

        $flagDeletes = $deletes->forTable('mago_flag');
        self::assertCount(1, $flagDeletes);
        self::assertSame(['created_at < ?'], array_keys($flagDeletes[0]));
        self::assertEqualsWithDelta(
            strtotime('-90 days'),
            strtotime($flagDeletes[0]['created_at < ?'] . ' UTC'),
            60
        );
    }

    #[Test]
    public function itScrubsUsageLogDebugPayloadsForThePurgedConversationsBeforeDeleting(): void
    {
        $this->config->method('getHistoryRetentionDays')->willReturn(90);

        // The scrub selects the entity_ids of the conversations that are about to be purged.
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')
            ->with('mago_conversation', 'entity_id')->willReturnSelf();
        $select->expects(self::once())->method('where')
            ->with('updated_at < ?', self::callback('is_string'))->willReturnSelf();
        $this->connection->method('select')->willReturn($select);

        $order = [];
        $this->connection->expects(self::once())
            ->method('update')
            ->with(
                'mago_usage_log',
                ['request_payload' => null, 'response_payload' => null],
                ['conversation_id IN (?)' => $select]
            )
            ->willReturnCallback(function () use (&$order): int {
                $order[] = 'scrub';
                return 3;
            });
        $this->connection->method('delete')
            ->willReturnCallback(function (string $table) use (&$order): int {
                $order[] = $table === 'mago_conversation' ? 'delete' : 'flags';
                return $table === 'mago_conversation' ? 7 : 0;
            });

        self::assertSame(7, $this->cleaner->clean());
        self::assertSame(
            ['scrub', 'flags', 'delete'],
            $order,
            'payloads must be scrubbed while conversation_id still links them, i.e. before the delete'
        );
    }

    /**
     * @param array<string, int> $affectedRows Rows each table's delete reports, 0 when not listed
     */
    private function recordDeletes(array $affectedRows): RecordedDeletes
    {
        $deletes = new RecordedDeletes();
        $this->connection->method('delete')->willReturnCallback(
            static function (string $table, array $where) use ($deletes, $affectedRows): int {
                $deletes->record($table, $where);

                return $affectedRows[$table] ?? 0;
            }
        );

        return $deletes;
    }

    private function selectStub(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }
}
