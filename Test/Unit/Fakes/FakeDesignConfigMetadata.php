<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Theme\Model\Design\Config\MetadataProviderInterface;

/**
 * The fields of Content > Design > Configuration, as Magento_Theme declares them in di.xml
 */
final class FakeDesignConfigMetadata implements MetadataProviderInterface
{
    /**
     * @param array<int|string, string> $paths config paths, optionally keyed by the field name
     *     Magento_Theme's di.xml registers ('head_includes' => 'design/head/includes'); an
     *     unkeyed path gets a name derived from it
     */
    public function __construct(
        private readonly array $paths = []
    ) {
    }

    /**
     * @return array<string, array{path: string}>
     */
    public function get(): array
    {
        $fields = [];
        foreach ($this->paths as $fieldName => $path) {
            $fields[is_string($fieldName) ? $fieldName : str_replace('/', '_', $path)] = ['path' => $path];
        }

        return $fields;
    }
}
