<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

/**
 * What the admin panel's confirm request asks for: which proposal, whether the card's
 * acknowledgement was ticked, and which calls of a bulk card were ticked.
 */
final class ConfirmRequest
{
    private const KEY_MESSAGE_ID = 'message_id';
    private const KEY_ACKNOWLEDGED = 'acknowledged';
    private const KEY_TOOL_CALL_IDS = 'tool_call_ids';

    /**
     * @param int $messageId
     * @param bool $acknowledged
     * @param string[]|null $selectedIds Ticked tool call ids of a bulk card; null runs them all
     */
    public function __construct(
        public readonly int $messageId,
        public readonly bool $acknowledged,
        public readonly ?array $selectedIds
    ) {
    }

    /**
     * Only a literal true counts as ticked, so "1", "true" or a missing field never pass for it
     *
     * @param array<string, mixed> $postData
     */
    public static function fromPostData(array $postData): self
    {
        $selectedIds = $postData[self::KEY_TOOL_CALL_IDS] ?? null;

        return new self(
            (int)($postData[self::KEY_MESSAGE_ID] ?? 0),
            ($postData[self::KEY_ACKNOWLEDGED] ?? false) === true,
            is_array($selectedIds) ? array_values(array_map('strval', $selectedIds)) : null
        );
    }
}
