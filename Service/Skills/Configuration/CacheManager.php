<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Configuration;

use Magento\Framework\App\Cache\Frontend\Pool as CacheFrontendPool;
use Magento\Framework\App\Cache\TypeListInterface;
use MagoAssistant\Mago\Api\Tool\ActionScopedToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class CacheManager implements ActionScopedToolInterface
{
    private const ACTION_DESCRIPTIONS = [
        'status' => 'list all cache types and their status',
        'flush' => 'flush all caches',
        'flush_type' => 'flush a specific cache type by id, e.g. "config", "full_page", "layout", "block_html"',
    ];

    public function __construct(
        private readonly TypeListInterface $cacheTypeList,
        private readonly CacheFrontendPool $cacheFrontendPool
    ) {
    }

    public function getName(): string
    {
        return 'cache_manager';
    }

    public function getDescription(): string
    {
        return $this->getDescriptionForActions(array_keys(self::ACTION_DESCRIPTIONS));
    }

    public function getDescriptionForActions(array $actionNames): string
    {
        $parts = [];
        foreach (self::ACTION_DESCRIPTIONS as $name => $description) {
            if (in_array($name, $actionNames, true)) {
                $parts[] = '"' . $name . '" (' . $description . ')';
            }
        }

        return 'Manage Magento caches. Actions: ' . implode(', ', $parts) . '.';
    }

    public function getParameterSchema(): array
    {
        return $this->getParameterSchemaForActions(array_keys(self::ACTION_DESCRIPTIONS));
    }

    public function getParameterSchemaForActions(array $actionNames): array
    {
        $properties = [
            'action' => [
                'type' => 'string',
                'description' => 'The action to perform',
                'enum' => array_values(array_intersect(array_keys(self::ACTION_DESCRIPTIONS), $actionNames)),
            ],
        ];
        if (in_array('flush_type', $actionNames, true)) {
            $properties['cache_type'] = [
                'type' => 'string',
                'description' => 'Cache type ID for flush_type action (e.g. "config", "full_page", "layout", "block_html", "collections", "reflection", "eav", "translate")',
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => ['action'],
        ];
    }

    public function execute(array $params): array
    {
        $action = $params['action'] ?? '';

        return match ($action) {
            'status' => $this->getStatus(),
            'flush' => $this->flushAll(),
            'flush_type' => $this->flushType($params['cache_type'] ?? ''),
            default => ['error' => 'Unknown action: ' . $action],
        };
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isReadOnlyAction(array $input): bool
    {
        // status only reads cache state; flush/flush_type mutate. Unknown actions fail closed to write.
        return ($input['action'] ?? '') === 'status';
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [
            'message' => [PiiClass::PUBLIC],
            'id' => [PiiClass::PUBLIC],
            'label' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'success' => [PiiClass::PUBLIC],
            'flushed' => [PiiClass::PUBLIC],
        ];
    }

    public function getMagentoAcl(array $input = []): string
    {
        // Mirrors the native Cache controllers: viewing the grid needs the parent
        // resource, FlushAll needs flush_cache_storage, MassRefresh needs
        // refresh_cache_type. Unknown actions fail closed to the flush resource.
        return match ($input['action'] ?? '') {
            'status' => 'Magento_Backend::cache',
            'flush_type' => 'Magento_Backend::refresh_cache_type',
            default => 'Magento_Backend::flush_cache_storage',
        };
    }

    private function getStatus(): array
    {
        $types = $this->cacheTypeList->getTypes();
        $result = [];
        foreach ($types as $type) {
            $result[] = [
                'id' => $type->getId(),
                'label' => (string)$type->getCacheType(),
                'status' => $type->getStatus() ? 'enabled' : 'disabled',
            ];
        }
        return ['cache_types' => $result];
    }

    private function flushAll(): array
    {
        $types = $this->cacheTypeList->getTypes();
        $flushed = [];
        foreach ($types as $type) {
            $this->cacheTypeList->cleanType($type->getId());
            $flushed[] = $type->getId();
        }
        foreach ($this->cacheFrontendPool as $frontend) {
            $frontend->getBackend()->clean();
        }
        return [
            'success' => true,
            'message' => 'All caches have been flushed',
            'flushed' => $flushed,
        ];
    }

    private function flushType(string $cacheType): array
    {
        if (!$cacheType) {
            return ['error' => 'cache_type parameter is required for flush_type action'];
        }

        $types = $this->cacheTypeList->getTypes();
        if (!isset($types[$cacheType])) {
            return ['error' => 'Unknown cache type: ' . $cacheType . '. Use "status" action to list available types.'];
        }

        $this->cacheTypeList->cleanType($cacheType);

        return [
            'success' => true,
            'message' => sprintf('Cache type "%s" has been flushed', $cacheType),
        ];
    }
}
