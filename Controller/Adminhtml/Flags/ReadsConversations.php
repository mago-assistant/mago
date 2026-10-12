<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Flags;

use MagoAssistant\Mago\Controller\Adminhtml\Conversations\View as ConversationsView;

/**
 * A flag is a copy of another admin's conversation, payloads included. Reading one therefore asks for
 * the same grant the Conversations screen does, on top of the Answer Feedback resource itself.
 */
trait ReadsConversations
{
    protected function _isAllowed(): bool
    {
        return parent::_isAllowed() && $this->_authorization->isAllowed(ConversationsView::ADMIN_RESOURCE);
    }
}
