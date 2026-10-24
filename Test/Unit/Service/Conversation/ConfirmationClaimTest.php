<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Conversation;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Model\Conversation\ConfirmationAlreadyHandledException;
use MagoAssistant\Mago\Model\Conversation\ConfirmationExpiredException;
use MagoAssistant\Mago\Model\Conversation\ConfirmationNotAcknowledgedException;
use MagoAssistant\Mago\Model\Conversation\ConfirmRequest;
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
    private int $conversationId;
    private int $messageId;

    protected function setUp(): void
    {
        $this->repository = new FakeConversationRepository();
        $this->claim = new ConfirmationClaim($this->repository, new Json());
        $this->conversationId = $this->repository->create(self::ADMIN_USER_ID);
        $this->messageId = $this->proposal(
            ['id' => 'call_1', 'name' => 'order_manager', 'input' => ['action' => 'create_credit_memo']]
        );
    }

    #[Test]
    public function itHandsTheProposalToTheFirstConfirmAndClearsThePendingFlag(): void
    {
        $message = $this->claim->claimToConfirm(new ConfirmRequest($this->messageId, true, null), self::ADMIN_USER_ID);

        self::assertSame($this->messageId, $message['entity_id']);
        self::assertSame(0, $this->repository->getMessageById($this->messageId)['pending_confirmation']);
    }

    #[Test]
    public function itRefusesAnIrreversibleWriteWithoutTheAcknowledgement(): void
    {
        $messageId = $this->proposal($this->creditMemoCall() + ['irreversible' => true]);

        $this->expectException(ConfirmationNotAcknowledgedException::class);

        $this->claim->claimToConfirm(new ConfirmRequest($messageId, false, null), self::ADMIN_USER_ID);
    }

    #[Test]
    public function itRefusesACautionedWriteWithoutTheAcknowledgement(): void
    {
        $messageId = $this->proposal($this->configCall() + ['caution' => true]);

        $this->expectException(ConfirmationNotAcknowledgedException::class);

        $this->claim->claimToConfirm(new ConfirmRequest($messageId, false, null), self::ADMIN_USER_ID);
    }

    #[Test]
    public function itRefusesATickedSelectionThatIncludesTheIrreversibleWriteWithoutTheAcknowledgement(): void
    {
        $messageId = $this->proposal(
            $this->creditMemoCall() + ['irreversible' => true],
            ['id' => 'call_2', 'name' => 'cms_data', 'input' => ['action' => 'update_page']]
        );

        $this->expectException(ConfirmationNotAcknowledgedException::class);

        $this->claim->claimToConfirm(new ConfirmRequest($messageId, false, ['call_1', 'call_2']), self::ADMIN_USER_ID);
    }

    #[Test]
    public function itReportsTheExpiryOfAnUnacknowledgedProposalOlderThanAnHour(): void
    {
        $messageId = $this->proposal($this->creditMemoCall() + ['irreversible' => true]);
        $this->repository->ageMessage($messageId, ConfirmationClaim::MAX_AGE_SECONDS + 1);

        $this->expectException(ConfirmationExpiredException::class);

        $this->claim->claimToConfirm(new ConfirmRequest($messageId, false, null), self::ADMIN_USER_ID);
    }

    #[Test]
    public function itLeavesAnUnacknowledgedProposalPendingForARetry(): void
    {
        $messageId = $this->proposal($this->creditMemoCall() + ['irreversible' => true]);

        $refusal = $this->unacknowledgedConfirmRefusal($messageId);

        self::assertInstanceOf(ConfirmationNotAcknowledgedException::class, $refusal);
        self::assertSame(1, $this->repository->getMessageById($messageId)['pending_confirmation']);
    }

    #[Test]
    public function itConfirmsAnIrreversibleWriteOnceAcknowledged(): void
    {
        $messageId = $this->proposal($this->creditMemoCall() + ['irreversible' => true]);

        $message = $this->claim->claimToConfirm(new ConfirmRequest($messageId, true, null), self::ADMIN_USER_ID);

        self::assertSame($messageId, $message['entity_id']);
    }

    #[Test]
    public function itConfirmsAWriteTheCardAskedNoAcknowledgementForWithoutIt(): void
    {
        $message = $this->claim->claimToConfirm(new ConfirmRequest($this->messageId, false, null), self::ADMIN_USER_ID);

        self::assertSame($this->messageId, $message['entity_id']);
    }

    #[Test]
    public function itConfirmsWithoutTheAcknowledgementWhenOnlyTheReversibleWriteIsTicked(): void
    {
        $messageId = $this->proposal(
            $this->creditMemoCall() + ['irreversible' => true],
            ['id' => 'call_2', 'name' => 'cms_data', 'input' => ['action' => 'update_page']]
        );

        $message = $this->claim->claimToConfirm(new ConfirmRequest($messageId, false, ['call_2']), self::ADMIN_USER_ID);

        self::assertSame($messageId, $message['entity_id']);
    }

    #[Test]
    public function itRefusesASecondConfirmOfTheSameProposal(): void
    {
        $this->claim->claimToConfirm(new ConfirmRequest($this->messageId, true, null), self::ADMIN_USER_ID);

        $this->expectException(ConfirmationAlreadyHandledException::class);

        $this->claim->claimToConfirm(new ConfirmRequest($this->messageId, true, null), self::ADMIN_USER_ID);
    }

    #[Test]
    public function itRefusesToConfirmAProposalOlderThanAnHour(): void
    {
        $this->repository->ageMessage($this->messageId, ConfirmationClaim::MAX_AGE_SECONDS + 1);

        $this->expectException(ConfirmationExpiredException::class);

        $this->claim->claimToConfirm(new ConfirmRequest($this->messageId, true, null), self::ADMIN_USER_ID);
    }

    #[Test]
    public function itConfirmsAProposalJustInsideTheHour(): void
    {
        $this->repository->ageMessage($this->messageId, ConfirmationClaim::MAX_AGE_SECONDS - 1);

        $message = $this->claim->claimToConfirm(new ConfirmRequest($this->messageId, true, null), self::ADMIN_USER_ID);

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
        $this->claim->claimToConfirm(new ConfirmRequest($this->messageId, true, null), self::ADMIN_USER_ID);

        $this->expectException(ConfirmationAlreadyHandledException::class);

        $this->claim->claimToReject($this->messageId, self::ADMIN_USER_ID);
    }

    #[Test]
    public function itRefusesToConfirmAProposalThatWasRejected(): void
    {
        $this->claim->claimToReject($this->messageId, self::ADMIN_USER_ID);

        $this->expectException(ConfirmationAlreadyHandledException::class);

        $this->claim->claimToConfirm(new ConfirmRequest($this->messageId, true, null), self::ADMIN_USER_ID);
    }

    #[Test]
    public function itLeavesAnotherAdminsProposalPending(): void
    {
        $failure = $this->failureOfConfirmBy(self::OTHER_ADMIN_USER_ID);

        self::assertInstanceOf(ConversationNotFoundException::class, $failure);
        self::assertSame(1, $this->repository->getMessageById($this->messageId)['pending_confirmation']);
    }

    /**
     * @param array<string, mixed> ...$toolCalls
     */
    private function proposal(array ...$toolCalls): int
    {
        return $this->repository->addMessage($this->conversationId, 'assistant', 'Proposing.', $toolCalls, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function creditMemoCall(): array
    {
        return ['id' => 'call_1', 'name' => 'order_manager', 'input' => ['action' => 'create_credit_memo']];
    }

    /**
     * @return array<string, mixed>
     */
    private function configCall(): array
    {
        return ['id' => 'call_1', 'name' => 'config_writer', 'input' => ['path' => 'design/head/includes']];
    }

    private function unacknowledgedConfirmRefusal(int $messageId): ?\Throwable
    {
        try {
            $this->claim->claimToConfirm(new ConfirmRequest($messageId, false, null), self::ADMIN_USER_ID);
        } catch (\Throwable $exception) {
            return $exception;
        }

        return null;
    }

    private function failureOfConfirmBy(int $adminUserId): ?\Throwable
    {
        try {
            $this->claim->claimToConfirm(new ConfirmRequest($this->messageId, true, null), $adminUserId);
        } catch (\Throwable $exception) {
            return $exception;
        }

        return null;
    }
}
