<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\WebApi;

/**
 * Chat management REST API interface
 * @api
 */
interface ChatManagementInterface
{
    /**
     * Send a chat message (non-streaming)
     *
     * @param string $message
     * @param int|null $conversationId
     * @return string JSON response
     */
    public function sendMessage(string $message, ?int $conversationId = null): string;

    /**
     * Get conversations for current admin user
     *
     * @return string JSON response
     */
    public function getConversations(): string;

    /**
     * Get a single conversation with messages
     *
     * @param int $conversationId
     * @return string JSON response
     */
    public function getConversation(int $conversationId): string;

    /**
     * Delete a conversation
     *
     * @param int $conversationId
     * @return string JSON response
     */
    public function deleteConversation(int $conversationId): string;

    /**
     * Confirm a pending write action
     *
     * A proposal whose tool calls carry "irreversible" or "caution" only runs with acknowledged
     * set to true, the API's counterpart of the checkbox on the admin panel's card.
     *
     * @param int $messageId
     * @param bool $acknowledged The caller read the impacts and cautions and accepts them
     * @return string JSON response
     */
    public function confirmAction(int $messageId, bool $acknowledged = false): string;

    /**
     * Reject a pending write action
     *
     * @param int $messageId
     * @return string JSON response
     */
    public function rejectAction(int $messageId): string;
}
