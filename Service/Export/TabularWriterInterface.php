<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Export;

use MagoAssistant\Mago\Api\Data\ExportColumn;

/**
 * Turns exported rows into the contents of one download format.
 *
 * Writers are listed by format in di.xml (the "writers" argument of ToolCallExport), so another
 * module can add a format there.
 */
interface TabularWriterInterface
{
    public function getContentType(): string;

    public function getFileExtension(): string;

    /**
     * @param list<ExportColumn> $columns
     * @param iterable<array<string, scalar|null>> $rows
     */
    public function write(array $columns, iterable $rows): string;
}
