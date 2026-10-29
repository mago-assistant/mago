<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool;

use MagoAssistant\Mago\Api\Tool\ToolInterface;

/**
 * The category a tool is listed under on the Skills & Permissions page: the namespace segment
 * after Vendor\Module\Service\Skills, so MagoAssistant\Mago\Service\Skills\Sales\OrderManager is "Sales"
 */
final class ToolCategory
{
    private const CATEGORY_SEGMENT = 4;
    private const FALLBACK = 'General';

    public function of(ToolInterface $tool): string
    {
        return explode('\\', get_class($tool))[self::CATEGORY_SEGMENT] ?? self::FALLBACK;
    }

    /**
     * @param ToolInterface[] $tools
     * @return list<string> distinct categories, sorted
     */
    public function allOf(array $tools): array
    {
        $categories = array_values(array_unique(array_map($this->of(...), $tools)));
        sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

        return $categories;
    }
}
