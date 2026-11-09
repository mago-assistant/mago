<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\Assert;

final class MagentoObjectManager
{
    private const AREA = 'adminhtml';
    private static ?ObjectManagerInterface $objectManager = null;

    public static function get(): ObjectManagerInterface
    {
        return self::$objectManager ??= self::boot();
    }

    public static function isAvailable(): bool
    {
        return is_file(self::bootstrapFile());
    }

    private static function boot(): ObjectManagerInterface
    {
        if (!self::isAvailable()) {
            Assert::markTestSkipped('Integration tests need the module mounted inside a Magento install.');
        }

        require_once self::bootstrapFile();
        $objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $objectManager->get(State::class)->setAreaCode(self::AREA);

        return $objectManager;
    }

    private static function bootstrapFile(): string
    {
        return dirname(__DIR__, 5) . '/app/bootstrap.php';
    }
}
