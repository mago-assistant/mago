<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Logger;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DebugLoggerTest extends TestCase
{
    #[Test]
    public function itWritesNothingWhenDebugModeIsOff(): void
    {
        $logger = new FakeLogger();
        $debugLogger = new DebugLogger($logger, new Json(), (new FakeConfigRepository())->withDebugEnabled(false));

        $debugLogger->addLog('Stream', ['conversation_id' => 12]);

        self::assertSame([], $logger->getMessages());
    }

    #[Test]
    public function itWritesTheEntryWhenDebugModeIsOn(): void
    {
        $logger = new FakeLogger();
        $debugLogger = new DebugLogger($logger, new Json(), (new FakeConfigRepository())->withDebugEnabled(true));

        $debugLogger->addLog('Stream', ['conversation_id' => 12]);
        $debugLogger->addLog('Stream', 'No admin user in session');

        self::assertSame(
            ['Stream: {"conversation_id":12}', 'Stream: No admin user in session'],
            $logger->getMessages()
        );
    }

    #[Test]
    public function itReadsTheDebugFlagOncePerRequest(): void
    {
        $config = (new FakeConfigRepository())->withDebugEnabled(true);
        $debugLogger = new DebugLogger(new FakeLogger(), new Json(), $config);

        $debugLogger->addLog('Stream', 'first');
        $debugLogger->addLog('Stream', 'second');

        self::assertSame(1, $config->getDebugEnabledReads());
    }
}
