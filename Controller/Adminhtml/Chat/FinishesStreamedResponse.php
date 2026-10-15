<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Chat;

use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Controller\Result\StreamSent;

/**
 * Ends a controller that streamed its answer as server-sent events, without exit().
 *
 * The stream is already on the wire and the headers are sent, so the returned result renders
 * nothing. Not calling exit() lets everything above the controller finish normally: the plugins'
 * finally blocks (tracing spans, for one) and the after-dispatch events.
 */
trait FinishesStreamedResponse
{
    private function finishResponse(): ResultInterface
    {
        return new StreamSent();
    }
}
