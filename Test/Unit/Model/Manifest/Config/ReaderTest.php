<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Manifest\Config;

use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Config\ValidationStateInterface;
use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\Mago\Model\Manifest\Config\Converter;
use MagoAssistant\Mago\Model\Manifest\Config\Reader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Merging keeps one <config> root, so the format version each file declares would be lost; the
 * reader stamps it on the file's tools first, and the converter hands it on per tool.
 */
final class ReaderTest extends TestCase
{
    private const TOOL = '<tool name="%s" acl="V::a" module="Vendor_%s" description="d">'
        . '<action name="list" description="d" method="GET" path="x"/></tool>';

    #[Test]
    public function eachToolCarriesTheVersionOfTheFileItCameFrom(): void
    {
        $tools = $this->reader([
            'a/etc/mago_tools.xml' => '<config version="1">' . sprintf(self::TOOL, 'alpha', 'A') . '</config>',
            'b/etc/mago_tools.xml' => '<config version="1">' . sprintf(self::TOOL, 'beta', 'B') . '</config>',
        ])->read();

        self::assertSame(['alpha', 'beta'], array_keys($tools));
        self::assertSame('1', $tools['alpha']['schema_version']);
        self::assertSame('1', $tools['beta']['schema_version']);
        self::assertSame('Vendor_B', $tools['beta']['module']);
    }

    #[Test]
    public function aFileWithoutAVersionIsRefusedByName(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The manifest "b/etc/mago_tools.xml" has no version');

        $this->reader([
            'a/etc/mago_tools.xml' => '<config version="1">' . sprintf(self::TOOL, 'alpha', 'A') . '</config>',
            'b/etc/mago_tools.xml' => '<config>' . sprintf(self::TOOL, 'beta', 'B') . '</config>',
        ])->read();
    }

    /**
     * @param array<string, string> $files
     */
    private function reader(array $files): Reader
    {
        $resolver = $this->createStub(FileResolverInterface::class);
        $resolver->method('get')->willReturn($files);
        $schemaLocator = $this->createStub(SchemaLocatorInterface::class);
        $schemaLocator->method('getSchema')->willReturn(null);
        $schemaLocator->method('getPerFileSchema')->willReturn(null);
        $validation = $this->createStub(ValidationStateInterface::class);
        $validation->method('isValidationRequired')->willReturn(false);

        return new Reader($resolver, new Converter(), $schemaLocator, $validation, Reader::FILE_NAME, [
            '/config/tool' => 'name',
            '/config/tool/action' => 'name',
        ]);
    }
}
