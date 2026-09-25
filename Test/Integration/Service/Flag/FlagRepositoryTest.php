<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Flag;

use Magento\Framework\App\ResourceConnection;
use MagoAssistant\Mago\Service\Flag\FlagRepository;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlagRepositoryTest extends TestCase
{
    private const ADMIN_USER_ID = 555101;

    private ResourceConnection $resourceConnection;
    private FlagRepository $flags;
    private int $conversationId = 0;

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
        $this->flags = $objectManager->create(FlagRepository::class);
        $this->conversationId = $this->createConversation();
    }

    protected function tearDown(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete(
            $this->resourceConnection->getTableName('mago_flag'),
            ['admin_user_id = ?' => self::ADMIN_USER_ID]
        );
        if ($this->conversationId) {
            $connection->delete(
                $this->resourceConnection->getTableName('mago_conversation'),
                ['entity_id = ?' => $this->conversationId]
            );
        }
    }

    #[Test]
    public function itCopiesTheQuestionAndTheAnswerIntoTheFlag(): void
    {
        $this->addMessage('user', 'How many orders were on hold last week?');
        $answerId = $this->addMessage('assistant', 'Fourteen orders were on hold.');

        $flag = $this->flags->flag($answerId, self::ADMIN_USER_ID, 'It was nine, not fourteen');

        self::assertNotNull($flag);
        self::assertTrue($flag['created']);

        $snapshot = $this->flags->snapshot((array)$this->flags->getById($flag['id']));

        self::assertSame('Fourteen orders were on hold.', $snapshot['answer']['content']);
        self::assertSame('How many orders were on hold last week?', $snapshot['context'][0]['content']);
        self::assertSame(self::ADMIN_USER_ID, $snapshot['flagged_by_admin_user_id']);
        self::assertNotEmpty($snapshot['environment']['magento_version']);
    }

    /**
     * The grid filters and sorts in SQL, so the three fields it shows from the snapshot are stored
     * as columns of their own. A flag that only had the JSON blob could not be searched at all.
     */
    #[Test]
    public function itStoresTheGridColumnsBesideTheSnapshot(): void
    {
        $this->addMessage('user', 'How many orders were on hold?');
        $answerId = $this->addMessage('assistant', 'Fourteen orders were on hold.');

        $flag = $this->flags->flag($answerId, self::ADMIN_USER_ID);
        $row = (array)$this->flags->getById((int)$flag['id']);

        self::assertSame('Fourteen orders were on hold.', $row['answer_preview']);
        // No usage row for this conversation, so there is no model to record.
        self::assertNull($row['model']);
        self::assertNull($row['skills']);
    }

    #[Test]
    public function itShortensALongAnswerForTheGrid(): void
    {
        $answerId = $this->addMessage('assistant', str_repeat('een heel lang antwoord ', 40));

        $flag = $this->flags->flag($answerId, self::ADMIN_USER_ID);
        $preview = (string)$this->flags->getById((int)$flag['id'])['answer_preview'];

        self::assertSame(251, mb_strlen($preview));
        self::assertStringEndsWith('…', $preview);
    }

    /**
     * The whole point of copying rather than referencing: the evidence has to outlive the
     * conversation, the message, and the payload purge.
     */
    #[Test]
    public function itKeepsTheSnapshotWhenTheConversationIsDeleted(): void
    {
        $answerId = $this->addMessage('assistant', 'An answer that will outlive its conversation.');
        $flag = $this->flags->flag($answerId, self::ADMIN_USER_ID);

        $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName('mago_conversation'),
            ['entity_id = ?' => $this->conversationId]
        );
        $this->conversationId = 0;

        $row = $this->flags->getById((int)$flag['id']);

        self::assertNotNull($row);
        self::assertNull($row['conversation_id']);
        self::assertNull($row['message_id']);
        self::assertSame(
            'An answer that will outlive its conversation.',
            $this->flags->snapshot($row)['answer']['content']
        );
    }

    #[Test]
    public function itFlagsAnAnswerOnlyOnce(): void
    {
        $answerId = $this->addMessage('assistant', 'One answer.');

        $first = $this->flags->flag($answerId, self::ADMIN_USER_ID);
        $second = $this->flags->flag($answerId, self::ADMIN_USER_ID);

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['id'], $second['id']);
    }

    #[Test]
    public function itRefusesToFlagAnythingButAnAnswer(): void
    {
        $questionId = $this->addMessage('user', 'A question is not an answer.');

        self::assertNull($this->flags->flag($questionId, self::ADMIN_USER_ID));
    }

    #[Test]
    public function itUnflagsAndReportsWhetherThereWasAnything(): void
    {
        $answerId = $this->addMessage('assistant', 'Flag me, then do not.');
        $this->flags->flag($answerId, self::ADMIN_USER_ID);

        self::assertTrue($this->flags->unflag($answerId));
        self::assertFalse($this->flags->unflag($answerId));
        self::assertNull($this->flags->findByMessage($answerId));
    }

    #[Test]
    public function itReportsWhichOfASetOfMessagesAreFlagged(): void
    {
        $flagged = $this->addMessage('assistant', 'This one is flagged.');
        $plain = $this->addMessage('assistant', 'This one is not.');
        $this->flags->flag($flagged, self::ADMIN_USER_ID);

        self::assertSame([$flagged], $this->flags->flaggedAmong([$flagged, $plain]));
        self::assertSame([], $this->flags->flaggedAmong([]));
    }

    #[Test]
    public function itOnlyAcceptsAStatusItKnows(): void
    {
        $answerId = $this->addMessage('assistant', 'Status test.');
        $flag = $this->flags->flag($answerId, self::ADMIN_USER_ID);

        $this->flags->setStatus((int)$flag['id'], 'something else');
        self::assertSame(FlagRepository::STATUS_OPEN, $this->flags->getById((int)$flag['id'])['status']);

        $this->flags->setStatus((int)$flag['id'], FlagRepository::STATUS_RESOLVED);
        self::assertSame(FlagRepository::STATUS_RESOLVED, $this->flags->getById((int)$flag['id'])['status']);
    }

    private function createConversation(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_conversation');
        $connection->insert($table, [
            'admin_user_id' => self::ADMIN_USER_ID,
            'title' => 'Flag repository test',
        ]);

        return (int)$connection->lastInsertId($table);
    }

    private function addMessage(string $role, string $content): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_message');
        $connection->insert($table, [
            'conversation_id' => $this->conversationId,
            'role' => $role,
            'content' => $content,
        ]);

        return (int)$connection->lastInsertId($table);
    }
}
