<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Manifest\Config;

use MagoAssistant\Mago\Model\Manifest\Config\Converter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The converter is the only place the XML is read; ManifestTool works on its arrays alone. So
 * every attribute and default the tool relies on is pinned here, against the documented example.
 */
final class ConverterTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $tools;

    protected function setUp(): void
    {
        $document = new \DOMDocument();
        $document->load(__DIR__ . '/_files/mago_tools.xml');
        $this->tools = (new Converter())->convert($document);
    }

    #[Test]
    public function toolsAreKeyedByNameWithTheirHeaderAndInstructions(): void
    {
        self::assertSame(['rma'], array_keys($this->tools));
        $tool = $this->tools['rma'];

        self::assertSame('rma', $tool['name']);
        self::assertSame('MageOS_RMA::rma', $tool['acl']);
        self::assertSame('MageOS_RMA', $tool['module']);
        self::assertSame('Returns', $tool['title']);
        self::assertSame('Search and handle return requests.', $tool['description']);
        self::assertSame(
            'Resolve a number to an id with search before changing anything.',
            $tool['instructions']
        );
        self::assertSame(['search', 'create', 'approve'], array_keys($tool['actions']));
        self::assertSame('1', $tool['schema_version'], 'read outside the reader: the current format');
    }

    #[Test]
    public function anActionWithMethodAndPathBecomesItsOwnSingleStep(): void
    {
        $search = $this->tools['rma']['actions']['search'];

        self::assertTrue($search['readonly']);
        self::assertFalse($search['irreversible']);
        self::assertNull($search['acl']);
        self::assertSame('Searching returns for {query}...', $search['status']);
        self::assertSame('', $search['instructions']);
        self::assertSame([[
            'name' => 'main',
            'method' => 'GET',
            'path' => 'rma/search',
            'body' => null,
            'when' => null,
            'sort' => 'created_at:desc',
            'fields' => [],
        ]], $search['steps']);
    }

    #[Test]
    public function paramsCarryLocationCriteriaAndDefaults(): void
    {
        $params = $this->tools['rma']['actions']['search']['params'];

        self::assertSame(['query', 'limit', 'store'], array_keys($params));
        self::assertSame([
            'name' => 'query',
            'type' => 'string',
            'description' => 'Part of the RMA number',
            'required' => true,
            'in' => 'criteria',
            'criteria' => 'increment_id',
            'condition' => 'like',
            'wrap' => '%',
            'items' => null,
            'enum' => [],
            'default' => null,
        ], $params['query']);
        self::assertSame('page_size', $params['limit']['in']);
        self::assertSame('10', $params['limit']['default']);
        self::assertFalse($params['limit']['required']);
        self::assertSame('store', $params['store']['in']);
    }

    #[Test]
    public function aParamWithoutLocationGoesInTheBodyAndAnEnumIsTrimmed(): void
    {
        $create = $this->tools['rma']['actions']['create'];

        self::assertSame('body', $create['params']['order_id']['in']);
        self::assertSame('integer', $create['params']['order_id']['type']);
        self::assertSame('array', $create['params']['tags']['type']);
        self::assertSame('string', $create['params']['tags']['items']);
        self::assertSame(['refund', 'replacement', 'repair'], $create['params']['tags']['enum']);
        self::assertSame('MageOS_RMA::rma_manage', $create['acl']);
        self::assertSame('rma', $create['steps'][0]['body']);
        self::assertSame('Only create an RMA for an order the customer named.', $create['instructions']);
    }

    #[Test]
    public function privacyFieldsPickAndLinksAreRead(): void
    {
        $search = $this->tools['rma']['actions']['search'];

        self::assertSame([
            'entity_id' => ['public'],
            'increment_id' => ['public'],
            '*' => ['strip'],
        ], $search['fields']);
        self::assertSame(['entity_id', 'increment_id'], $search['pick']);
        self::assertSame([[
            'label' => 'Open RMA {increment_id}',
            'route' => 'rma/rma/edit',
            'param' => 'entity_id',
            'from' => 'entity_id',
        ]], $search['links']);
    }

    #[Test]
    public function explicitStepsKeepOrderConditionsAndCastFields(): void
    {
        $publish = $this->tools['rma']['actions']['approve'];

        self::assertTrue($publish['irreversible']);
        self::assertSame('RMA {entity_id} is approved and the customer is told.', $publish['impact']);
        self::assertSame('{steps.save}', $publish['result']);
        self::assertSame(['load', 'save', 'comment'], array_column($publish['steps'], 'name'));
        self::assertSame('GET', $publish['steps'][0]['method']);
        self::assertSame('rma/{entity_id}', $publish['steps'][0]['path']);
        self::assertSame([
            ['name' => 'entity_id', 'value' => '{entity_id}', 'as' => 'int'],
            ['name' => 'status_id', 'value' => '3', 'as' => 'int'],
            ['name' => 'increment_id', 'value' => '{steps.load.increment_id}', 'as' => 'string'],
        ], $publish['steps'][1]['fields']);
        self::assertSame('rma', $publish['steps'][1]['body']);
        self::assertSame('{note}', $publish['steps'][2]['when']);
        self::assertSame([['name' => 'comment', 'value' => '{note}', 'as' => 'raw']], $publish['steps'][2]['fields']);
    }

    #[Test]
    public function readonlyOnAWritingRouteIsTreatedAsAWriteAndRemembered(): void
    {
        $document = new \DOMDocument();
        $document->loadXML(
            '<config version="1"><tool name="t" acl="V::a" module="Vendor_Module" description="d">'
            . '<action name="save" description="d" method="POST" path="x" readonly="true"/>'
            . '<action name="load" description="d" method="GET" path="x" readonly="true"/>'
            . '<action name="chain" description="d" readonly="true"><step name="a" method="GET" path="x"/>'
            . '<step name="b" method="PUT" path="x"/></action>'
            . '</tool></config>'
        );

        $actions = (new Converter())->convert($document)['t']['actions'];

        self::assertFalse($actions['save']['readonly']);
        self::assertTrue($actions['save']['readonly_declared']);
        self::assertTrue($actions['load']['readonly']);
        self::assertFalse($actions['chain']['readonly'], 'one writing step makes the whole action a write');
        self::assertFalse($this->tools['rma']['actions']['create']['readonly_declared']);
    }

    #[Test]
    public function anActionWithNeitherStepsNorRouteHasNoSteps(): void
    {
        $document = new \DOMDocument();
        $document->loadXML(
            '<config version="1"><tool name="t" acl="V::a" module="Vendor_Module" description="d">'
            . '<action name="a" description="d"/></tool></config>'
        );

        $tools = (new Converter())->convert($document);

        self::assertSame([], $tools['t']['actions']['a']['steps']);
        self::assertSame('', $tools['t']['title']);
    }

    #[Test]
    public function theVersionTheReaderStampedOnAToolIsHandedOn(): void
    {
        $document = new \DOMDocument();
        $document->loadXML(
            '<config version="1"><tool name="t" acl="V::a" module="Vendor_Module" description="d" schema="1">'
            . '<action name="a" description="d" method="GET" path="x"/></tool></config>'
        );

        self::assertSame('1', (new Converter())->convert($document)['t']['schema_version']);
    }
}
