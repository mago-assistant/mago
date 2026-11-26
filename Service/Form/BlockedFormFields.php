<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Form;

use Magento\Theme\Model\Design\Config\MetadataProviderInterface;
use MagoAssistant\Mago\Service\Skills\Configuration\BlockedConfigPaths;

/**
 * The form fields that store a setting BlockedConfigPaths keeps off limits, so page_form refuses
 * the writes config_writer already refuses (security audit follow-up 2026-11-02, finding 3).
 *
 * Content > Design > Configuration is the one admin screen that edits config values through a UI
 * component form instead of Stores > Configuration. Without this, write_fields staged
 * design/head/includes and design/footer/absolute_footer - the scripts and HTML every storefront
 * page loads - on design_config_form after BlockedConfigPaths said config_writer must never touch
 * them. The field-to-config-path mapping is Magento's own (MetadataProviderInterface, the same
 * source ConfigPathAccess::isDeclared() reads) and the policy stays BlockedConfigPaths itself, so
 * a pattern a module adds through di.xml's additionalPatterns blocks the matching form field the
 * same instant it blocks the config path; there is only ever the one list.
 *
 * WriteFieldsAction enforces this on the server, before the confirmation card and again on the
 * confirm request; form-bridge.js enforces the same list in the browser before staging a
 * directive's change (published through Block\Adminhtml\ChatPanel::getJsConfig(), the way
 * FormPolicy's deny lists already are). describe_form and read_fields still show these fields,
 * the same way config_reader still reads the paths: this is a write policy.
 */
class BlockedFormFields
{
    /**
     * The namespace Content > Design > Configuration registers under, and the dataScope its
     * fields are described with (design_config_form.xml declares <dataScope>data</dataScope>, so
     * describe_form reports the head_includes field as "data.head_includes").
     */
    private const DESIGN_CONFIG_FORM_NAMESPACE = 'design_config_form';
    private const DESIGN_CONFIG_FIELD_PREFIX = 'data.';

    public function __construct(
        private readonly BlockedConfigPaths $blockedConfigPaths,
        private readonly MetadataProviderInterface $designConfigMetadata
    ) {
    }

    public function isBlocked(string $namespace, string $fieldPath): bool
    {
        return in_array($fieldPath, $this->getBlockedFieldPaths()[$namespace] ?? [], true);
    }

    /**
     * Every write-blocked field path per form namespace, the shape form-bridge.js reads from the
     * js config. Metadata entries are keyed by field name (head_includes), each carrying the
     * config path it stores under (design/head/includes), as Magento_Theme's di.xml declares them.
     *
     * @return array<string, array<int, string>>
     */
    public function getBlockedFieldPaths(): array
    {
        $fieldPaths = array_values(array_map(
            static fn (string|int $fieldName): string => self::DESIGN_CONFIG_FIELD_PREFIX . $fieldName,
            array_keys(array_filter(
                $this->designConfigMetadata->get(),
                fn (mixed $metadata): bool => is_array($metadata)
                    && $this->blockedConfigPaths->isBlocked((string)($metadata['path'] ?? ''))
            ))
        ));

        return $fieldPaths === [] ? [] : [self::DESIGN_CONFIG_FORM_NAMESPACE => $fieldPaths];
    }
}
