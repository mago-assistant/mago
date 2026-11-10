<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Manifest\Config;

use Magento\Framework\Config\Reader\Filesystem;

/**
 * Reads every module's etc/mago_tools.xml, validated against etc/mago_tools.xsd, into one tree.
 * Tools, actions, params and steps merge by name across modules, in module load order, the way
 * Magento merges its other configuration: a later module that declares a <tool> with a name an
 * earlier module used adds to and overrides it, module, acl and readonly included. Nothing rejects
 * that yet; a module should therefore prefix its tool names. The file name, converter and schema
 * locator come from di.xml.
 */
class Reader extends Filesystem
{
    public const FILE_NAME = 'mago_tools.xml';

    /**
     * @var array<string, string>
     */
    protected $_idAttributes = [
        '/config/tool' => 'name',
        '/config/tool/action' => 'name',
        '/config/tool/action/param' => 'name',
        '/config/tool/action/step' => 'name',
        '/config/tool/action/step/field' => 'name',
        '/config/tool/action/field' => 'name',
        '/config/tool/action/pick' => 'field',
        '/config/tool/action/link' => 'route',
    ];
}
