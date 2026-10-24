<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Acl\AclResource\ProviderInterface;

/**
 * An acl.xml tree in the shape Magento\Framework\Acl\AclResource\Provider returns it.
 */
final class FakeAclResourceProvider implements ProviderInterface
{
    /**
     * @param array<int,array{id:string,children?:array<int,mixed>}> $resources
     */
    public function __construct(
        private readonly array $resources = []
    ) {
    }

    /**
     * Magento_Backend::admin with the given resource ids as its children.
     */
    public static function withResources(string ...$ids): self
    {
        return new self([[
            'id' => 'Magento_Backend::admin',
            'children' => array_map(static fn (string $id) => ['id' => $id, 'children' => []], $ids),
        ]]);
    }

    public function getAclResources(): array
    {
        return $this->resources;
    }
}
