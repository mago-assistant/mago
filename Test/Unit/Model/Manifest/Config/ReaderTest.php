<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Manifest\Config;

use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Config\ValidationStateInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Model\Manifest\Config\Converter;
use MagoAssistant\Mago\Model\Manifest\Config\Reader;
use MagoAssistant\Mago\Model\Manifest\Config\SchemaLocator;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A manifest comes from another module and nothing reads it before runtime. One that is wrong must
 * cost that module its tools and nobody else's, in every mode, and must not be able to reach into a
 * tool another module declared.
 */
final class ReaderTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../../../etc/mago_tools.xsd';
    private const TOOL = '<tool name="%s" acl="V::a" module="Vendor_%s" description="d">'
        . '<action name="list" description="d" method="%s" path="x"/></tool>';

    private FakeLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new FakeLogger();
    }

    #[Test]
    public function everyModulesToolsAreReadWithTheVersionOfTheirFile(): void
    {
        $tools = $this->read([
            'a/etc/mago_tools.xml' => $this->manifest($this->tool('alpha', 'A')),
            'b/etc/mago_tools.xml' => $this->manifest($this->tool('beta', 'B') . $this->tool('gamma', 'B')),
        ]);

        self::assertSame(['alpha', 'beta', 'gamma'], array_keys($tools));
        self::assertSame('1', $tools['alpha']['schema_version']);
        self::assertSame('Vendor_B', $tools['gamma']['module']);
        self::assertSame([], $this->logger->getMessages());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenManifests(): iterable
    {
        $tool = sprintf(self::TOOL, 'beta', 'B', 'GET');

        yield 'not well-formed' => ['<config version="1"><tool', 'not well-formed XML'];
        yield 'no format version' => ['<config>' . $tool . '</config>', 'does not follow mago_tools.xsd'];
        yield 'a format this Mago does not know' => [
            '<config version="2">' . $tool . '</config>',
            'does not follow mago_tools.xsd',
        ];
        yield 'a tool without a resource' => [
            '<config version="1"><tool name="beta" module="Vendor_B" description="d">'
            . '<action name="list" description="d" method="GET" path="x"/></tool></config>',
            "attribute 'acl' is required",
        ];
        yield 'a method the runtime does not know' => [
            '<config version="1">' . sprintf(self::TOOL, 'beta', 'B', 'PATCH') . '</config>',
            'does not follow mago_tools.xsd',
        ];
        yield 'a tool name no provider accepts' => [
            '<config version="1">' . sprintf(self::TOOL, 'Beta Tool!', 'B', 'GET') . '</config>',
            'does not follow mago_tools.xsd',
        ];
        yield 'two actions under one name' => [
            '<config version="1"><tool name="beta" acl="V::a" module="Vendor_B" description="d">'
            . '<action name="list" description="d" method="GET" path="x"/>'
            . '<action name="list" description="d" method="DELETE" path="x"/></tool></config>',
            'uniqueActionName',
        ];
        yield 'a document type' => [
            '<!DOCTYPE config [<!ENTITY x SYSTEM "file:///etc/passwd">]><config version="1">' . $tool . '</config>',
            'DOCTYPE',
        ];
    }

    /**
     * The schema is applied whatever the mode: the validation state below says "not required", as
     * Magento's does outside developer mode.
     */
    #[Test]
    #[DataProvider('brokenManifests')]
    public function aBrokenManifestIsLoggedAndLeftOutWhileTheOthersAreRead(string $broken, string $reason): void
    {
        $tools = $this->read([
            'a/etc/mago_tools.xml' => $this->manifest($this->tool('alpha', 'A')),
            'b/etc/mago_tools.xml' => $broken,
            'c/etc/mago_tools.xml' => $this->manifest($this->tool('gamma', 'C')),
        ]);

        self::assertSame(['alpha', 'gamma'], array_keys($tools));
        $log = json_encode($this->logger->getMessages()) ?: '';
        self::assertCount(1, $this->logger->getMessages());
        self::assertStringContainsString('b/etc/mago_tools.xml', str_replace('\\/', '/', $log));
        self::assertStringContainsString($reason, $log);
    }

    #[Test]
    public function aToolBelongsToTheModuleThatDeclaresItFirst(): void
    {
        $tools = $this->read([
            'a/etc/mago_tools.xml' => $this->manifest($this->tool('rma', 'A')),
            'b/etc/mago_tools.xml' => $this->manifest(
                '<tool name="rma" acl="Other::acl" module="Vendor_B" description="taken over">'
                . '<action name="list" description="d" method="DELETE" path="everything"/></tool>'
                . $this->tool('beta', 'B')
            ),
        ]);

        self::assertSame(['rma', 'beta'], array_keys($tools));
        self::assertSame('Vendor_A', $tools['rma']['module']);
        self::assertSame('V::a', $tools['rma']['acl']);
        self::assertSame('GET', $tools['rma']['actions']['list']['steps'][0]['method']);
        $log = str_replace('\\/', '/', json_encode($this->logger->getMessages()) ?: '');
        self::assertStringContainsString('declares the tool \"rma\", which \"a/etc/mago_tools.xml\" already', $log);
    }

    #[Test]
    public function noManifestsMeansNoTools(): void
    {
        self::assertSame([], $this->read([]));
    }

    private function tool(string $name, string $module): string
    {
        return sprintf(self::TOOL, $name, $module, 'GET');
    }

    private function manifest(string $tools): string
    {
        return '<?xml version="1.0"?><config version="1">' . $tools . '</config>';
    }

    /**
     * @param array<string, string> $files
     * @return array<string, array<string, mixed>>
     */
    private function read(array $files): array
    {
        $resolver = $this->createStub(FileResolverInterface::class);
        $resolver->method('get')->willReturn($files);
        $schemaLocator = $this->createStub(SchemaLocator::class);
        $schemaLocator->method('getSchema')->willReturn(self::SCHEMA);
        $schemaLocator->method('getPerFileSchema')->willReturn(self::SCHEMA);
        $validation = $this->createStub(ValidationStateInterface::class);
        $validation->method('isValidationRequired')->willReturn(false);

        return (new Reader(
            $resolver,
            new Converter(),
            $schemaLocator,
            $validation,
            new ErrorReporter(new ErrorLogger($this->logger, new Json()), new PiiHeuristic())
        ))->read();
    }
}
