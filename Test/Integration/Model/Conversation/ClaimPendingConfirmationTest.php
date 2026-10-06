<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Model\Conversation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use MagoAssistant\Mago\Model\Conversation\ConfirmationAlreadyHandledException;
use MagoAssistant\Mago\Model\Conversation\ConfirmationExpiredException;
use MagoAssistant\Mago\Model\Conversation\Repository;
use MagoAssistant\Mago\Service\Conversation\ConfirmationClaim;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The claim is one conditional UPDATE, so its guarantees (one winner, no claim after an hour, no
 * claim of another admin's row) only hold if the SQL says so. These run it against the database.
 */
final class ClaimPendingConfirmationTest extends TestCase
{
    private const ADMIN_USER_ID = 555201;
    private const OTHER_ADMIN_USER_ID = 555202;

    private ResourceConnection $resourceConnection;
    private Repository $repository;
    private ConfirmationClaim $claim;
    private int $conversationId = 0;
    private int $messageId = 0;

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
        $this->repository = $objectManager->create(Repository::class);
        $this->claim = $objectManager->create(ConfirmationClaim::class, ['conversationRepository' => $this->repository]);
        $this->conversationId = $this->repository->create(self::ADMIN_USER_ID, 'Claim test');
        $this->messageId = $this->repository->addMessage(
            $this->conversationId,
            'assistant',
            'Creating the credit memo.',
            [['id' => 'call_1', 'name' => 'order_manager', 'input' => ['action' => 'create_credit_memo']]],
            true
        );
    }

    protected function tearDown(): void
    {
        if ($this->conversationId) {
            $this->resourceConnection->getConnection()->delete(
                $this->resourceConnection->getTableName('mago_conversation'),
                ['entity_id = ?' => $this->conversationId]
            );
        }
    }

    #[Test]
    public function onlyTheFirstOfTwoClaimsSucceeds(): void
    {
        $first = $this->repository->claimPendingConfirmation($this->messageId, self::ADMIN_USER_ID, ConfirmationClaim::MAX_AGE_SECONDS);
        $second = $this->repository->claimPendingConfirmation($this->messageId, self::ADMIN_USER_ID, ConfirmationClaim::MAX_AGE_SECONDS);

        self::assertTrue($first);
        self::assertFalse($second);
        self::assertSame('0', (string)$this->repository->getMessageById($this->messageId)['pending_confirmation']);
    }

    #[Test]
    public function itDoesNotClaimAnotherAdminsMessage(): void
    {
        $claimed = $this->repository->claimPendingConfirmation($this->messageId, self::OTHER_ADMIN_USER_ID);

        self::assertFalse($claimed);
        self::assertSame('1', (string)$this->repository->getMessageById($this->messageId)['pending_confirmation']);
    }

    #[Test]
    public function itDoesNotClaimAMessageOlderThanTheMaximumAge(): void
    {
        $this->backdateMessage(2);

        $claimed = $this->repository->claimPendingConfirmation($this->messageId, self::ADMIN_USER_ID, ConfirmationClaim::MAX_AGE_SECONDS);

        self::assertFalse($claimed);
        self::assertSame('1', (string)$this->repository->getMessageById($this->messageId)['pending_confirmation']);
    }

    #[Test]
    public function itClaimsAnOldMessageWhenNoMaximumAgeIsGiven(): void
    {
        $this->backdateMessage(2);

        $claimed = $this->repository->claimPendingConfirmation($this->messageId, self::ADMIN_USER_ID);

        self::assertTrue($claimed);
    }

    #[Test]
    public function aSecondConfirmIsToldTheActionWasAlreadyHandled(): void
    {
        $this->claim->claimToConfirm($this->messageId, self::ADMIN_USER_ID);

        $this->expectException(ConfirmationAlreadyHandledException::class);

        $this->claim->claimToConfirm($this->messageId, self::ADMIN_USER_ID);
    }

    #[Test]
    public function aConfirmAfterAnHourIsToldTheActionExpired(): void
    {
        $this->backdateMessage(2);

        $this->expectException(ConfirmationExpiredException::class);

        $this->claim->claimToConfirm($this->messageId, self::ADMIN_USER_ID);
    }

    private function backdateMessage(int $hours): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName('mago_message'),
            ['created_at' => new Expression('NOW() - INTERVAL ' . $hours . ' HOUR')],
            ['entity_id = ?' => $this->messageId]
        );
    }
}
