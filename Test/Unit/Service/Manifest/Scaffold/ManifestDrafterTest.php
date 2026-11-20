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
        self::assertSame(
            ['faq_id', 'question', 'answer', 'is_active', 'product_skus', 'vat_id', 'note'],
            array_keys($create['params'])
        );
        self::assertSame('boolean', $create['params']['is_active']['type']);
        self::assertSame('array', $create['params']['product_skus']['type']);
        self::assertFalse($create['params']['question']['required']);

        $update = $actions['update_questions'];
        self::assertSame(
            ['faq_id', 'question', 'answer', 'is_active', 'product_skus', 'vat_id', 'note'],
            array_keys($update['params'])
        );
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
            'TODO: classify question, answer, is_active, product_skus, created_at, vat_id, note as public, tokenise '
            . '(personal data) '
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

    /**
     * A chat tool is an admin's tool: the storefront's and the customer's own routes are no part of
     * a draft, and the draft says how many it left out.
     */
    #[Test]
    public function routesForCustomersAndGuestsAreLeftOut(): void
    {
        $xml = $this->drafter()->draft('Vendor_Mixed');

        self::assertStringContainsString('2 route(s) for customers or guests', $xml);
        self::assertStringNotContainsString('carts', $xml);
        self::assertStringNotContainsString('stores', $xml);
        self::assertStringNotContainsString('acl="anonymous"', $xml);
        self::assertStringNotContainsString('acl="self"', $xml);
    }

    #[Test]
    public function aModuleWithOnlyStorefrontRoutesIsRefused(): void
    {
        $this->expectException(InvalidScaffoldException::class);
        $this->expectExceptionMessage('only declares REST routes for customers and guests');

        $this->drafter()->draft('Vendor_Shop');
    }

    #[Test]
    public function namesStayWithinTheSchemaAndOneToolNameIsOneTool(): void
    {
        $xml = $this->drafter()->draft('Vendor_Mixed');
        $tools = $this->tools('Vendor_Mixed');

        self::assertTrue($this->validates($xml), 'long and colliding names must not break the schema');
        self::assertSame(['rma', 'rma_lines'], array_keys($tools), 'rma-lines and rma_lines are one tool');
        self::assertSame(['list', 'get'], array_keys($tools['rma_lines']['actions']));
        foreach (array_keys($tools['rma']['actions']) as $name) {
            self::assertLessThanOrEqual(64, strlen((string)$name));
        }
        self::assertCount(8, $tools['rma']['actions'], 'the two long names stay apart');
    }

    /**
     * An increment id is text: typed integer, "000000012" would be sent as 12.
     */
    #[Test]
    public function aPathParameterIsTypedByTheContractAndAnIncrementIdIsText(): void
    {
        $actions = $this->tools('Vendor_Mixed')['rma']['actions'];

        self::assertSame('string', $actions['get_increment_id']['params']['increment_id']['type']);
        self::assertSame('integer', $actions['create_invoice']['params']['order_id']['type']);
    }

    /**
     * Guessing how several arguments nest would send the scalars inside the wrong wrapper.
     */
    #[Test]
    public function aMixedSignatureGetsItsScalarsAndATodoInsteadOfAGuessedWrapper(): void
    {
        $xml = $this->drafter()->draft('Vendor_Mixed');
        $invoice = $this->tools('Vendor_Mixed')['rma']['actions']['create_invoice'];

        self::assertNull($invoice['steps'][0]['body']);
        self::assertSame(['order_id', 'capture'], array_keys($invoice['params']));
        self::assertStringContainsString('also takes $entity, $items, $comment, which could not be drafted', $xml);
    }

    /**
     * What the draft cannot know it must not decide: a text id is not public, a read's arguments
     * travel in the query, and a body the route may refuse is pointed out.
     */
    #[Test]
    public function theDraftDoesNotDecideWhatItCannotKnow(): void
    {
        $xml = $this->drafter()->draft('Vendor_Mixed');
        $actions = $this->tools('Vendor_Mixed')['rma']['actions'];

        $reasons = $actions['get_reasons'];
        self::assertSame('query', $reasons['params']['store_id']['in']);
        self::assertTrue($reasons['params']['store_id']['required']);
        self::assertSame('query', $reasons['params']['code']['in']);
        self::assertSame(['public'], $reasons['fields']['faq_id'], 'a numeric id');
        self::assertArrayNotHasKey('vat_id', $reasons['fields'], 'a text id waits for the author');

        $comment = $actions['create_comments'];
        self::assertSame('comment', $comment['steps'][0]['body']);
        self::assertArrayNotHasKey('3d_secure', $comment['params'], 'no name the schema refuses');
        self::assertStringContainsString('rma_id is in the path but is no field of $comment', $xml);
        self::assertStringContainsString('product_skus are lists, drafted as lists of text', $xml);
        self::assertTrue($this->validates($xml));

        self::assertSame('string', $comment['params']['note']['type'], 'a getter typed in its docblock only');
        self::assertArrayNotHasKey('children', $comment['params'], 'a list of objects is no parameter');
        $purge = $actions['delete_purge'];
        self::assertSame(['faq_id'], array_keys($purge['params']), 'a DELETE carries no query parameter');
        self::assertStringContainsString('the route also takes $force, which a DELETE cannot carry', $xml);

        $byCode = $actions['get_code'];
        self::assertSame('string', $byCode['params']['code']['type'], 'typed by the @param of the contract');
        self::assertSame(['code'], array_keys($byCode['params']));
        self::assertStringContainsString('the route also takes $anything, which could not be drafted', $xml);
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
    private function tools(string $module = 'Vendor_Faq'): array
    {
        $document = new \DOMDocument();
        $document->loadXML($this->drafter()->draft($module));

        return (new Converter())->convert($document);
    }

    private function validates(string $xml): bool
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $previous = libxml_use_internal_errors(true);
        $valid = $document->schemaValidate(self::SCHEMA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $valid;
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
