<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\Framework\Exception\AuthorizationException;

final class AccessDeniedException extends AuthorizationException
{
}
