<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml\Chat;

use MagoAssistant\Mago\Test\Unit\Fakes\FakeSessionManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReleasesSessionLockTest extends TestCase
{
    #[Test]
    public function startingTheStreamClosesTheSessionSoOtherAdminTabsAreNotBlocked(): void
    {
        $session = new FakeSessionManager();
        $this->expectOutputString(": \n\n");

        (new SessionLockActionDouble($session))->startStream();

        self::assertFalse($session->isOpen());
    }

    /**
     * Magento only starts a session while no headers are out; a session created later in the turn
     * must not take the lock back, so the stream is committed before the close.
     */
    #[Test]
    public function theStreamIsCommittedWithAnSseCommentBeforeTheSessionCloses(): void
    {
        $session = new FakeSessionManager();
        $this->expectOutputString(": \n\n");

        (new SessionLockActionDouble($session))->startStream();

        self::assertSame(": \n\n", $session->getOutputOnClose());
    }
}
