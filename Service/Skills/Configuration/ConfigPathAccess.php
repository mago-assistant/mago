<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Configuration;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\TypePool;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\ObjectManager\ConfigInterface as ObjectManagerConfig;
use Magento\Theme\Model\Design\Config\MetadataProviderInterface;

/**
 * What config_reader and config_writer share: the Magento ACL resource that gates a configuration
 * path, and the paths that stay off limits whatever the admin holds.
 *
 * A path is gated by the resource its section declares in system.xml - Magento_Payment::payment
 * for payment methods, Magento_Config::config_admin for the admin URL and session settings,
 * Magento_Config::web for whether the admin panel enforces HTTPS - resolved the way the admin
 * configuration save controller resolves it: a field's <config_path> can place its value in a
 * different section than the one that declares the field, so the config path is first mapped back
 * to the sections declaring it. The broad Magento_Config::config only says the admin may open the
 * configuration area at all, which any admin holding one section does, so it is not a gate on its
 * own and is only answered when no path is named. A section that declares no resource, or a path
 * outside any section, resolves to '' and is refused (#200).
 *
 * The blocklist is defense in depth for credential-shaped paths: holding a section is no reason to
 * pass its API keys and secrets through chat. A path is blocked when its name looks like a credential,
 * when the field that stores it is a password field or saves encrypted, and for Mago's own settings,
 * which steer the assistant itself. Everything Magento marks sensitive (contact addresses, carrier
 * accounts, SMTP host: what app:config:dump keeps out of config.php) is readable, but its value only
 * reaches the model as a vault token (#106).
 */
final class ConfigPathAccess
{
    public const AREA_RESOURCE = 'Magento_Config::config';

    private const BLOCKED_PATTERNS = [
        '*key*', '*secret*', '*password*', '*token*', '*credential*',
        'payment/*', '*api_key*', '*private*', '*encrypt*',
    ];

    private const BLOCKED_WORDS = [
        'key', 'secret', 'password', 'pwd', 'passwd', 'token', 'credential', 'private', 'encrypt', 'username',
    ];

    private const BLOCKED_PREFIXES = ['payment/', 'mago/'];

    private const SECRET_FIELD_TYPES = ['obscure', 'password'];

    public function __construct(
        private readonly Structure $structure,
        private readonly AuthorizationInterface $authorization,
        private readonly MetadataProviderInterface $designConfig,
        private readonly TypePool $typePool,
        private readonly ObjectManagerConfig $objectManagerConfig
    ) {
    }

    /**
     * Whether the admin holds the resource of the section this path belongs to.
     *
     * ToolAccess answers the same question before the call, but on the input as the model sent
     * it; execute() receives it with privacy tokens rehydrated, so a path that only becomes itself
     * there is checked again on its final value (#222).
     *
     * @param string $path
     * @return bool
     */
    public function isAllowed(string $path): bool
    {
        $resource = $this->aclResourceFor($path);

        return $resource !== '' && $this->authorization->isAllowed($resource);
    }

    /**
     * Whether an admin screen stores this path, the test the configuration save applies before
     * writing (Save::filterNodes): a path no field declares is a row no admin screen shows or can
     * change back. Fields count by the path they store under (their config_path when they have
     * one), a group that clones its fields accepts any field name, and the design section's fields
     * live in Content > Design > Configuration, not in system.xml.
     *
     * @param string $path
     * @return bool
     */
    public function isDeclared(string $path): bool
    {
        if (isset($this->structure->getFieldPaths()[$path])) {
            return true;
        }
        foreach ($this->designConfig->get() as $field) {
            if (($field['path'] ?? null) === $path) {
                return true;
            }
        }

        return $this->isInCloningGroup($path);
    }

