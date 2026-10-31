<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Data\ExportColumn;
use MagoAssistant\Mago\Api\Skill\ExportableActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * A list action with rows in memory. It hands out at most the rows asked for, as a real action
 * does, and records every export so a test can see the arguments it ran with.
 */
final class FakeExportableAction implements ExportableActionInterface
{
    /** @var list<array{params: array<array-key, mixed>, admin_user_id: int, max_rows: int}> */
    public array $exports = [];

    private bool $isCountBroken = false;

    private bool $isExportBroken = false;

    /**
     * @param list<array<string, scalar|null>> $rows
     * @param array<string, mixed> $parameterSchema
     */
    public function __construct(
        private readonly string $name,
        private readonly array $rows = [],
        private readonly bool $isReadOnly = true,
        private readonly ?string $aclResource = null,
        private readonly array $parameterSchema = ['query' => ['type' => 'string']]
    ) {
    }

    public function givenCountFails(): self
    {
        $this->isCountBroken = true;

        return $this;
    }

    public function givenExportFails(): self
    {
        $this->isExportBroken = true;

        return $this;
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
        return $this->parameterSchema;
    }

    public function getAclResource(): ?string
    {
        return $this->aclResource;
    }

    public function isReadOnly(): bool
    {
        return $this->isReadOnly;
    }

    public function getFieldClassification(): array
    {
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function execute(array $params, int $adminUserId): array
    {
        return ['rows' => array_slice($this->rows, 0, 2)];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getExportTitle(array $params): string
    {
        return 'Full ' . $this->name . ' list';
    }

    public function countExportRows(array $params, int $adminUserId): int
    {
        if ($this->isCountBroken) {
            throw new \RuntimeException('The count query timed out');
        }

        return count($this->rows);
    }

    public function getExportColumns(): array
    {
        return [new ExportColumn('sku', 'SKU'), new ExportColumn('qty', 'Quantity', true)];
    }

    public function exportRows(array $params, int $adminUserId, int $maxRows): iterable
    {
        $this->exports[] = ['params' => $params, 'admin_user_id' => $adminUserId, 'max_rows' => $maxRows];
        if ($this->isExportBroken) {
            throw new \RuntimeException("SQLSTATE[HY000]: General error in SELECT ... WHERE email = 'jane@example.com'");
        }

        yield from array_slice($this->rows, 0, $maxRows);
    }
}
