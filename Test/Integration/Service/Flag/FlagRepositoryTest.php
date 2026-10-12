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
    private const OTHER_ADMIN_USER_ID = 555102;

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
        $connection->delete(
            $this->resourceConnection->getTableName('mago_usage_log'),
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

        $snapshot = $this->flags->snapshot((array)$this->flags->getById($flag));

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
        $row = (array)$this->flags->getById((int)$flag);

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
        $preview = (string)$this->flags->getById((int)$flag)['answer_preview'];

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

        $row = $this->flags->getById((int)$flag);

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

        self::assertNotNull($first);
        self::assertSame($first, $second);
        self::assertCount(1, $this->flagRowsOfConversation());
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

        $first = $this->flags->unflag($answerId, self::ADMIN_USER_ID);
        $second = $this->flags->unflag($answerId, self::ADMIN_USER_ID);

        self::assertTrue($first);
        self::assertFalse($second);
        self::assertNull($this->flags->findByMessage($answerId));
    }

    #[Test]
    public function itLeavesAFlagAloneWhenSomeoneElseTriesToUnflagIt(): void
    {
        $answerId = $this->addMessage('assistant', 'Flagged by one admin.');
        $this->flags->flag($answerId, self::ADMIN_USER_ID);

        $isRemoved = $this->flags->unflag($answerId, self::OTHER_ADMIN_USER_ID);

        self::assertFalse($isRemoved);
        self::assertNotNull($this->flags->findByMessage($answerId));
    }

    #[Test]
    public function itKeepsAResolvedFlagWhenTheAnswerIsUnflaggedFromThePanel(): void
    {
        $answerId = $this->addMessage('assistant', 'Somebody already worked on this one.');
        $flag = $this->flags->flag($answerId, self::ADMIN_USER_ID);
        $this->flags->setStatus((int)$flag, FlagRepository::STATUS_RESOLVED);

        $isRemoved = $this->flags->unflag($answerId, self::ADMIN_USER_ID);

        self::assertFalse($isRemoved);
        self::assertNotNull($this->flags->findByMessage($answerId));
    }

    #[Test]
    public function itDeletesOnlyTheGivenFlagsAndIgnoresIdsThatAreNotIds(): void
    {
        $kept = $this->flags->flag($this->addMessage('assistant', 'Keep me.'), self::ADMIN_USER_ID);
        $gone = $this->flags->flag($this->addMessage('assistant', 'Delete me.'), self::ADMIN_USER_ID);

        $deleted = $this->flags->delete([(string)$gone, 'abc', 0, -3, null]);

        self::assertSame(1, $deleted);
        self::assertNull($this->flags->getById((int)$gone));
        self::assertNotNull($this->flags->getById((int)$kept));
    }

    #[Test]
    public function itDeletesNothingWhenNoUsableIdIsGiven(): void
    {
        $flag = $this->flags->flag($this->addMessage('assistant', 'Still here.'), self::ADMIN_USER_ID);

        $deleted = $this->flags->delete(['', 'x', 0]);

        self::assertSame(0, $deleted);
        self::assertNotNull($this->flags->getById((int)$flag));
    }

    #[Test]
    public function itReportsHowEachOfASetOfMessagesWasRated(): void
    {
        $down = $this->addMessage('assistant', 'This one is rated down.');
        $up = $this->addMessage('assistant', 'This one is rated up.');
        $plain = $this->addMessage('assistant', 'This one is not rated.');
        $this->flags->flag($down, self::ADMIN_USER_ID);
        $this->flags->flag($up, self::ADMIN_USER_ID, '', FlagRepository::RATING_UP);

        $ratings = $this->flags->ratingsAmong([$down, $up, $plain]);
        ksort($ratings);

        self::assertSame([$down => FlagRepository::RATING_DOWN, $up => FlagRepository::RATING_UP], $ratings);
        self::assertSame([], $this->flags->ratingsAmong([]));
    }

    /**
     * Flags made before thumbs existed were all complaints, and a caller that does not name a
     * rating still means one.
     */
    #[Test]
    public function itRatesAnAnswerDownUnlessToldOtherwise(): void
    {
        $flag = $this->flags->flag($this->addMessage('assistant', 'Rated.'), self::ADMIN_USER_ID);

        self::assertSame(FlagRepository::RATING_DOWN, $this->flags->getById((int)$flag)['rating']);
    }

    #[Test]
    public function itRefusesARatingItDoesNotKnow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->flags->flag($this->addMessage('assistant', 'Rated.'), self::ADMIN_USER_ID, '', 'sideways');
    }

    /**
     * The note comes after the thumb, in a second call, so that call has to add it to the feedback
     * the thumb made rather than start another one or take a new snapshot.
     */
    #[Test]
    public function itAddsTheNoteAndANewRatingToTheFeedbackAlreadyGiven(): void
    {
        $answerId = $this->addMessage('assistant', 'Fourteen orders were on hold.');

        $first = $this->flags->flag($answerId, self::ADMIN_USER_ID, '', FlagRepository::RATING_UP);
        $second = $this->flags->flag($answerId, self::ADMIN_USER_ID, 'It was nine', FlagRepository::RATING_DOWN);
        $third = $this->flags->flag($answerId, self::ADMIN_USER_ID, '', FlagRepository::RATING_DOWN);
        $row = (array)$this->flags->getById((int)$first);

        self::assertSame($first, $second);
        self::assertSame($first, $third);
        self::assertSame(FlagRepository::RATING_DOWN, $row['rating']);
        self::assertSame('It was nine', $row['note'], 'An empty note leaves the stored one alone');
    }

    /**
     * A note says what was wrong or what helped, so it does not carry over to the other thumb.
     */
    #[Test]
    public function itDropsTheNoteWhenTheRatingChanges(): void
    {
        $answerId = $this->addMessage('assistant', 'Fourteen orders were on hold.');

        $flag = $this->flags->flag($answerId, self::ADMIN_USER_ID, 'It was nine', FlagRepository::RATING_DOWN);
        $this->flags->flag($answerId, self::ADMIN_USER_ID, '', FlagRepository::RATING_UP);
        $row = (array)$this->flags->getById((int)$flag);

        self::assertSame(FlagRepository::RATING_UP, $row['rating']);
        self::assertNull($row['note']);
    }

    #[Test]
    public function itLeavesFeedbackAloneWhenSomeoneElseRatesIt(): void
    {
        $answerId = $this->addMessage('assistant', 'Fourteen orders were on hold.');
        $flag = $this->flags->flag($answerId, self::ADMIN_USER_ID, 'Mine', FlagRepository::RATING_DOWN);

        $this->flags->flag($answerId, self::OTHER_ADMIN_USER_ID, 'Theirs', FlagRepository::RATING_UP);
        $row = (array)$this->flags->getById((int)$flag);

        self::assertSame(FlagRepository::RATING_DOWN, $row['rating']);
        self::assertSame('Mine', $row['note']);
    }

    #[Test]
    public function itKeepsTheRatingOfResolvedFeedback(): void
    {
        $answerId = $this->addMessage('assistant', 'Fourteen orders were on hold.');
        $flag = $this->flags->flag($answerId, self::ADMIN_USER_ID, '', FlagRepository::RATING_DOWN);
        $this->flags->setStatus((int)$flag, FlagRepository::STATUS_RESOLVED);

        $this->flags->flag($answerId, self::ADMIN_USER_ID, 'Changed my mind', FlagRepository::RATING_UP);
        $row = (array)$this->flags->getById((int)$flag);

        self::assertSame(FlagRepository::RATING_DOWN, $row['rating']);
        self::assertNull($row['note']);
    }

    #[Test]
    public function itRefusesAStatusItDoesNotKnow(): void
    {
        $flag = $this->flags->flag($this->addMessage('assistant', 'Status test.'), self::ADMIN_USER_ID);

        try {
            $this->flags->setStatus((int)$flag, 'something else');
            self::fail('An unknown status must be refused, not silently ignored.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(FlagRepository::STATUS_OPEN, $this->flags->getById((int)$flag)['status']);
    }

    #[Test]
    public function itResolvesAFlag(): void
    {
        $flag = $this->flags->flag($this->addMessage('assistant', 'Status test.'), self::ADMIN_USER_ID);

        $this->flags->setStatus((int)$flag, FlagRepository::STATUS_RESOLVED);

        self::assertSame(FlagRepository::STATUS_RESOLVED, $this->flags->getById((int)$flag)['status']);
    }

    /**
     * A tool-using turn logs one usage row per provider call. The flag has to carry all of them, or
     * the skills that were used and most of the tokens are lost.
     */
    #[Test]
    public function itCopiesEveryProviderCallOfTheFlaggedTurn(): void
    {
        $this->addMessage('user', 'How many orders are on hold?', '2026-09-30 10:00:00');
        $this->addUsage('get_orders', 1200, 30, '2026-09-30 10:00:02', '{"call":"tool"}');
        $this->addUsage(null, 1500, 80, '2026-09-30 10:00:04', '{"call":"answer"}');
        $answerId = $this->addMessage('assistant', 'Fourteen.', '2026-09-30 10:00:05');

        $flag = $this->flags->flag($answerId, self::ADMIN_USER_ID);

        $row = (array)$this->flags->getById((int)$flag);
        $usage = $this->flags->snapshot($row)['usage'];
        self::assertSame('get_orders', $row['skills']);
        self::assertSame('anthropic claude-sonnet', $row['model']);
        self::assertSame(2700, $usage['input_tokens']);
        self::assertSame(110, $usage['output_tokens']);
        self::assertSame(['call' => 'tool'], $usage['calls'][0]['request_payload']);
        self::assertSame(['call' => 'answer'], $usage['calls'][1]['request_payload']);
    }

    /**
     * A slash command answers without calling a provider. The turn before it did, and its usage row
     * must not be passed off as the evidence for this answer.
     */
    #[Test]
    public function itDoesNotBorrowTheUsageOfAnEarlierTurn(): void
    {
        $this->addMessage('user', 'How many orders are on hold?', '2026-09-30 10:00:00');
        $this->addUsage('get_orders', 1200, 30, '2026-09-30 10:00:02', '{"call":"earlier"}');
        $this->addMessage('assistant', 'Fourteen.', '2026-09-30 10:00:03');
        $this->addMessage('user', '/cache flush', '2026-09-30 10:05:00');
        $answerId = $this->addMessage('assistant', 'The cache was flushed.', '2026-09-30 10:05:01');

        $flag = $this->flags->flag($answerId, self::ADMIN_USER_ID);

        $row = (array)$this->flags->getById((int)$flag);
        self::assertNull($this->flags->snapshot($row)['usage']);
        self::assertNull($row['model']);
        self::assertNull($row['skills']);
    }

    #[Test]
    public function itRefusesToFlagAnAnswerFromSomeoneElsesConversation(): void
    {
        $answerId = $this->addMessage('assistant', 'Only for the admin who asked.');

        $flagId = $this->flags->flag($answerId, self::OTHER_ADMIN_USER_ID);

        self::assertNull($flagId);
        self::assertSame([], $this->flagRowsOfConversation());
    }

    /**
     * A write is proposed in one turn and carried out in the next: the proposal is stored as an
     * answer awaiting confirmation, and the follow-up answer comes after the admin confirms. The
     * follow-up's flag gets the calls made after the confirmation, not the call that proposed the
     * write, even when that call was logged in the same second as the pending answer.
     */
    #[Test]
    public function itStartsAConfirmedTurnAfterTheAnswerThatAskedForConfirmation(): void
    {
        $this->addMessage('user', 'Create the page.', '2026-09-30 10:00:00');
        $this->addUsage('cms_data', 900, 40, '2026-09-30 10:00:02', '{"call":"proposal"}');
        $this->addMessage('assistant', 'Shall I create it?', '2026-09-30 10:00:02');
        $this->addMessage('tool', '{"created":true}', '2026-09-30 10:00:20');
        $this->addUsage(null, 1100, 60, '2026-09-30 10:00:22', '{"call":"follow-up"}');
        $answerId = $this->addMessage('assistant', 'The page is live.', '2026-09-30 10:00:23');

        $flagId = $this->flags->flag($answerId, self::ADMIN_USER_ID);

        $calls = $this->flags->snapshot((array)$this->flags->getById((int)$flagId))['usage']['calls'];
        self::assertCount(1, $calls);
        self::assertSame(['call' => 'follow-up'], $calls[0]['request_payload']);
    }

    #[Test]
    public function itCountsACallInTheSameSecondAsTheQuestionAsPartOfTheTurn(): void
    {
        $this->addMessage('user', 'How many orders are on hold?', '2026-09-30 10:00:00');
        $this->addUsage(null, 900, 10, '2026-09-30 10:00:00', '{"call":"answer"}');
        $answerId = $this->addMessage('assistant', 'Fourteen.', '2026-09-30 10:00:01');

        $flagId = $this->flags->flag($answerId, self::ADMIN_USER_ID);

        $calls = $this->flags->snapshot((array)$this->flags->getById((int)$flagId))['usage']['calls'];
        self::assertCount(1, $calls);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flagRowsOfConversation(): array
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName('mago_flag'))
                ->where('conversation_id = ?', $this->conversationId)
        );
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

    private function addMessage(string $role, string $content, ?string $createdAt = null): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_message');
        $connection->insert($table, array_filter([
            'conversation_id' => $this->conversationId,
            'role' => $role,
            'content' => $content,
            'created_at' => $createdAt,
        ], static fn (mixed $value): bool => $value !== null));

        return (int)$connection->lastInsertId($table);
    }

    private function addUsage(
        ?string $skillNames,
        int $inputTokens,
        int $outputTokens,
        string $createdAt,
        string $requestPayload
    ): void {
        $this->resourceConnection->getConnection()->insert(
            $this->resourceConnection->getTableName('mago_usage_log'),
            [
                'admin_user_id' => self::ADMIN_USER_ID,
                'conversation_id' => $this->conversationId,
                'provider' => 'anthropic',
                'model' => 'claude-sonnet',
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
                'skill_names' => $skillNames,
                'request_payload' => $requestPayload,
                'created_at' => $createdAt,
            ]
        );
    }
}
