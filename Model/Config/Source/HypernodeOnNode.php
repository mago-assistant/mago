<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MagoAssistant\Mago\Service\Hypernode\Config;

class HypernodeOnNode implements OptionSourceInterface
{
    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::ON_NODE_AUTO, 'label' => __('Auto-detect')],
            ['value' => Config::ON_NODE_YES, 'label' => __('Yes')],
            ['value' => Config::ON_NODE_NO, 'label' => __('No')],
        ];
    }
}
