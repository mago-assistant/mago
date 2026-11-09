<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Data;

/**
 * One column of an exported list: the row key it reads, the header the admin sees, and whether
 * its cells are numbers, so a spreadsheet format can store them as numbers instead of text.
 * @api
 */
final readonly class ExportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $isNumeric = false
    ) {
    }
}
