<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Conversation;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Model\Conversation\ConfirmationAlreadyHandledException;
use MagoAssistant\Mago\Model\Conversation\ConfirmRequest;
use MagoAssistant\Mago\Service\Conversation\ConfirmationClaim;
use MagoAssistant\Mago\Service\Conversation\ProposalSuperseder;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConversationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProposalSupersederTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private FakeConversationRepository $repository;
    private ProposalSuperseder $superseder;
    private int $conversationId;

    protected function setUp(): void
    {
        $this->repository = new FakeConversationRepository();
        $this->superseder = new ProposalSuperseder($this->repository, new Json());
        $this->conversationId = $this->repository->create(self::ADMIN_USER_ID);
    }

    #[Test]
    public function itRetiresEveryPendingProposalOfTheConversation(): void
    {
        $olderProposalId = $this->proposal(['id' => 'call_1', 'name' => 'cache_manager', 'input' => ['action' => 'clean']]);
        $newerProposalId = $this->proposal(['id' => 'call_2', 'name' => 'index_manager', 'input' => ['action' => 'reindex']]);

        $this->superseder->supersedePendingProposals($this->conversationId, self::ADMIN_USER_ID);

        self::assertSame(0, $this->repository->getMessageById($olderProposalId)['pending_confirmation']);
        self::assertSame(0, $this->repository->getMessageById($newerProposalId)['pending_confirmation']);
    }

    #[Test]
    public function itStampsTheRetiredCallsSupersededForTheReloadedCard(): void
    {
        $proposalId = $this->proposal(['id' => 'call_1', 'name' => 'cache_manager', 'input' => ['action' => 'clean']]);

        $this->superseder->supersedePendingProposals($this->conversationId, self::ADMIN_USER_ID);

        self::assertSame('superseded', $this->storedToolCalls($proposalId)[0]['status']);
    }

    #[Test]
    public function itAnswersEachRetiredCallWithASkippedToolResult(): void
    {
        $this->proposal(
            ['id' => 'call_1', 'name' => 'cms_data', 'input' => ['action' => 'update_block']],
            ['id' => 'call_2', 'name' => 'cms_data', 'input' => ['action' => 'update_block']]
        );

        $this->superseder->supersedePendingProposals($this->conversationId, self::ADMIN_USER_ID);

        $toolResults = $this->repository->messagesWithRole($this->conversationId, 'tool');
        self::assertSame(['call_1', 'call_2'], array_column($toolResults, 'tool_call_id'));
        self::assertStringContainsString('"skipped":true', (string)$toolResults[0]['content']);
    }

    /**
     * A proposal buried under later turns predates the fix; its results would land at the end of
     * the conversation, far from their calls, which the providers reject. It is retired silently.
     */
    #[Test]
    public function itStoresNoResultsForAProposalBuriedUnderLaterTurns(): void
    {
        $buriedProposalId = $this->proposal(['id' => 'call_1', 'name' => 'cache_manager', 'input' => ['action' => 'clean']]);
        $this->repository->addMessage($this->conversationId, 'user', 'Something else.');
        $this->repository->addMessage($this->conversationId, 'assistant', 'On it.');

        $this->superseder->supersedePendingProposals($this->conversationId, self::ADMIN_USER_ID);

        self::assertSame(0, $this->repository->getMessageById($buriedProposalId)['pending_confirmation']);
        self::assertSame('superseded', $this->storedToolCalls($buriedProposalId)[0]['status']);
        self::assertSame([], $this->repository->messagesWithRole($this->conversationId, 'tool'));
    }

    #[Test]
    public function itMakesTheClaimRefuseASupersededProposal(): void
    {
        $proposalId = $this->proposal(['id' => 'call_1', 'name' => 'cache_manager', 'input' => ['action' => 'clean']]);
        $claim = new ConfirmationClaim($this->repository, new Json());

        $this->superseder->supersedePendingProposals($this->conversationId, self::ADMIN_USER_ID);

        $this->expectException(ConfirmationAlreadyHandledException::class);
        $claim->claimToConfirm(new ConfirmRequest($proposalId, true, null), self::ADMIN_USER_ID);
    }

    #[Test]
    public function itLeavesAnsweredAndTextOnlyMessagesAlone(): void
    {
        $answeredId = $this->repository->addMessage(
            $this->conversationId,
            'assistant',
            '',
            [['id' => 'call_1', 'name' => 'cache_manager', 'input' => ['action' => 'clean'], 'status' => 'done']],
            false
        );
        $textOnlyId = $this->repository->addMessage($this->conversationId, 'assistant', 'All done.');

        $this->superseder->supersedePendingProposals($this->conversationId, self::ADMIN_USER_ID);

        self::assertSame('done', $this->storedToolCalls($answeredId)[0]['status']);
        self::assertSame([], $this->repository->messagesWithRole($this->conversationId, 'tool'));
        self::assertSame('All done.', $this->repository->getMessageById($textOnlyId)['content']);
    }

    /**
     * @param array<string, mixed> ...$toolCalls
     */
    private function proposal(array ...$toolCalls): int
    {
        return $this->repository->addMessage($this->conversationId, 'assistant', '', $toolCalls, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedToolCalls(int $messageId): array
    {
        return (array)json_decode((string)$this->repository->getMessageById($messageId)['tool_calls'], true);
    }
}
