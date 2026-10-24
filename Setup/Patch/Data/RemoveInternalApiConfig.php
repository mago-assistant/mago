<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Tools no longer call the REST API over HTTP, so the internal URL and its TLS switch are gone from
 * the configuration screen. Their saved values would only linger unseen in core_config_data.
 */
class RemoveInternalApiConfig implements DataPatchInterface
{
    public const REMOVED_PATHS = [
        'mago/api/internal_url',
        'mago/api/internal_ssl_verify',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->delete(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['path IN (?)' => self::REMOVED_PATHS]
        );

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
