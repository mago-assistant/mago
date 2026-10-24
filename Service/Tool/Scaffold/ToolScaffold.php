<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool\Scaffold;

use MagoAssistant\Mago\Service\Tool\Verify\ToolVerifier;

/**
 * The validated answers mago:tool:create needs. The ACL and the access mode have no defaults on
 * purpose: a tool that silently came out read-only and unguarded would fail open.
 */
final readonly class ToolScaffold
{
    private const MODULE_PATTERN = '/^([A-Z][A-Za-z0-9]*)_([A-Z][A-Za-z0-9]*)$/';
    private const ACL_PATTERN = '/^[A-Za-z0-9_]+::[A-Za-z0-9_]+$/';
    private const CLASS_PATTERN = '/^[A-Z][A-Za-z0-9]*$/';
    private const PACKAGE_PATTERN = '/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/';

    private function __construct(
        public string $toolName,
        public string $vendor,
        public string $module,
        public string $className,
        public string $packageName,
        public string $acl,
        public ToolAccess $access
    ) {
    }

    /**
     * @throws InvalidScaffoldException
     */
    public static function fromInput(
        string $toolName,
        string $moduleName,
        string $acl,
        string $access,
        string $className = '',
        string $packageName = ''
    ): self {
        if (preg_match(ToolVerifier::NAME_PATTERN, $toolName) !== 1) {
            throw new InvalidScaffoldException(
                'Tool name "' . $toolName . '" must be snake_case, start with a letter and be at most 64 characters.'
            );
        }

        if (preg_match(self::MODULE_PATTERN, $moduleName, $moduleParts) !== 1) {
            throw new InvalidScaffoldException('Module "' . $moduleName . '" must look like Vendor_Module.');
        }

        if ($acl === '') {
            throw new InvalidScaffoldException(
                'An ACL resource is required: the one an admin needs for the same action in the backend.'
            );
        }

        if (preg_match(self::ACL_PATTERN, $acl) !== 1) {
            throw new InvalidScaffoldException(
                'ACL resource "' . $acl . '" must look like Magento_Sales::actions_view.'
            );
        }

        if ($access === '') {
            throw new InvalidScaffoldException('Access is required: "read" or "write".');
        }

        $toolAccess = ToolAccess::tryFrom($access)
            ?? throw new InvalidScaffoldException('Access must be "read" or "write", not "' . $access . '".');

        [, $vendor, $module] = $moduleParts;
        $resolvedClass = $className !== '' ? $className : self::deriveClassName($toolName, $module);
        if (preg_match(self::CLASS_PATTERN, $resolvedClass) !== 1) {
            throw new InvalidScaffoldException('Class name "' . $resolvedClass . '" must be PascalCase.');
        }

        $resolvedPackage = $packageName !== ''
            ? $packageName
            : self::toKebabCase($vendor) . '/magento2-' . self::toKebabCase($module);
        if (preg_match(self::PACKAGE_PATTERN, $resolvedPackage) !== 1) {
            throw new InvalidScaffoldException(
                'Composer package "' . $resolvedPackage . '" must look like vendor/magento2-name.'
            );
        }

        return new self($toolName, $vendor, $module, $resolvedClass, $resolvedPackage, $acl, $toolAccess);
    }

    public function isReadOnly(): bool
    {
        return $this->access === ToolAccess::Read;
    }

    /**
     * Whether the ACL resource belongs to the new module, which then has to declare it itself.
     */
    public function hasOwnAclResource(): bool
    {
        return str_starts_with($this->acl, $this->vendor . '_' . $this->module . '::');
    }

    /**
     * vies_vat_check in module Vies becomes VatCheck: the module prefix the tool name carries for
     * registry uniqueness is noise inside the module's own namespace.
     */
    private static function deriveClassName(string $toolName, string $module): string
    {
        $pascal = str_replace('_', '', ucwords($toolName, '_'));
        $withoutModule = substr($pascal, strlen($module));

        return str_starts_with($pascal, $module) && $withoutModule !== '' ? $withoutModule : $pascal;
    }

    private static function toKebabCase(string $pascal): string
    {
        return strtolower((string)preg_replace('/(?<=[a-z0-9])[A-Z]/', '-$0', $pascal));
    }
}
