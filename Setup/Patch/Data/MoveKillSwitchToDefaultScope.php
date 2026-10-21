<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use MagoAssistant\Mago\Api\Config\RepositoryInterface;

/**
 * The Enabled switch is read at default scope only now, so a website or store view value would be
 * ignored and hidden from the admin. Failing closed: if the assistant was switched off anywhere,
 * it becomes off at default scope, so nobody who switched it off for one website finds it running
 * there again after the upgrade. The website and store view rows are then removed, since nothing
 * reads them any more.
 */
class MoveKillSwitchToDefaultScope implements DataPatchInterface
{
    private const SWITCHED_OFF = '0';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');
        $scopedValues = $connection->fetchCol(
            $connection->select()
                ->from($table, ['value'])
                ->where('path = ?', RepositoryInterface::XML_PATH_EXTENSION_ENABLE)
                ->where('scope <> ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
        );
        if ($scopedValues === []) {
            return $this;
        }

        $connection->beginTransaction();
        try {
            if (in_array(self::SWITCHED_OFF, array_map('strval', $scopedValues), true)) {
                $connection->insertOnDuplicate($table, [
                    'scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                    'scope_id' => 0,
                    'path' => RepositoryInterface::XML_PATH_EXTENSION_ENABLE,
                    'value' => self::SWITCHED_OFF,
                ], ['value']);
            }
            $connection->delete($table, [
                'path = ?' => RepositoryInterface::XML_PATH_EXTENSION_ENABLE,
                'scope <> ?' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ]);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

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
