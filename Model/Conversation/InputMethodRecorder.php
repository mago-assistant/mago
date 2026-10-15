<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

use Magento\Framework\App\ResourceConnection;

/**
 * Stores whether a user message was spoken or typed, next to the message. Kept apart from
 * ConversationRepositoryInterface for the same reason as PageLocationRecorder: that interface is
 * @api, and this is a detail of voice input nobody else needs to implement.
 */
class InputMethodRecorder
{
    public const VOICE = 'voice';
    public const TYPED = 'typed';

    private const TABLE = 'mago_message';
    private const COLUMN = 'input_method';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Anything the panel sent other than "voice" counts as typed.
     */
    public static function fromRequest(mixed $value): string
    {
        return $value === self::VOICE ? self::VOICE : self::TYPED;
    }

    public function record(int $messageId, string $inputMethod): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName(self::TABLE),
            [self::COLUMN => $inputMethod === self::VOICE ? self::VOICE : self::TYPED],
            ['entity_id = ?' => $messageId]
        );
    }
}
