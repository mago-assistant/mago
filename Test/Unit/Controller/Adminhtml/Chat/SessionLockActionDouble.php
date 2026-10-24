<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml\Chat;

use Magento\Framework\Session\SessionManagerInterface;
use MagoAssistant\Mago\Controller\Adminhtml\Chat\ReleasesSessionLock;

/**
 * Stands in for a chat controller: the same $_session property Backend\App\Action has, and the
 * trait that releases it before the stream starts.
 */
final class SessionLockActionDouble
{
    use ReleasesSessionLock;

    public function __construct(protected SessionManagerInterface $_session)
    {
    }

    public function startStream(): void
    {
        $this->releaseSessionLock();
    }
}
