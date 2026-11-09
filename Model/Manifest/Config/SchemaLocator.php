<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Manifest\Config;

use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;

class SchemaLocator implements SchemaLocatorInterface
{
    private readonly string $schema;

    public function __construct(ModuleDirReader $moduleDirReader)
    {
        $this->schema = $moduleDirReader->getModuleDir(Dir::MODULE_ETC_DIR, 'MagoAssistant_Mago') . '/mago_tools.xsd';
    }

    public function getSchema()
    {
        return $this->schema;
    }

    public function getPerFileSchema()
    {
        return $this->schema;
    }
}
