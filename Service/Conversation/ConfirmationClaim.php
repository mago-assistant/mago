<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Conversation;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\ConversationRepositoryInterface;
use MagoAssistant\Mago\Model\Conversation\ConfirmationAlreadyHandledException;
use MagoAssistant\Mago\Model\Conversation\ConfirmationExpiredException;
use MagoAssistant\Mago\Model\Conversation\ConfirmationNotAcknowledgedException;
use MagoAssistant\Mago\Model\Conversation\ConfirmRequest;
use MagoAssistant\Mago\Model\Conversation\ConversationNotFoundException;

/**
 * Takes a pending write for exactly one request before anything runs.
 *
 * Reading the flag and clearing it after the writes left a window in which a double submit, or
 * the panel and the REST API at once, both saw it set and both ran the same credit memo or
 * coupon. The flag is now cleared first in one conditional update, and only the request that
 * cleared it goes on. The admin panel and the WebApi share this so neither can skip it.
 *
 * A write the card asks to acknowledge (no undo, or a caution) is only claimed when the request
 * says the admin ticked that box. The checkbox alone lived in the browser, so a crafted request
 * or a REST call ran the write without it. The flags are read off the stored proposal, the same
 * copy the card was drawn from: worked out again here they could differ from what the card
 * showed (a caution that reads the store, a row stored before the flags existed) and leave a card
 * without a checkbox that the server never accepts.
 */
class ConfirmationClaim
{
    /**
     * A proposal older than this is not run: the card the admin approves may no longer match the store
     */
    public const MAX_AGE_SECONDS = 3600;

    public function __construct(
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly Json $json
    ) {
    }

    /**
     * Claim a pending write to run it
     *
     * @param ConfirmRequest $request The proposal, its acknowledgement and the ticked calls, as sent
     * @param int $adminUserId
     * @return array<string, mixed> The message row that asked for confirmation
     * @throws ConversationNotFoundException when the message is missing or belongs to another admin
     * @throws ConfirmationNotAcknowledgedException when a write that needs the acknowledgement lacks it
     * @throws ConfirmationAlreadyHandledException when another request confirmed or rejected it first
     * @throws ConfirmationExpiredException when it was proposed more than MAX_AGE_SECONDS ago
     */
    public function claimToConfirm(ConfirmRequest $request, int $adminUserId): array
    {
        $messageId = $request->messageId;
        $message = $this->conversationRepository->getMessageForUser($messageId, $adminUserId);
        // Checked before the claim, so a refused request leaves the card pending for a ticked retry.
        // A proposal past its hour cannot be retried at all, so that refusal says so instead.
        if (!$request->acknowledged && $this->needsAcknowledgement($message, $request->selectedIds)) {
            throw $this->isExpired($messageId, $adminUserId)
                ? new ConfirmationExpiredException()
                : new ConfirmationNotAcknowledgedException();
        }
        if ($this->conversationRepository->claimPendingConfirmation($messageId, $adminUserId, self::MAX_AGE_SECONDS)) {
            return $message;
        }
        if ($this->isStillPending($messageId, $adminUserId)) {
            throw new ConfirmationExpiredException();
        }

        throw new ConfirmationAlreadyHandledException();
    }

    /**
     * Claim a pending write to decline it. Declining runs nothing, so an expired proposal can
     * still be declined, which is how the admin clears it.
     *
     * @return array<string, mixed> The message row that asked for confirmation
     * @throws ConversationNotFoundException when the message is missing or belongs to another admin
     * @throws ConfirmationAlreadyHandledException when another request confirmed or rejected it first
     */
    public function claimToReject(int $messageId, int $adminUserId): array
    {
        $message = $this->conversationRepository->getMessageForUser($messageId, $adminUserId);
        if ($this->conversationRepository->claimPendingConfirmation($messageId, $adminUserId)) {
            return $message;
        }

        throw new ConfirmationAlreadyHandledException();
    }

    /**
     * @param array<string, mixed> $message
     * @param string[]|null $selectedIds
     */
    private function needsAcknowledgement(array $message, ?array $selectedIds): bool
    {
        // A handled proposal runs nothing either way; the claim below reports why
        if (empty($message['pending_confirmation'])) {
            return false;
        }
        $toolCalls = $message['tool_calls'] ?? [];
        if (is_string($toolCalls)) {
            $toolCalls = $this->json->unserialize($toolCalls);
        }

        return array_filter(
            is_array($toolCalls) ? $toolCalls : [],
            static fn (array $toolCall): bool => ($selectedIds === null
                    || in_array((string)($toolCall['id'] ?? ''), $selectedIds, true))
                && (!empty($toolCall['irreversible']) || !empty($toolCall['caution']))
        ) !== [];
    }

    private function isExpired(int $messageId, int $adminUserId): bool
    {
        return $this->conversationRepository->isPendingConfirmationExpired(
            $messageId,
            $adminUserId,
            self::MAX_AGE_SECONDS
        );
    }

    private function isStillPending(int $messageId, int $adminUserId): bool
    {
        return (bool)($this->conversationRepository->getMessageForUser($messageId, $adminUserId)['pending_confirmation'] ?? false);
    }
}
