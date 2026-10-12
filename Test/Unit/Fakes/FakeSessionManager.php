<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Session\SessionManagerInterface;

/**
 * A session that only tracks whether it is open, and what had been output when it was closed
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
final class FakeSessionManager implements SessionManagerInterface
{
    private bool $isOpen = true;

    private ?string $outputOnClose = null;

    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    public function getOutputOnClose(): ?string
    {
        return $this->outputOnClose;
    }

    public function start()
    {
        $this->isOpen = true;

        return $this;
    }

    public function writeClose()
    {
        $this->isOpen = false;
        $this->outputOnClose = (string)ob_get_contents();
    }

    public function isSessionExists()
    {
        return $this->isOpen;
    }

    public function getSessionId()
    {
        return 'fake-session-id';
    }

    public function getName()
    {
        return 'admin';
    }

    public function setName($name)
    {
        return $this;
    }

    public function destroy(?array $options = null)
    {
        $this->isOpen = false;
    }

    public function clearStorage()
    {
        return $this;
    }

    public function getCookieDomain()
    {
        return '';
    }

    public function getCookiePath()
    {
        return '/';
    }

    public function getCookieLifetime()
    {
        return 0;
    }

    public function setSessionId($sessionId)
    {
        return $this;
    }

    public function regenerateId()
    {
        return $this;
    }

    public function expireSessionCookie()
    {
    }

    public function getSessionIdForHost($urlHost)
    {
        return null;
    }

    public function isValidForHost($host)
    {
        return true;
    }

    public function isValidForPath($path)
    {
        return true;
    }
}
