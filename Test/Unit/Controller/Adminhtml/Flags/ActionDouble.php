<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml\Flags;

use Magento\Framework\AuthorizationInterface;

/**
 * Stands in for Magento\Backend\App\Action: the same $_authorization property and the same
 * _isAllowed() on ADMIN_RESOURCE, without the backend context a real action needs.
 */
abstract class ActionDouble
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::flags';

    public function __construct(protected AuthorizationInterface $_authorization)
    {
    }

    public function isAllowed(): bool
    {
        return $this->_isAllowed();
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(static::ADMIN_RESOURCE);
    }
}
