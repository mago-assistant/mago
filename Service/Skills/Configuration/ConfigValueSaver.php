<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Configuration;

use Magento\Config\Model\Config\Backend\File;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\ConfigFactory;
use Magento\Config\Model\PreparedValueFactory;
use Magento\Config\Model\ResourceModel\Config\Data as ConfigValueResource;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use UnexpectedValueException;

/**
 * Saves one configuration value the way bin/magento config:set does: through the field's backend
 * model, so its validation, encryption and after-save steps run as when the admin saves the
 * configuration screen, and never over a value app/etc/env.php, config.php or a CONFIG__ environment
 * variable locks, at the scope asked or at the default scope it falls back to, the same check that
 * greys the field out in the admin (#245).
 *
 * A field declared in system.xml is saved through the configuration model, as the admin save
 * controller does, so the admin_system_config_changed_section_* observers run too: they reindex,
 * flush caches or reschedule cron after a change. A design configuration path is not in system.xml,
 * which the configuration model needs, so it is saved through its backend model alone.
 *
 * The value factory, structure and configuration model are pinned to the adminhtml config structure
 * in etc/di.xml: only that structure knows the backend models, and the chat also runs in webapi_rest
 * and cron.
 */
class ConfigValueSaver
{
    public function __construct(
        private readonly PreparedValueFactory $preparedValueFactory,
        private readonly SettingChecker $settingChecker,
        private readonly StoreManagerInterface $storeManager,
        private readonly Structure $structure,
        private readonly ConfigFactory $configFactory,
        private readonly ConfigValueResource $configValueResource
    ) {
    }

    /**
     * @param string $path
     * @param string $value
     * @param string $scope "default", "websites" or "stores"
     * @param int $scopeId
     * @return void
     * @throws LocalizedException When the value is locked, the field takes an upload, or its backend
     *     model refuses the value
     */
    public function save(string $path, string $value, string $scope, int $scopeId): void
    {
        $scopeCode = $this->scopeCode($scope, $scopeId);
        if ($this->settingChecker->isReadOnly($path, $scope, $scopeCode)) {
            throw new LocalizedException(__(
                'This setting is locked in app/etc/env.php or app/etc/config.php, so it cannot be changed here.'
            ));
        }

        $backendModel = $this->preparedValueFactory->create($path, $value, $scope, $scopeCode);
        if (!$backendModel instanceof Value) {
            throw new LocalizedException(__('This setting cannot be saved here.'));
        }
        // A file field clears itself when no upload comes with the save.
        if ($backendModel instanceof File) {
            throw new LocalizedException(__(
                'This setting takes an uploaded file; change it under Stores > Configuration.'
            ));
        }

        $structurePath = $this->structure->getFieldPaths()[$path][0] ?? null;
        if ($structurePath === null) {
            $this->configValueResource->save($backendModel);

            return;
        }
        $this->saveSection($structurePath, $value, $scope, $scopeId);
    }

    /**
     * @param string $structurePath The field's path in system.xml, which its config_path can differ from
     * @param string $value
     * @param string $scope
     * @param int $scopeId
     * @return void
     * @throws LocalizedException
     */
    private function saveSection(string $structurePath, string $value, string $scope, int $scopeId): void
    {
        $config = $this->configFactory->create(['data' => ['scope' => $scope, 'scope_id' => $scopeId]]);
        try {
            $config->setDataByPath($structurePath, $value);
        } catch (UnexpectedValueException $e) {
            throw new LocalizedException(__('This setting cannot be saved here.'), $e);
        }
        $config->save();
    }

    /**
     * @param string $scope
     * @param int $scopeId
     * @return string|null
     */
    private function scopeCode(string $scope, int $scopeId): ?string
    {
        return match ($scope) {
            'websites' => (string)$this->storeManager->getWebsite($scopeId)->getCode(),
            'stores' => (string)$this->storeManager->getStore($scopeId)->getCode(),
            default => null,
        };
    }
}
