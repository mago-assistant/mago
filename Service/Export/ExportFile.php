<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Export;

/**
 * A finished download: what to call it, what it is and what is in it.
 */
final readonly class ExportFile
{
    public function __construct(
        public string $fileName,
        public string $contentType,
        public string $contents
    ) {
    }
}
