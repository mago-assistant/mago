<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\WebApi;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Model\WebApi\ChatManagement;
use MagoAssistant\Mago\Service\Conversation\ConfirmationClaim;
use MagoAssistant\Mago\Service\Conversation\ProposalSuperseder;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeChatService;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConversationRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeUserContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatManagementTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private FakeConversationRepository $repository;
    private FakeChatService $chatService;
    private ChatManagement $chatManagement;
    private ConversationVault $vault;
    private int $conversationId;
    private int $messageId;

    protected function setUp(): void
    {
        $this->repository = new FakeConversationRepository();
        $this->chatService = new FakeChatService(static fn (array $toolCall): array => ['success' => true]);
        $vault = $this->vault = new ConversationVault();
        $this->chatManagement = new ChatManagement(
            $this->chatService,
            $this->repository,
            new FakeUserContext(self::ADMIN_USER_ID),
            new Json(),
            new ErrorReporter(new ErrorLogger(new FakeLogger(), new Json()), new PiiHeuristic()),
            new PrivacyService(new PrivacyFilter($vault, new PiiHeuristic()), $vault, new PiiHeuristic()),
            new ConfirmationClaim($this->repository, new Json()),
            new ProposalSuperseder($this->repository, new Json())
        );
        $this->conversationId = $this->repository->create(self::ADMIN_USER_ID);
        $this->messageId = $this->repository->addMessage(
            $this->conversationId,
            'assistant',
            'Creating the coupon.',
            [['id' => 'call_1', 'name' => 'coupon_manager', 'input' => ['action' => 'create_rule']]],
            true
        );
    }

    #[Test]
    public function itRunsTheConfirmedWriteOnce(): void
    {
        $response = $this->decode($this->chatManagement->confirmAction($this->messageId));

        self::assertTrue($response['success'] ?? false);
        self::assertSame([['action' => 'create_rule']], $this->chatService->inputs());
    }

    #[Test]
    public function itRunsNothingForAWriteThatNeedsTheAcknowledgementWithoutIt(): void
    {
        $messageId = $this->irreversibleProposal();

        $response = $this->decode($this->chatManagement->confirmAction($messageId));

        self::assertStringContainsString('understand what it changes', (string)($response['error'] ?? ''));
        self::assertSame([], $this->chatService->toolCalls);
    }

    #[Test]
    public function itRunsAWriteThatNeedsTheAcknowledgementOnceAcknowledged(): void
    {
        $messageId = $this->irreversibleProposal();

        $this->chatManagement->confirmAction($messageId, true);

        self::assertCount(1, $this->chatService->toolCalls);
    }

    #[Test]
    public function itRunsNothingWhenTheSameProposalIsConfirmedAgain(): void
    {
        $this->chatManagement->confirmAction($this->messageId);

        $response = $this->decode($this->chatManagement->confirmAction($this->messageId));

        self::assertStringContainsString('already been handled', (string)($response['error'] ?? ''));
        self::assertCount(1, $this->chatService->toolCalls);
        self::assertCount(1, $this->repository->messagesWithRole($this->conversationId, 'tool'));
    }

    #[Test]
    public function itRunsNothingForAProposalOlderThanAnHour(): void
    {
        $this->repository->ageMessage($this->messageId, ConfirmationClaim::MAX_AGE_SECONDS + 1);

        $response = $this->decode($this->chatManagement->confirmAction($this->messageId));

        self::assertStringContainsString('more than an hour ago', (string)($response['error'] ?? ''));
        self::assertSame([], $this->chatService->toolCalls);
    }

    #[Test]
    public function itDoesNotRejectAProposalThatAlreadyRan(): void
    {
        $this->chatManagement->confirmAction($this->messageId);

        $response = $this->decode($this->chatManagement->rejectAction($this->messageId));

        self::assertStringContainsString('already been handled', (string)($response['error'] ?? ''));
        self::assertSame([], array_filter(
            $this->repository->messagesWithRole($this->conversationId, 'assistant'),
            static fn (array $message): bool => str_contains((string)$message['content'], 'rejected')
        ));
    }

    #[Test]
    public function itRunsNothingOnceTheProposalWasRejected(): void
    {
        $this->chatManagement->rejectAction($this->messageId);

        $response = $this->decode($this->chatManagement->confirmAction($this->messageId));

        self::assertStringContainsString('already been handled', (string)($response['error'] ?? ''));
        self::assertSame([], $this->chatService->toolCalls);
    }

    /**
     * Issue #332: REST had no selection, so "confirm" ran every action of a multi-call proposal
     */
    #[Test]
    public function itRefusesAMultiCallProposalWithoutTheTickedIds(): void
    {
        $messageId = $this->twoCallProposal();

        $response = $this->decode($this->chatManagement->confirmAction($messageId));

        self::assertStringContainsString('tool_call_ids', (string)($response['error'] ?? ''));
        self::assertSame([], $this->chatService->toolCalls);
        self::assertSame(1, $this->repository->getMessageById($messageId)['pending_confirmation']);
    }

    /**
     * #332 review: toolCallIds [] claimed the proposal, skipped everything and reported success
     */
    #[Test]
    public function itRefusesAnEmptySelectionAndLeavesTheProposalPending(): void
    {
        $messageId = $this->twoCallProposal();

        $response = $this->decode($this->chatManagement->confirmAction($messageId, false, []));

        self::assertStringContainsString('tool_call_ids', (string)($response['error'] ?? ''));
        self::assertSame([], $this->chatService->toolCalls);
        self::assertSame(1, $this->repository->getMessageById($messageId)['pending_confirmation']);
    }

    #[Test]
    public function itRefusesASelectionWithATypoAndLeavesTheProposalPending(): void
    {
        $messageId = $this->twoCallProposal();

        $response = $this->decode($this->chatManagement->confirmAction($messageId, false, ['call_a', 'nope']));

        self::assertStringContainsString('tool_call_ids', (string)($response['error'] ?? ''));
        self::assertSame([], $this->chatService->toolCalls);
        self::assertSame(1, $this->repository->getMessageById($messageId)['pending_confirmation']);
    }

    #[Test]
    public function itRunsOnlyTheTickedCallsOfAMultiCallProposal(): void
    {
        $messageId = $this->twoCallProposal();

        $this->chatManagement->confirmAction($messageId, false, ['call_b']);

        self::assertSame(['call_b'], array_column($this->chatService->toolCalls, 'id'));
    }

    #[Test]
    public function itSupersedesTheOpenProposalWhenANewMessageArrives(): void
    {
        $this->chatManagement->sendMessage('Something else instead.', $this->conversationId);

        $proposal = $this->repository->getMessageById($this->messageId);
        self::assertSame(0, $proposal['pending_confirmation']);
        self::assertStringContainsString('superseded', (string)$proposal['tool_calls']);
    }

    private function twoCallProposal(): int
    {
        return $this->repository->addMessage(
            $this->conversationId,
            'assistant',
            'Two blocks to enable.',
            [
                ['id' => 'call_a', 'name' => 'cms_data', 'input' => ['action' => 'update_block', 'identifier' => 'promo_a']],
                ['id' => 'call_b', 'name' => 'cms_data', 'input' => ['action' => 'update_block', 'identifier' => 'promo_b']],
            ],
            true
        );
    }

    /**
     * PR #347 review: impact lines are stored concealed to vault tokens, but a REST caller
     * rebuilding a confirmation card from getConversation has no token map, so the lines must come
     * back rehydrated, like main returned them. The input stays tokenised: it is the canonical
     * copy a confirm re-reads from the store.
     */
    #[Test]
    public function getConversationRehydratesTheStoredImpactLinesOfAPendingProposal(): void
    {
        $token = $this->vault->tokenise('000000549', 'order');
        $this->repository->addMessage($this->conversationId, 'assistant', '', [[
            'id' => 'call_3',
            'name' => 'order_manager',
            'input' => ['action' => 'cancel', 'order_number' => $token],
            'irreversible' => true,
            'impacts' => ['Order #' . $token . ' is canceled and cannot be reopened.'],
        ]], true);

        $toolCalls = $this->pendingToolCallsOf($this->decode(
            $this->chatManagement->getConversation($this->conversationId)
        ));

        self::assertSame(
            ['Order #000000549 is canceled and cannot be reopened.'],
            $toolCalls[0]['impacts']
        );
        self::assertSame($token, $toolCalls[0]['input']['order_number']);
    }

    #[Test]
    public function getConversationShowsANeutralLabelForAnImpactTokenTheVaultCannotResolve(): void
    {
        $this->repository->addMessage($this->conversationId, 'assistant', '', [[
            'id' => 'call_4',
            'name' => 'order_manager',
            'input' => ['action' => 'cancel', 'order_number' => 'mago://order_9'],
            'irreversible' => true,
            'impacts' => ['Order #mago://order_9 is canceled and cannot be reopened.'],
        ]], true);

        $toolCalls = $this->pendingToolCallsOf($this->decode(
            $this->chatManagement->getConversation($this->conversationId)
        ));

        self::assertSame(
            ['Order #[earlier record] is canceled and cannot be reopened.'],
            $toolCalls[0]['impacts']
        );
    }

    /**
     * The tool calls of the newest pending message, decoded from the JSON string shape the
     * endpoint returns them in.
     *
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function pendingToolCallsOf(array $response): array
    {
        $pending = array_values(array_filter(
            (array)($response['messages'] ?? []),
            static fn (array $message): bool => (int)($message['pending_confirmation'] ?? 0) === 1
                && is_string($message['tool_calls'] ?? null)
        ));

        self::assertNotSame([], $pending, 'the conversation holds no pending row with tool calls');

        return (array)json_decode((string)end($pending)['tool_calls'], true);
    }

    private function irreversibleProposal(): int
    {
        return $this->repository->addMessage(
            $this->conversationId,
            'assistant',
            'Refunding the order.',
            [[
                'id' => 'call_2',
                'name' => 'order_manager',
                'input' => ['action' => 'create_creditmemo'],
                'irreversible' => true,
            ]],
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        return (array)json_decode($json, true);
    }
}
