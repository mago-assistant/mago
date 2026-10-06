<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Error;

use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Model\Conversation\ConfirmationUnavailableException;
use MagoAssistant\Mago\Model\Conversation\ConversationNotFoundException;
use MagoAssistant\Mago\Service\Ai\AiNotConfiguredException;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;

/**
 * Turns a caught exception into what the admin or API caller may see, and logs the real one.
 *
 * An exception message is not written for the person reading the chat: a provider or HTTP client
 * puts the request URL, the status and the response body in it, a database driver the failing
 * query and its values (#202). So the caller gets a fixed sentence with a reference, and the log
 * gets the message under the same reference, with personal data masked the way chat text is.
 * Only exceptions this module raises with a message meant for the admin pass through as written.
 */
class ErrorReporter
{
    /**
     * Credentials an HTTP client puts in a message: a key in the query string, a bearer header, a
     * provider key. The personal-data mask does not know them, and a log file is copied around.
     */
    private const SECRETS = [
        '/\b((?:x-)?api[_-]?key|authorization|proxy-authorization)["\']?\s*:\s*\[?\s*["\']?'
            . '(?:(?:basic|bearer|digest|token)\s+)?[A-Za-z0-9._~+\/=-]{6,}/i' => '$1: [redacted]',
        '/\b((?:api[_-]?)?key|token|secret|password|access_token)=[^&\s"\']+/i' => '$1=[redacted]',
        '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/' => 'Bearer [redacted]',
        '/\bsk-[A-Za-z0-9_-]{8,}/' => '[redacted]',
    ];

    /**
     * Exceptions whose message is written for the person who made the request
     */
    private const USER_FACING = [
        AiNotConfiguredException::class,
        AuthorizationException::class,
        ConfirmationUnavailableException::class,
        ConversationNotFoundException::class,
    ];

    public function __construct(
        private readonly ErrorLogger $errorLogger,
        private readonly PiiHeuristic $piiHeuristic
    ) {
    }

    /**
     * Log the exception and return the message for the admin or API caller
     */
    public function report(string $context, \Throwable $exception): string
    {
        foreach (self::USER_FACING as $class) {
            if ($exception instanceof $class) {
                return $exception->getMessage();
            }
        }
        // Core fills "No such entity with %fieldName = %fieldValue" with the value it looked up;
        // a phrase without parameters ("No store view is available.") carries none.
        if ($exception instanceof NoSuchEntityException && $exception->getParameters() === []) {
            return $exception->getMessage();
        }

        return (string)__(
            'Something went wrong while handling this request. Reference: %1 (see var/log/mago-error.log).',
            $this->log($context, $exception)
        );
    }

    /**
     * The error a failed tool call answers with. It reaches the model, the stored tool trace and a
     * WebApi caller's tool_results: Magento's own LocalizedException ("URL key already exists")
     * tells the model what to fix, while a database or HTTP client exception carries the query or
     * the endpoint and is replaced by a reference. Core repositories wrap such an exception's text
     * in a LocalizedException ("Could not save the page: %1"), so one caused by anything else is
     * treated as that cause.
     */
    public function reportToolFailure(string $context, \Throwable $exception): string
    {
        $reference = $this->log($context, $exception);

        return $this->isMagentoReason($exception)
            ? $exception->getMessage()
            : (string)__('The tool failed unexpectedly. Reference: %1 (see var/log/mago-error.log).', $reference);
    }

    /**
     * Log the exception with personal data masked, and return the reference it is logged under
     */
    public function log(string $context, \Throwable $exception): string
    {
        $reference = bin2hex(random_bytes(4));
        $entry = sprintf('[%s] %s', $reference, $this->describe($exception));
        // The cause an HTTP client or driver wraps is where the actual failure is named
        for ($previous = $exception->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            $entry .= ' | caused by ' . $this->describe($previous);
        }
        $this->errorLogger->addLog($context, $entry);

        return $reference;
    }

    /**
     * Whether the exception is Magento's own reason, written for the person who made the call: a
     * LocalizedException with nothing but LocalizedExceptions behind it
     *
     * @param \Throwable $exception
     * @return bool
     */
    public function isMagentoReason(\Throwable $exception): bool
    {
        for ($link = $exception; $link !== null; $link = $link->getPrevious()) {
            if (!$link instanceof LocalizedException) {
                return false;
            }
        }

        return true;
    }

    private function describe(\Throwable $exception): string
    {
        $message = (string)preg_replace(
            array_keys(self::SECRETS),
            array_values(self::SECRETS),
            $this->piiHeuristic->mask($exception->getMessage())
        );

        return sprintf('%s: %s at %s:%d', $exception::class, $message, $exception->getFile(), $exception->getLine());
    }
}
