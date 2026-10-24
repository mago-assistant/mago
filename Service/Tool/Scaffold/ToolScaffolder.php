<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool\Scaffold;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Renders the files of a new tool module from the templates next to this class. The templates end
 * in .tpl so neither setup:di:compile nor a PHP linter mistakes them for code of this module.
 */
class ToolScaffolder
{
    private const TEMPLATES = [
        'composer.json.tpl' => 'composer.json',
        'registration.php.tpl' => 'registration.php',
        'module.xml.tpl' => 'etc/module.xml',
        'di.xml.tpl' => 'etc/di.xml',
        'Tool.php.tpl' => 'Service/Tool/{{ClassName}}.php',
        'README.md.tpl' => 'README.md',
    ];

    private const ACL_TEMPLATE = ['acl.xml.tpl' => 'etc/acl.xml'];

    public function __construct(
        private readonly File $fileDriver
    ) {
    }

    /**
     * @return array<string,string> File contents keyed by path relative to the module directory
     * @throws FileSystemException
     */
    public function render(ToolScaffold $scaffold): array
    {
        $placeholders = $this->placeholders($scaffold);
        $templates = self::TEMPLATES + ($scaffold->hasOwnAclResource() ? self::ACL_TEMPLATE : []);

        return array_combine(
            array_map(static fn (string $path) => strtr($path, $placeholders), array_values($templates)),
            array_map(
                fn (string $template) => strtr(
                    $this->fileDriver->fileGetContents(__DIR__ . '/templates/' . $template),
                    $placeholders
                ),
                array_keys($templates)
            )
        );
    }

    /**
     * @return array<string,string>
     */
    private function placeholders(ToolScaffold $scaffold): array
    {
        return [
            '{{Vendor}}' => $scaffold->vendor,
            '{{Module}}' => $scaffold->module,
            '{{ClassName}}' => $scaffold->className,
            '{{tool_name}}' => $scaffold->toolName,
            '{{package}}' => $scaffold->packageName,
            '{{acl}}' => $scaffold->acl,
            '{{access}}' => $scaffold->access->value,
            '{{is_read_only}}' => $scaffold->isReadOnly() ? 'true' : 'false',
        ];
    }
}
