<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MagoAssistant\Mago\Service\Flag\FlagRepository;

class FlagStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => FlagRepository::STATUS_OPEN, 'label' => __('Open')],
            ['value' => FlagRepository::STATUS_RESOLVED, 'label' => __('Resolved')],
        ];
    }
}
