<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool\Verify;

use Magento\Framework\Acl\AclResource\ProviderInterface;

/**
 * Whether an ACL resource id is declared anywhere in the merged acl.xml tree. A tool returning a
 * resource that does not exist is denied for every admin but the ones with full access.
 */
class AclResourceIndex
{
    /** @var array<string,true>|null */
    private ?array $ids = null;

    public function __construct(
        private readonly ProviderInterface $resourceProvider
    ) {
    }

    public function has(string $resourceId): bool
    {
        $this->ids ??= $this->collectIds((array)$this->resourceProvider->getAclResources());

        return isset($this->ids[$resourceId]);
    }

    /**
     * @param array<int,array{id?:string,children?:array<int,mixed>}> $resources
     * @return array<string,true>
     */
    private function collectIds(array $resources): array
    {
        return array_reduce(
            $resources,
            fn (array $ids, array $resource): array => $ids
                + (isset($resource['id']) ? [(string)$resource['id'] => true] : [])
                + $this->collectIds($resource['children'] ?? []),
            []
        );
    }
}
