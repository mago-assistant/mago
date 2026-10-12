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
     * @param string[] $paths
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
        foreach ($this->paths as $path) {
            $fields[str_replace('/', '_', $path)] = ['path' => $path];
        }

        return $fields;
    }
}
