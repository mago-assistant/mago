<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Plugin\Webapi;

use MagoAssistant\Mago\Api\WebApi\ChatManagementInterface;

/**
 * A subject for the before plugins, which only need its type
 */
final class NullChatManagement implements ChatManagementInterface
{
    public function sendMessage(string $message, ?int $conversationId = null): string
    {
        return '';
    }

    public function getConversations(): string
    {
        return '';
    }

    public function getConversation(int $conversationId): string
    {
        return '';
    }

    public function deleteConversation(int $conversationId): string
    {
        return '';
    }

    public function confirmAction(int $messageId): string
    {
        return '';
    }

    public function rejectAction(int $messageId): string
    {
        return '';
    }
}
