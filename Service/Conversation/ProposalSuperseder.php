<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Conversation;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\ConversationRepositoryInterface;

/**
 * Retires the open proposals of a conversation the moment the admin sends a new message (#332).
 *
 * An unanswered card used to stay silently live, and its Allow button ran whatever was proposed
 * last instead of what the card shows. Moving past a proposal now supersedes it: the pending flag
 * is cleared so ConfirmationClaim refuses it, and its calls are stamped superseded so a reloaded
 * conversation draws the card disabled.
 *
 * Each proposal is taken through the same atomic claim a confirm uses, so a confirm racing this
 * (two tabs, or REST and the panel at once) wins or loses whole: the loser does nothing, and the
 * conversation never ends up with a write that ran recorded as skipped.
 *
 * Only the proposal that is still the conversation's newest message gets a skipped tool result per
 * call, which keeps its chain resolved for the provider and stops the model proposing the same
 * action again. The results of a proposal buried under later turns (stored before this fix
 * existed) would land at the end of the conversation, far from their calls, and OpenAI and
 * Anthropic reject a tool result that does not directly follow its call. Those proposals are
 * retired without results; message replay keeps dropping their unanswered calls exactly as it did
 * before the fix.
 */
class ProposalSuperseder
{
    public const SUPERSEDED_STATUS = 'superseded';

    private const SKIPPED_RESULT = [
        'skipped' => true,
        'reason' => 'The administrator sent a new message without answering this proposal. Nothing was run. '
            . 'Propose it again if it is still wanted.',
    ];

    public function __construct(
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly Json $json
    ) {
    }

    public function supersedePendingProposals(int $conversationId, int $adminUserId): void
    {
        $messages = $this->conversationRepository->getMessages($conversationId);
        $newestMessageId = $messages === [] ? 0 : (int)($messages[array_key_last($messages)]['entity_id'] ?? 0);
        foreach ($this->pendingProposals($messages) as $proposal) {
            $this->retire($conversationId, $adminUserId, $proposal, (int)$proposal['entity_id'] === $newestMessageId);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function pendingProposals(array $messages): array
    {
        return array_filter(
            $messages,
            static fn (array $message): bool => ($message['role'] ?? '') === 'assistant'
                && (int)($message['pending_confirmation'] ?? 0) === 1
        );
    }

    /**
     * @param array<string, mixed> $proposal
     */
    private function retire(int $conversationId, int $adminUserId, array $proposal, bool $isNewestMessage): void
    {
        $proposalId = (int)$proposal['entity_id'];
        if (!$this->conversationRepository->claimPendingConfirmation($proposalId, $adminUserId)) {
            return;
        }

        $toolCalls = $this->toolCallsOf($proposal);
        $this->conversationRepository->resolveConfirmation(
            $proposalId,
            false,
            null,
            $toolCalls === [] ? null : $this->markedSuperseded($toolCalls)
        );
        if (!$isNewestMessage) {
            return;
        }
        foreach ($this->callIds($toolCalls) as $toolCallId) {
            $this->conversationRepository->addMessage(
                $conversationId,
                'tool',
                (string)$this->json->serialize(self::SKIPPED_RESULT),
                null,
                false,
                $toolCallId
            );
        }
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array<int, array<string, mixed>>
     */
    private function toolCallsOf(array $proposal): array
    {
        $toolCalls = $proposal['tool_calls'] ?? [];
        if (is_string($toolCalls)) {
            try {
                $toolCalls = $this->json->unserialize($toolCalls);
            } catch (\InvalidArgumentException $e) {
                return [];
            }
        }

        return is_array($toolCalls) ? array_values(array_filter($toolCalls, 'is_array')) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $toolCalls
     * @return array<int, array<string, mixed>>
     */
    private function markedSuperseded(array $toolCalls): array
    {
        return array_map(
            static fn (array $toolCall): array => ['status' => self::SUPERSEDED_STATUS] + $toolCall,
            $toolCalls
        );
    }

    /**
     * @param array<int, array<string, mixed>> $toolCalls
     * @return string[]
     */
    private function callIds(array $toolCalls): array
    {
        return array_values(array_filter(array_map(
            static fn (array $toolCall): string => (string)($toolCall['id'] ?? ''),
            $toolCalls
        ), static fn (string $id): bool => $id !== ''));
    }
}
