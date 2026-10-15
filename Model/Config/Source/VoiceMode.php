<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How a spoken message reaches the assistant. Dictate only fills the input. Send After a Pause and
 * Conversation keep listening across turns; the first sends each message after a countdown, the
 * second asks for a spoken or clicked "send" before each message goes out.
 */
class VoiceMode implements OptionSourceInterface
{
    public const DICTATE = 'dictate';
    public const AUTO_SEND = 'auto_send';
    public const CONVERSATION = 'conversation';

    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::DICTATE, 'label' => __('Dictate: fill the input, I press Send')],
            ['value' => self::AUTO_SEND, 'label' => __('Send after a pause')],
            ['value' => self::CONVERSATION, 'label' => __('Conversation: hands-free, confirm each message')],
        ];
    }
}
