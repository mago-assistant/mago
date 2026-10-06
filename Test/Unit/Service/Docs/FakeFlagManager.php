<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Docs;

use Magento\Framework\FlagManager;

/**
 * Keeps flags in memory instead of the flag table
 */
final class FakeFlagManager extends FlagManager
{
    /** @var array<string, mixed> */
    private array $flags = [];

    public function __construct()
    {
    }

    public function getFlagData($code)
    {
        return $this->flags[$code] ?? null;
    }

    public function saveFlag($code, $value)
    {
        $this->flags[$code] = $value;

        return true;
    }

    public function deleteFlag($code)
    {
        unset($this->flags[$code]);

        return true;
    }
}
