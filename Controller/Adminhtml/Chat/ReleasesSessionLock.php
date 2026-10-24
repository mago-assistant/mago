<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Chat;

/**
 * Lets a streaming chat controller give up the session lock before its tool loop starts.
 *
 * File and Redis session handlers lock the session for the whole request, so a turn that runs for
 * minutes would block every other admin tab of the same user until it ends. Everything a chat
 * request needs from the session (form key, admin user, ACL) is read before execute() runs and
 * stays readable in memory after the close; nothing in the turn writes to the session.
 *
 * The response headers go out first: Magento only (re)starts a session while no headers have been
 * sent, so a session object created later in the turn cannot take the lock back.
 *
 * Using classes must extend Backend\App\Action, whose $_session is the shared admin session.
 */
trait ReleasesSessionLock
{
    private function releaseSessionLock(): void
    {
        echo ": \n\n";
        flush();
        $this->_session->writeClose();
    }
}
