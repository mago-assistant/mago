<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Manifest\Scaffold;

use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use MagoAssistant\Mago\Model\Manifest\Config\Converter;
use MagoAssistant\Mago\Service\Manifest\Scaffold\ManifestDrafter;
use MagoAssistant\Mago\Service\Tool\Scaffold\InvalidScaffoldException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManifestDrafterTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../../../etc/mago_tools.xsd';
    private const FIXTURES = __DIR__ . '/_files';

    #[Test]
    public function theDraftValidatesAgainstTheSchemaAsIs(): void
    {
        $xml = $this->drafter()->draft('Vendor_Faq');

        $document = new \DOMDocument();
        $document->loadXML($xml);
        $previous = libxml_use_internal_errors(true);
        $valid = $document->schemaValidate(self::SCHEMA);
        $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
        libxml_use_internal_errors($previous);

        self::assertTrue($valid, implode("\n", $errors));
        self::assertStringContainsString('drafted by bin/magento mago:tool:manifest', $xml);
    }

    #[Test]
    public function oneToolPerTopLevelResourceWithTheMostCommonResourceAsItsAcl(): void
    {
        $tools = $this->tools();

        self::assertSame(['faq', 'reviews'], array_keys($tools));
        self::assertSame('Vendor_Faq::faq_manage', $tools['faq']['acl']);
        self::assertSame('Vendor_Faq', $tools['faq']['module']);
        self::assertSame('Faq', $tools['faq']['title']);
        self::assertStringStartsWith('TODO:', $tools['faq']['description']);
        self::assertSame('Vendor_Faq::reviews', $tools['reviews']['acl']);
    }

    #[Test]
    public function everyRouteBecomesAnActionNamedByVerbAndResource(): void
    {
        $actions = $this->tools()['faq']['actions'];

        self::assertSame(
            ['list_questions', 'get_questions', 'create_questions', 'update_questions', 'delete_questions',
                'create_questions_publish', 'list_topics', 'create_topics'],
            array_keys($actions)
        );
        self::assertSame('GET', $actions['list_questions']['steps'][0]['method']);
        self::assertSame('faq/questions', $actions['list_questions']['steps'][0]['path']);
        self::assertSame('faq/questions/{faq_id}', $actions['get_questions']['steps'][0]['path']);
        self::assertSame('faq/topics/search', $actions['list_topics']['steps'][0]['path']);
    }

    #[Test]
    public function readsAreReadonlyDeletesIrreversibleAndADifferingResourceIsNamed(): void
    {
        $actions = $this->tools()['faq']['actions'];

        self::assertTrue($actions['list_questions']['readonly']);
        self::assertTrue($actions['get_questions']['readonly']);
        self::assertFalse($actions['create_questions']['readonly']);
        self::assertFalse($actions['delete_questions']['readonly']);
        self::assertTrue($actions['delete_questions']['irreversible']);
        self::assertStringStartsWith('TODO:', (string)$actions['delete_questions']['impact']);
        self::assertNull($actions['create_questions']['acl']);
        self::assertSame('Vendor_Faq::faq_publish', $actions['create_questions_publish']['acl']);
    }

    #[Test]
    public function aListGetsTextCriteriaAndAPageSizeAndAGetItsPathParameter(): void
    {
        $actions = $this->tools()['faq']['actions'];

        $list = $actions['list_questions']['params'];
        self::assertSame(['query', 'limit'], array_keys($list));
        self::assertSame('criteria', $list['query']['in']);
        self::assertSame('TODO_field', $list['query']['criteria']);
        self::assertSame('like', $list['query']['condition']);
        self::assertSame('%', $list['query']['wrap']);
        self::assertSame('page_size', $list['limit']['in']);
        self::assertSame('20', $list['limit']['default']);

        $get = $actions['get_questions']['params'];
        self::assertSame(['faq_id'], array_keys($get));
        self::assertSame('path', $get['faq_id']['in']);
        self::assertSame('integer', $get['faq_id']['type']);
        self::assertTrue($get['faq_id']['required']);
    }

    #[Test]
    public function aWriteTakesItsBodyFromTheDataInterfaceWithoutIdsAndTimestamps(): void
    {
        $actions = $this->tools()['faq']['actions'];

        $create = $actions['create_questions'];
        self::assertSame('entity', $create['steps'][0]['body']);
        self::assertSame(['faq_id', 'question', 'answer', 'is_active', 'product_skus'], array_keys($create['params']));
        self::assertSame('boolean', $create['params']['is_active']['type']);
        self::assertSame('array', $create['params']['product_skus']['type']);
        self::assertFalse($create['params']['question']['required']);

        $update = $actions['update_questions'];
        self::assertSame(['faq_id', 'question', 'answer', 'is_active', 'product_skus'], array_keys($update['params']));
        self::assertSame('path', $update['params']['faq_id']['in'], 'the path parameter is declared once');
    }

    #[Test]
    public function scalarServiceArgumentsBecomeBodyParametersRequiredWhenTheMethodRequiresThem(): void
    {
        $publish = $this->tools()['faq']['actions']['create_questions_publish'];

        self::assertNull($publish['steps'][0]['body']);
        self::assertSame(['faq_id', 'note', 'notify'], array_keys($publish['params']));
        self::assertSame('path', $publish['params']['faq_id']['in']);
        self::assertSame('body', $publish['params']['note']['in']);
        self::assertFalse($publish['params']['note']['required']);
        self::assertSame('boolean', $publish['params']['notify']['type']);
    }

    #[Test]
    public function idsArePublicAndEveryOtherFieldIsStrippedUntilClassified(): void
    {
        $xml = $this->drafter()->draft('Vendor_Faq');
        $get = $this->tools()['faq']['actions']['get_questions'];

        self::assertSame(['faq_id' => ['public'], '*' => ['strip']], $get['fields']);
        self::assertStringContainsString(
            'TODO: classify question, answer, is_active, product_skus, created_at as public, tokenise (personal data) '
            . 'or strip',
            $xml
        );
    }

    #[Test]
    public function aServiceThatCannotBeLoadedLeavesATodoInsteadOfBodyParameters(): void
    {
        $xml = $this->drafter()->draft('Vendor_Faq');
        $topics = $this->tools()['faq']['actions']['create_topics'];

        self::assertSame([], $topics['params']);
        self::assertNull($topics['steps'][0]['body']);
        self::assertStringContainsString(
            'TODO: Vendor\Faq\Api\TopicRepositoryInterface::save() could not be loaded; declare the body parameters '
            . 'by hand',
            $xml
        );
    }

    #[Test]
    public function aModuleWithoutRoutesOrAnUnknownModuleIsRefused(): void
    {
        $this->expectException(InvalidScaffoldException::class);
        $this->expectExceptionMessage('Vendor_Empty declares no REST routes in etc/webapi.xml');

        $this->drafter()->draft('Vendor_Empty');
    }

    #[Test]
    public function anUnregisteredModuleIsRefused(): void
    {
        $this->expectException(InvalidScaffoldException::class);
        $this->expectExceptionMessage('Module Vendor_Nope is not registered');

        $this->drafter()->draft('Vendor_Nope');
    }

    #[Test]
    public function aMalformedModuleNameIsRefused(): void
    {
        $this->expectException(InvalidScaffoldException::class);
        $this->expectExceptionMessage('Module must be Vendor_Module');

        $this->drafter()->draft('faq');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function tools(): array
    {
        $document = new \DOMDocument();
        $document->loadXML($this->drafter()->draft('Vendor_Faq'));

        return (new Converter())->convert($document);
    }

    private function drafter(): ManifestDrafter
    {
        $reader = $this->createStub(ModuleDirReader::class);
        $reader->method('getModuleDir')->willReturnCallback(static function (string $type, string $module): string {
            if ($module === 'Vendor_Nope') {
                throw new \InvalidArgumentException("Module '$module' is not correctly registered.");
            }

            return self::FIXTURES . '/' . $module . '/' . $type;
        });

        return new ManifestDrafter($reader, new File());
    }
}
