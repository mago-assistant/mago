<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Url;

/**
 * Stands in for an admin controller that declares its own ACL resource, the way roughly a third
 * of Magento's own do.
 */
class ControllerWithOwnResource
{
    public const ADMIN_RESOURCE = 'Magento_Example::own';
}
