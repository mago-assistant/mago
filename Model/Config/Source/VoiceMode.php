<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How a spoken message reaches the assistant. Dictate only fills the input. Send After a Pause
 * counts down after each pause and sends; Confirm Each Message waits for a spoken or clicked yes.
 * Both keep listening across turns.
 */
class VoiceMode implements OptionSourceInterface
{
    public const DICTATE = 'dictate';
    public const AUTO_SEND = 'auto_send';
    public const CONFIRM = 'confirm';
    public const ALL = [self::DICTATE, self::AUTO_SEND, self::CONFIRM];

    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::DICTATE, 'label' => __('Dictate: fill the input, I press Send')],
            ['value' => self::AUTO_SEND, 'label' => __('Send after a pause')],
            ['value' => self::CONFIRM, 'label' => __('Confirm each message')],
        ];
    }
}
