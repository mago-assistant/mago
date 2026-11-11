<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Form;

use Magento\Theme\Model\Design\Config\MetadataProviderInterface;
use MagoAssistant\Mago\Service\Skills\Configuration\BlockedConfigPaths;

/**
 * The design configuration form (Content > Design > Configuration) is the one admin form whose
 * fields store the settings BlockedConfigPaths declares off limits for config_writer: the scripts
 * every storefront page loads (design/head/includes, design/footer/absolute_footer). Those fields
 * are plain visible textareas, so without this check page_form would offer the staging write that
 * config_writer refuses, however the request is worded or confirmed.
 *
 * The mapping from form field to config path comes from Magento's own design config metadata, the
 * same source ConfigPathAccess reads, so one policy covers both tools: a module that blocks a
 * design path of its own through BlockedConfigPaths' di.xml argument is covered here automatically.
 * Reading these fields stays allowed; only writing is refused, before a confirmation card is ever
 * shown.
 */
class BlockedDesignConfigFields
{
    private const DESIGN_CONFIG_NAMESPACE = 'design_config_form';

    public function __construct(
        private readonly MetadataProviderInterface $designConfigMetadata,
        private readonly BlockedConfigPaths $blockedConfigPaths
    ) {
    }

    public function findBlockedPath(string $formNamespace, string $fieldPath): ?string
    {
        if ($formNamespace !== self::DESIGN_CONFIG_NAMESPACE) {
            return null;
        }

        $configPath = $this->configPathFor($fieldPath);
        if ($configPath === null || !$this->blockedConfigPaths->isBlocked($configPath)) {
            return null;
        }

        return $configPath;
    }

    private function configPathFor(string $fieldPath): ?string
    {
        $path = $this->designConfigMetadata->get()[$this->fieldNameOf($fieldPath)]['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * The snapshot carries the component's runtime dataScope, which Magento's UI renderer composes
     * from the form's own scope: "data.head_includes" on the design configuration form (its
     * form-level dataScope is "data"). The design config metadata is keyed by the bare field name,
     * so the last segment is what the lookup needs; a bare path is its own last segment.
     */
    private function fieldNameOf(string $fieldPath): string
    {
        $segments = explode('.', $fieldPath);

        return $segments[array_key_last($segments)];
    }
}
