<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Export;

/**
 * The spreadsheet file could not be built, for example because the temporary file could not be
 * written.
 */
class SpreadsheetWriteException extends \RuntimeException
{
}
