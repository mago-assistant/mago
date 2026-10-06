<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Conversation;

use MagoAssistant\Mago\Model\Conversation\ConfirmationAlreadyHandledException;
use MagoAssistant\Mago\Model\Conversation\ConfirmationExpiredException;
use MagoAssistant\Mago\Model\Conversation\ConversationNotFoundException;
use MagoAssistant\Mago\Service\Conversation\ConfirmationClaim;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConversationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfirmationClaimTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const OTHER_ADMIN_USER_ID = 8;

    private FakeConversationRepository $repository;
    private ConfirmationClaim $claim;
    private int $messageId;

    protected function setUp(): void
    {
        $this->repository = new FakeConversationRepository();
        $this->claim = new ConfirmationClaim($this->repository);
        $conversationId = $this->repository->create(self::ADMIN_USER_ID);
        $this->messageId = $this->repository->addMessage(
            $conversationId,
            'assistant',
            'Creating the credit memo.',
            [['id' => 'call_1', 'name' => 'order_manager', 'input' => ['action' => 'create_credit_memo']]],
            true
        );
    }

    #[Test]
    public function itHandsTheProposalToTheFirstConfirmAndClearsThePendingFlag(): void
    {
        $message = $this->claim->claimToConfirm($this->messageId, self::ADMIN_USER_ID);

        self::assertSame($this->messageId, $message['entity_id']);
        self::assertSame(0, $this->repository->getMessageById($this->messageId)['pending_confirmation']);
    }

    #[Test]
    public function itRefusesASecondConfirmOfTheSameProposal(): void
    {
        $this->claim->claimToConfirm($this->messageId, self::ADMIN_USER_ID);

        $this->expectException(ConfirmationAlreadyHandledException::class);

        $this->claim->claimToConfirm($this->messageId, self::ADMIN_USER_ID);
    }

    #[Test]
    public function itRefusesToConfirmAProposalOlderThanAnHour(): void
    {
        $this->repository->ageMessage($this->messageId, ConfirmationClaim::MAX_AGE_SECONDS + 1);

        $this->expectException(ConfirmationExpiredException::class);

        $this->claim->claimToConfirm($this->messageId, self::ADMIN_USER_ID);
    }

    #[Test]
    public function itConfirmsAProposalJustInsideTheHour(): void
    {
        $this->repository->ageMessage($this->messageId, ConfirmationClaim::MAX_AGE_SECONDS - 1);

        $message = $this->claim->claimToConfirm($this->messageId, self::ADMIN_USER_ID);

        self::assertSame($this->messageId, $message['entity_id']);
    }

    #[Test]
    public function itLetsTheAdminRejectAProposalThatExpired(): void
    {
        $this->repository->ageMessage($this->messageId, ConfirmationClaim::MAX_AGE_SECONDS + 1);

        $message = $this->claim->claimToReject($this->messageId, self::ADMIN_USER_ID);

        self::assertSame($this->messageId, $message['entity_id']);
        self::assertSame(0, $this->repository->getMessageById($this->messageId)['pending_confirmation']);
    }

    #[Test]
    public function itRefusesToRejectAProposalThatWasAlreadyConfirmed(): void
    {
        $this->claim->claimToConfirm($this->messageId, self::ADMIN_USER_ID);

        $this->expectException(ConfirmationAlreadyHandledException::class);

        $this->claim->claimToReject($this->messageId, self::ADMIN_USER_ID);
    }

    #[Test]
    public function itRefusesToConfirmAProposalThatWasRejected(): void
    {
        $this->claim->claimToReject($this->messageId, self::ADMIN_USER_ID);

        $this->expectException(ConfirmationAlreadyHandledException::class);

        $this->claim->claimToConfirm($this->messageId, self::ADMIN_USER_ID);
    }

    #[Test]
    public function itLeavesAnotherAdminsProposalPending(): void
    {
        $failure = $this->failureOfConfirmBy(self::OTHER_ADMIN_USER_ID);

        self::assertInstanceOf(ConversationNotFoundException::class, $failure);
        self::assertSame(1, $this->repository->getMessageById($this->messageId)['pending_confirmation']);
    }

    private function failureOfConfirmBy(int $adminUserId): ?\Throwable
    {
        try {
            $this->claim->claimToConfirm($this->messageId, $adminUserId);
        } catch (\Throwable $exception) {
            return $exception;
        }

        return null;
    }
}
