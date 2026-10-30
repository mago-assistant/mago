<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Export;

/**
 * A tool call export the admin may not have or that cannot exist. The message is shown to the
 * admin as it is.
 */
class ExportRefusedException extends \RuntimeException
{
}
