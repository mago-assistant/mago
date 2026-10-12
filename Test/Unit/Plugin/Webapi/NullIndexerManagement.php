<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Plugin\Webapi;

use MagoAssistant\Mago\Api\WebApi\IndexerManagementInterface;

/**
 * A subject for the before plugins, which only need its type
 */
final class NullIndexerManagement implements IndexerManagementInterface
{
    public function reindexAll(): array
    {
        return [];
    }

    public function reindex(array $indexerIds): array
    {
        return [];
    }
}
