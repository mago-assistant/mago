<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Ai;

use Magento\Framework\Exception\LocalizedException;

/**
 * No usable AI service could be resolved from the configuration. The message is a setup
 * instruction from the client factory (which service, where to configure it, which package to
 * install) and is built before any request leaves the shop, so it is safe to show the admin.
 */
class AiNotConfiguredException extends LocalizedException
{
}
