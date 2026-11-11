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
     * @param array<int|string, string> $paths config paths, keyed by the form field name the way
     *        Magento_Theme's di.xml declares them ('head_includes' => 'design/head/includes'); a
     *        bare path gets a name derived from it.
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
        foreach ($this->paths as $name => $path) {
            $fields[is_string($name) ? $name : str_replace('/', '_', $path)] = ['path' => $path];
        }

        return $fields;
    }
}
