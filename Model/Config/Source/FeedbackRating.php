<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MagoAssistant\Mago\Service\Flag\FlagRepository;

class FeedbackRating implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => FlagRepository::RATING_UP, 'label' => __('Thumbs up')],
            ['value' => FlagRepository::RATING_DOWN, 'label' => __('Thumbs down')],
        ];
    }
}
