<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Module\Manager;

/**
 * A store where only the modules named through withEnabledModule() are enabled.
 */
class FakeModuleManager extends Manager
{
    /** @var array<string, true> */
    private array $enabled = [];

    public function __construct()
    {
    }

    public function withEnabledModule(string $moduleName): self
    {
        $this->enabled[$moduleName] = true;

        return $this;
    }

    public function isEnabled($moduleName)
    {
        return isset($this->enabled[$moduleName]);
    }
}
