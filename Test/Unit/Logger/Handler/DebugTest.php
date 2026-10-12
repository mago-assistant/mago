<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Logger\Handler;

use Magento\Framework\App\Filesystem\DirectoryList;
use MagoAssistant\Mago\Logger\Handler\Debug;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DebugTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mago-debug-handler-' . uniqid();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/var/log/*') ?: []);
        array_map(
            'rmdir',
            array_filter([$this->root . '/var/log', $this->root . '/var', $this->root], 'is_dir')
        );
    }

    #[Test]
    public function itWritesToADatedFileWithTheDebugLogNameAsBase(): void
    {
        $handler = new Debug(new DirectoryList($this->root));

        (new Logger('test', [$handler]))->info('Stream: {"conversation_id":12}');
        $handler->close();

        $files = glob($this->root . '/var/log/mago-debug-*.log') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/mago-debug-\d{4}-\d{2}-\d{2}\.log$/', $files[0]);
        self::assertStringContainsString('Stream: {"conversation_id":12}', (string)file_get_contents($files[0]));
    }
}
