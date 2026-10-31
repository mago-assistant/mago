<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Ui\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MagoAssistant\Mago\Service\Tool\ToolCategory;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;

/**
 * Options for the Category filter on the skills grid: every category a registered tool falls under
 */
class SkillCategory implements OptionSourceInterface
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolCategory $toolCategory
    ) {
    }

    public function toOptionArray(): array
    {
        return array_map(
            static fn (string $category): array => ['value' => $category, 'label' => $category],
            $this->toolCategory->allOf($this->toolRegistry->getAllTools())
        );
    }
}
