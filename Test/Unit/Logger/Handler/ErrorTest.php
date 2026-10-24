<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Logger\Handler;

use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Logger\Handler\Error;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorTest extends TestCase
{
    private string $root;

    private Error $handler;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mago-error-handler-' . uniqid();
        $this->handler = new Error(new File(), $this->root);
    }

    protected function tearDown(): void
    {
        $this->handler->close();
        array_map('unlink', glob($this->root . '/var/log/*') ?: []);
        array_map(
            'rmdir',
            array_filter([$this->root . '/var/log', $this->root . '/var', $this->root], 'is_dir')
        );
    }

    #[Test]
    public function itWritesAWarningToTheErrorLog(): void
    {
        $errorLogger = new ErrorLogger(new Logger('test', [$this->handler]), new Json());

        $errorLogger->addWarning('Tool Arguments Never Declared', ['keys' => ['country_code']]);

        self::assertStringContainsString(
            'Tool Arguments Never Declared: {"keys":["country_code"]}',
            $this->logContents()
        );
    }

    #[Test]
    public function itKeepsInfoOutOfTheErrorLog(): void
    {
        $logger = new Logger('test', [$this->handler]);

        $logger->info('Stream: {"conversation_id":12}');

        self::assertSame('', $this->logContents());
    }

    private function logContents(): string
    {
        $path = $this->root . '/var/log/mago-error.log';

        return is_file($path) ? (string)file_get_contents($path) : '';
    }
}