    /**
     * @param string $path
     * @return bool
     */
    private function isInCloningGroup(string $path): bool
    {
        $segments = explode('/', $path);
        for ($depth = 2; $depth < count($segments); $depth++) {
            $group = $this->structure->getElement(implode('/', array_slice($segments, 0, $depth)));
            if (!empty($group?->getData()['clone_fields'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The path as both config tools check and use it: surrounding whitespace and slashes dropped.
     * Case is kept, since the row is stored as named; a section id in the wrong case matches no
     * section and is refused.
     *
     * @param mixed $path
     * @return string
     */
    public function normalise(mixed $path): string
    {
        return is_string($path) ? trim($path, " \t\n\r\0\x0B/") : '';
    }

    /**
     * The resource an admin needs for the section a path belongs to.
     *
     * '' when no section the path can be edited in declares one, and the configuration area's own
     * resource when no path is named.
     *
     * @param string $path A config path: "web/secure/use_in_adminhtml"
     * @return string
     */
    public function aclResourceFor(string $path): string
    {
        if ($path === '') {
            return self::AREA_RESOURCE;
        }

        foreach ($this->sectionsEditing($path) as $sectionId) {
            $resource = (string)($this->structure->getElement($sectionId)?->getData()['resource'] ?? '');
            if ($resource !== '') {
                return $resource;
            }
        }

        return '';
    }

    /**
     * The sections a config path is edited in: every section declaring a field that stores under
     * it (a field's <config_path> can point into another section, and PayPal declares its fields
     * once per country in sections extending "payment", not all of which carry the resource), then
     * the path's own section.
     *
     * @param string $configPath
     * @return string[]
     */
    private function sectionsEditing(string $configPath): array
    {
        return array_unique(array_map(
            static fn (string $structurePath): string => explode('/', $structurePath)[0],
            [...($this->structure->getFieldPaths()[$configPath] ?? []), $configPath]
        ));
    }

    /**
     * @param string $path
     * @return bool
     */
    public function isBlocked(string $path): bool
    {
        $pathLower = strtolower($path);
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            $regex = '/^' . str_replace(['*', '/'], ['.*', '\/'], $pattern) . '$/';
            if (preg_match($regex, $pathLower)) {
                return true;
            }
        }
        foreach (explode('/', $pathLower) as $segment) {
            foreach (self::BLOCKED_WORDS as $word) {
                if (str_contains($segment, $word)) {
                    return true;
                }
            }
        }

        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if ($pathLower === rtrim($prefix, '/') || str_starts_with($pathLower, $prefix)) {
                return true;
            }
        }

        return $this->isStoredAsSecret($path);
    }

    /**
     * Whether Magento marks the path sensitive: readable by the admin, but not for the model to see.
     *
     * @param string $path
     * @return bool
     */
    public function isSensitive(string $path): bool
    {
        return $this->typePool->isPresent($path, TypePool::TYPE_SENSITIVE);
    }

    /**
     * Magento's Encrypted backend, its subclasses and virtual types of either, and a module's own
     * encrypting backend, which need not extend it (MageOS AiBase's EncryptedServices keeps the AI
     * provider keys). A backend model that resolves to no class counts as encrypting: what it would
     * do with the value cannot be told.
     *
     * @param string $backendModel
     * @return bool
     */
    private function isEncrypting(string $backendModel): bool
    {
        $backendModel = ltrim($backendModel, '\\');
        if ($backendModel === '') {
            return false;
        }
        $backendClass = ltrim($this->objectManagerConfig->getInstanceType($backendModel), '\\');

        return !class_exists($backendClass)
            || is_a($backendClass, Encrypted::class, true)
            || str_contains(strtolower($backendModel . ' ' . $backendClass), 'encrypt');
    }

    /**
     * Whether a field storing this path is a password field or saves its value encrypted, whatever
     * the path is called: a third-party module names its secret as it likes.
     *
     * @param string $path
     * @return bool
     */
    private function isStoredAsSecret(string $path): bool
    {
        foreach ($this->structure->getFieldPaths()[$path] ?? [] as $structurePath) {
            $field = $this->structure->getElement($structurePath)?->getData() ?? [];
            $backendModel = (string)($field['backend_model'] ?? '');
            if (in_array($field['type'] ?? '', self::SECRET_FIELD_TYPES, true) || $this->isEncrypting($backendModel)) {
                return true;
            }
        }

        return false;
    }
}
