<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Chat;

/**
 * Keeps the confirm SSE event back until the assistant row it belongs to is stored.
 *
 * ChatService mints the event before the row exists, so emitted straight away it cannot name its
 * message, and a card without a message id confirmed whatever proposal was pending last (#332).
 * sendHeldConfirm() releases the event with the id of its own row, and the panel keys the card's
 * Allow and Reject on exactly that.
 */
trait HoldsConfirmEvent
{
    /**
     * @param array<string, mixed>|null $heldConfirm
     */
    private function sendingSseHoldingConfirm(?array &$heldConfirm): callable
    {
        return function (string $type, array $data) use (&$heldConfirm): void {
            if ($type === 'confirm') {
                $heldConfirm = $data;
                return;
            }
            $this->sendSse($type, $data);
        };
    }

    /**
     * @param array<string, mixed>|null $heldConfirm
     */
    private function sendHeldConfirm(?array $heldConfirm, int $messageId, int $conversationId): void
    {
        if ($heldConfirm === null) {
            return;
        }

        $this->sendSse('confirm', [
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
        ] + $heldConfirm);
    }

    abstract private function sendSse(string $event, array $data, bool $pad = false): void;
}
