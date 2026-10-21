<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Http;

use Magento\Framework\App\Response\HeaderManager;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Sends the headers of a server-sent event stream.
 *
 * A stream sends its headers itself, before the first event, so the response never reaches
 * Magento's sendResponse() and the HeaderManager plugin that adds the security headers there
 * (X-Frame-Options, X-Content-Type-Options, HSTS and whatever else a module registers). Those are
 * applied here first, from the same providers, so a stream gets what every other admin page gets.
 */
class EventStreamHeaders
{
    public function __construct(
        private readonly HeaderManager $headerManager
    ) {
    }

    public function send(HttpResponse $response): void
    {
        $this->headerManager->beforeSendResponse($response);
        $response->setHeader('Content-Type', 'text/event-stream', true);
        $response->setHeader('Cache-Control', 'no-cache', true);
        $response->setHeader('Connection', 'keep-alive', true);
        $response->setHeader('X-Accel-Buffering', 'no', true);
        $response->sendHeaders();
    }
}
