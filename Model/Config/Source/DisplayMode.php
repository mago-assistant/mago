<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MagoAssistant\Mago\Api\Config\RepositoryInterface;

/**
 * How admins open the chat: the header button, the chat bar at the bottom of the page, or both
 */
class DisplayMode implements OptionSourceInterface
{
    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => RepositoryInterface::DISPLAY_MODE_BOTH, 'label' => __('Button and Chat Bar')],
            ['value' => RepositoryInterface::DISPLAY_MODE_BUTTON, 'label' => __('Button')],
            ['value' => RepositoryInterface::DISPLAY_MODE_CHAT_BAR, 'label' => __('Chat Bar')],
        ];
    }
}
