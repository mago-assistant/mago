<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Result;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * The result of a controller that already streamed its whole response to the client.
 *
 * The headers and the body are on the wire by the time the controller returns, so there is nothing
 * left to render: renderResult() leaves the response as it is. Returning this instead of calling
 * exit() lets everything above the controller finish normally (plugin finally blocks, tracing
 * spans, after-dispatch events).
 */
class StreamSent implements ResultInterface
{
    /**
     * @param int $httpCode
     * @return $this
     */
    public function setHttpResponseCode($httpCode)
    {
        return $this;
    }

    /**
     * @param string $name
     * @param string $value
     * @param bool $replace
     * @return $this
     */
    public function setHeader($name, $value, $replace = false)
    {
        return $this;
    }

    /**
     * @param ResponseInterface $response
     * @return $this
     */
    public function renderResult(ResponseInterface $response)
    {
        return $this;
    }
}
