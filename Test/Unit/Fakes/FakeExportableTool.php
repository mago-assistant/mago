<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Data\ExportColumn;
use MagoAssistant\Mago\Api\Tool\ExportableToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * A flat tool that implements the export interface itself, as another module's tool can, and calls
 * every action exportable. It records every export so a test can see what ran and with what.
 */
final class FakeExportableTool implements ExportableToolInterface
{
    /** @var list<array<array-key, mixed>> */
    public array $exports = [];

    /**
     * @param string[] $readActions
     * @param list<array<string, scalar|null>> $rows
     */
    public function __construct(
        private readonly string $name,
        private readonly array $readActions,
        private readonly array $rows = [],
        private readonly string $magentoAcl = 'Magento_Backend::admin'
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->name . ' description';
    }

    public function getParameterSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $params): array
    {
        return ['rows' => $this->rows];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return in_array($input['action'] ?? '', $this->readActions, true);
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return $this->magentoAcl;
    }

    public function isExportableAction(array $input): bool
    {
        return true;
    }

    public function getExportTitle(array $input): string
    {
        return 'Full ' . $this->name . ' list';
    }

    public function countExportRows(array $input): int
    {
        return count($this->rows);
    }

    public function getExportColumns(array $input): array
    {
        return [new ExportColumn('sku', 'SKU')];
    }

    public function exportRows(array $input, int $maxRows): iterable
    {
        $this->exports[] = $input;

        return array_slice($this->rows, 0, $maxRows);
    }
}
