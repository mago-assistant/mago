<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Manifest\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The XSD is the contract a module author writes against, so it has to accept the documented
 * example and reject the mistakes that would otherwise surface only in the chat.
 */
final class ManifestSchemaTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../../../etc/mago_tools.xsd';

    #[Test]
    public function theExampleManifestIsValid(): void
    {
        self::assertSame([], $this->violations((string)file_get_contents(__DIR__ . '/_files/mago_tools.xml')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidManifests(): iterable
    {
        $action = '<action name="list" description="d" method="GET" path="x"/>';
        $tool = static fn (string $attributes, ?string $body = null): string =>
            '<config><tool ' . $attributes . '>' . ($body ?? $action) . '</tool></config>';
        $get = static fn (string $inner): string =>
            '<action name="a" description="d" method="GET" path="x">' . $inner . '</action>';
        $valid = 'name="faq" acl="Vendor_Module::faq" module="Vendor_Module" description="d"';

        yield 'tool without acl' => [$tool('name="faq" module="Vendor_Module" description="d"'), 'acl'];
        yield 'tool without module' => [$tool('name="faq" acl="V::a" description="d"'), 'module'];
        yield 'module name without vendor' => [$tool('name="faq" acl="V::a" module="Faq" description="d"'), 'module'];
        yield 'tool name with capitals' => [
            $tool('name="FaqTool" acl="V::a" module="Vendor_Module" description="d"'),
            'name',
        ];
        yield 'tool without actions' => [$tool($valid, ''), 'action'];
        yield 'duplicate tool names' => [
            '<config>' . substr($tool($valid), 8, -9) . substr($tool($valid), 8, -9) . '</config>',
            'uniqueToolName',
        ];
        yield 'unknown http method' => [
            $tool($valid, '<action name="a" description="d" method="PATCH" path="x"/>'),
            'PATCH',
        ];
        yield 'unknown param type' => [
            $tool($valid, $get('<param name="p" type="float"/>')),
            'float',
        ];
        yield 'unknown param location' => [
            $tool($valid, $get('<param name="p" type="string" in="header"/>')),
            'header',
        ];
        yield 'unknown privacy class' => [
            $tool($valid, $get('<field name="f" class="secret"/>')),
            'secret',
        ];
        yield 'step without path' => [
            $tool($valid, '<action name="a" description="d"><step name="s" method="GET"/></action>'),
            'path',
        ];
        yield 'link without from' => [
            $tool($valid, $get('<link label="l" route="r" param="id"/>')),
            'from',
        ];
    }

    #[Test]
    #[DataProvider('invalidManifests')]
    public function aManifestBreakingTheContractIsRejected(string $xml, string $mentioned): void
    {
        $violations = $this->violations($xml);

        self::assertNotSame([], $violations, 'the schema must reject this manifest');
        self::assertStringContainsString($mentioned, implode("\n", $violations));
    }

    /**
     * @return list<string>
     */
    private function violations(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $valid = $document->schemaValidate(self::SCHEMA);
        $errors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $valid ? [] : $errors;
    }
}
