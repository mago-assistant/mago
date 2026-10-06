<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Conversation;

use MagoAssistant\Mago\Api\ConversationRepositoryInterface;
use MagoAssistant\Mago\Model\Conversation\ConfirmationAlreadyHandledException;
use MagoAssistant\Mago\Model\Conversation\ConfirmationExpiredException;
use MagoAssistant\Mago\Model\Conversation\ConversationNotFoundException;

/**
 * Takes a pending write for exactly one request before anything runs.
 *
 * Reading the flag and clearing it after the writes left a window in which a double submit, or
 * the panel and the REST API at once, both saw it set and both ran the same credit memo or
 * coupon. The flag is now cleared first in one conditional update, and only the request that
 * cleared it goes on. The admin panel and the WebApi share this so neither can skip it.
 */
class ConfirmationClaim
{
    /**
     * A proposal older than this is not run: the card the admin approves may no longer match the store
     */
    public const MAX_AGE_SECONDS = 3600;

    public function __construct(
        private readonly ConversationRepositoryInterface $conversationRepository
    ) {
    }

    /**
     * Claim a pending write to run it
     *
     * @return array<string, mixed> The message row that asked for confirmation
     * @throws ConversationNotFoundException when the message is missing or belongs to another admin
     * @throws ConfirmationAlreadyHandledException when another request confirmed or rejected it first
     * @throws ConfirmationExpiredException when it was proposed more than MAX_AGE_SECONDS ago
     */
    public function claimToConfirm(int $messageId, int $adminUserId): array
    {
        $message = $this->conversationRepository->getMessageForUser($messageId, $adminUserId);
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

    private function isStillPending(int $messageId, int $adminUserId): bool
    {
        return (bool)($this->conversationRepository->getMessageForUser($messageId, $adminUserId)['pending_confirmation'] ?? false);
    }
}
