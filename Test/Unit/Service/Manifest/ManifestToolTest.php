<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Manifest;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Module\Manager as ModuleManager;
use MagoAssistant\Mago\Model\Manifest\Config\Converter;
use MagoAssistant\Mago\Service\Manifest\ManifestTool;
use MagoAssistant\Mago\Service\Manifest\Placeholders;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The manifest tool runs the documented example manifest against the internal API client, so what
 * a module author writes in XML is pinned to the calls Mago makes for it.
 */
final class ManifestToolTest extends TestCase
{
    private const ADMIN_ID = 7;

    private FakeInternalApiClient $api;

    private bool $moduleEnabled = true;

    protected function setUp(): void
    {
        $this->api = new FakeInternalApiClient();
    }

    #[Test]
    public function theModelSeesTheToolAsTheManifestDescribesIt(): void
    {
        $tool = $this->tool();

        self::assertSame('rma', $tool->getName());
        self::assertSame('Returns', $tool->getDisplayName());
        self::assertSame(
            'Search and handle return requests. Actions: "search" (Find RMAs by number), '
            . '"create" (Create an RMA), "approve" (Approve an RMA).',
            $tool->getDescription()
        );
        self::assertSame(
            'Search and handle return requests. Actions: "search" (Find RMAs by number).',
            $tool->getDescriptionForActions(['search'])
        );
        self::assertSame(
            "Resolve a number to an id with search before changing anything.\n\n"
            . "## create\nOnly create an RMA for an order the customer named.",
            $tool->getInstructions()
        );
    }

    #[Test]
    public function theSchemaListsTheActionsAndTheirParametersOnce(): void
    {
        $schema = $this->tool()->getParameterSchema();

        self::assertSame(
            ['action', 'query', 'limit', 'store', 'order_id', 'reason', 'tags', 'entity_id', 'note'],
            array_keys($schema['properties'])
        );
        self::assertSame(['search', 'create', 'approve'], $schema['properties']['action']['enum']);
        self::assertSame(['action'], $schema['required']);
        self::assertSame(
            ['type' => 'string', 'description' => 'Part of the RMA number'],
            $schema['properties']['query']
        );
        self::assertSame(
            ['type' => 'array', 'enum' => ['refund', 'replacement', 'repair'], 'items' => ['type' => 'string']],
            $schema['properties']['tags']
        );

        $narrowed = $this->tool()->getParameterSchemaForActions(['create']);
        self::assertSame(['action', 'order_id', 'reason', 'tags'], array_keys($narrowed['properties']));
        self::assertSame(['create'], $narrowed['properties']['action']['enum']);
    }

    #[Test]
    public function readOnlyAclAndIrreversibilityFollowTheAction(): void
    {
        $tool = $this->tool();

        self::assertFalse($tool->isReadOnly());
        self::assertTrue($tool->isReadOnlyAction(['action' => 'search']));
        self::assertFalse($tool->isReadOnlyAction(['action' => 'create']));
        self::assertFalse($tool->isReadOnlyAction(['action' => 'nope']), 'an unknown action fails closed');

        self::assertSame('MageOS_RMA::rma', $tool->getMagentoAcl());
        self::assertSame('MageOS_RMA::rma', $tool->getMagentoAcl(['action' => 'search']));
        self::assertSame('MageOS_RMA::rma_manage', $tool->getMagentoAcl(['action' => 'create']));
        self::assertNull($tool->getActionAcl(['action' => 'search']));
        self::assertSame('MageOS_RMA::rma_manage', $tool->getActionAcl(['action' => 'create']));

        self::assertFalse($tool->isIrreversibleAction(['action' => 'create']));
        self::assertTrue($tool->isIrreversibleAction(['action' => 'approve']));
        self::assertSame([], $tool->getImpacts(['action' => 'create'], self::ADMIN_ID));
        self::assertSame(
            ['RMA 12 is approved and the customer is told.'],
            $tool->getImpacts(['action' => 'approve', 'entity_id' => 12], self::ADMIN_ID)
        );
    }

    #[Test]
    public function anIrreversibleActionWithoutAnImpactTextGetsTheDefaultLine(): void
    {
        $tool = $this->tool(
            '<config><tool name="t" acl="V::a" module="Vendor_Module" description="d">'
            . '<action name="drop" description="d" method="DELETE" path="x/{id}" irreversible="true">'
            . '<param name="id" type="integer" in="path"/></action>'
            . '</tool></config>'
        );

        self::assertSame(
            ['This cannot be undone.'],
            $tool->getImpacts(['action' => 'drop', 'id' => 1], self::ADMIN_ID)
        );
        self::assertSame('T', $tool->getDisplayName());
    }

    #[Test]
    public function fieldClassificationComesFromTheActionAndIsEmptyForAnUnknownOne(): void
    {
        $tool = $this->tool();

        self::assertSame(
            [
                'entity_id' => ['public'],
                'increment_id' => ['public'],
                PiiClass::ANY => ['strip'],
                'items' => ['public'],
                'total_count' => ['public'],
            ],
            $tool->getFieldClassification('search'),
            'a catch-all of strip must not swallow the items wrapper'
        );
        self::assertSame([], $tool->getFieldClassification('create'));
        self::assertSame([], $tool->getFieldClassification('nope'));
    }

    #[Test]
    public function theStatusMessageRendersTheInputAndIsNullWhenNotDeclared(): void
    {
        $tool = $this->tool();

        self::assertSame(
            'Searching returns for 0001...',
            $tool->getStatusMessage('search', ['action' => 'search', 'query' => '0001'])
        );
        self::assertNull($tool->getStatusMessage('create', ['action' => 'create']));
    }

    #[Test]
    public function availabilityFollowsTheDeclaringModule(): void
    {
        self::assertTrue($this->tool()->isAvailable());
        $this->moduleEnabled = false;
        self::assertFalse($this->tool()->isAvailable());
    }

    #[Test]
    public function aSearchBuildsSearchCriteriaFromTheCriteriaParamsAndPicksLinksTheItems(): void
    {
        $this->api->withResponse(FakeInternalApiClient::GET, 'rma/search', [
            'items' => [
                ['entity_id' => 3, 'increment_id' => '000000003', 'customer_email' => 'a@b.c', 'secret' => 'x'],
                ['entity_id' => 4, 'increment_id' => '000000004', 'customer_email' => 'd@e.f'],
            ],
            'total_count' => 2,
            'search_criteria' => [],
        ]);

        $result = $this->tool()->execute(
            ['action' => 'search', 'query' => '00000', 'store' => 'nl', '_admin_user_id' => self::ADMIN_ID]
        );

        $call = $this->api->calls()[0];
        self::assertSame(FakeInternalApiClient::GET, $call['method']);
        self::assertSame('rma/search', $call['endpoint']);
        self::assertSame(self::ADMIN_ID, $call['admin_user_id']);
        self::assertSame('nl', $call['store_code']);
        self::assertSame([
            'searchCriteria[pageSize]' => 10,
            'searchCriteria[currentPage]' => 1,
            'searchCriteria[filter_groups][0][filters][0][field]' => 'increment_id',
            'searchCriteria[filter_groups][0][filters][0][value]' => '%00000%',
            'searchCriteria[filter_groups][0][filters][0][conditionType]' => 'like',
            'searchCriteria[sortOrders][0][field]' => 'created_at',
            'searchCriteria[sortOrders][0][direction]' => 'DESC',
        ], $call['payload']);

        self::assertSame([
            'items' => [
                ['entity_id' => 3, 'increment_id' => '000000003'],
                ['entity_id' => 4, 'increment_id' => '000000004'],
            ],
            'total_count' => 2,
            '_links' => [
                ['label' => 'Open RMA 000000003', 'url' => 'https://admin.test/rma/rma/edit/entity_id/3'],
                ['label' => 'Open RMA 000000004', 'url' => 'https://admin.test/rma/rma/edit/entity_id/4'],
            ],
        ], $result);
    }

    #[Test]
    public function aPageSizeParamOverridesTheDefaultAndTheDefaultStoreIsUsedWithoutOne(): void
    {
        $this->tool()->execute(
            ['action' => 'search', 'query' => 'x', 'limit' => 3, '_admin_user_id' => self::ADMIN_ID]
        );

        $call = $this->api->calls()[0];
        self::assertSame(3, $call['payload']['searchCriteria[pageSize]']);
        self::assertNull($call['store_code']);
    }

    /**
     * A provider in strict mode makes the model send every schema property, with 0, "" or false where
     * it has nothing; those must not become filters or body fields.
     */
    #[Test]
    public function zeroValuesOfOptionalParamsCountAsNotGiven(): void
    {
        $this->tool()->execute([
            'action' => 'search',
            'query' => 'x',
            'topic' => 0,
            'store' => '',
            'limit' => 0,
            'entity_id' => 0,
            'order_id' => 0,
            'note' => '',
            '_admin_user_id' => self::ADMIN_ID,
        ]);

        $call = $this->api->calls()[0];
        self::assertNull($call['store_code']);
        self::assertSame(10, $call['payload']['searchCriteria[pageSize]'], 'limit 0 falls back to the default');
        self::assertArrayNotHasKey('searchCriteria[filter_groups][1][filters][0][field]', $call['payload']);
    }

    #[Test]
    public function aZeroForARequiredParamIsStillSent(): void
    {
        $tool = $this->tool(
            '<config><tool name="t" acl="V::a" module="Vendor_Module" description="d">'
            . '<action name="set" description="d" method="POST" path="x" body="e">'
            . '<param name="id" type="integer" required="true"/>'
            . '<param name="active" type="integer" required="true"/>'
            . '<param name="position" type="integer"/>'
            . '</action></tool></config>'
        );

        $tool->execute(
            ['action' => 'set', 'id' => 5, 'active' => 0, 'position' => 0, '_admin_user_id' => self::ADMIN_ID]
        );

        self::assertSame(['e' => ['id' => 5, 'active' => 0]], $this->api->calls()[0]['payload']);
    }

    #[Test]
    public function aMissingRequiredParamIsAnErrorBeforeAnyCall(): void
    {
        $result = $this->tool()->execute(
            ['action' => 'create', 'order_id' => 7, '_admin_user_id' => self::ADMIN_ID]
        );

        self::assertSame(['error' => 'Parameter "reason" is required for create'], $result);
        self::assertSame([], $this->api->calls());
    }

    #[Test]
    public function anUnknownActionIsAnError(): void
    {
        self::assertSame(['error' => 'Unknown action: nope'], $this->tool()->execute(['action' => 'nope']));
        self::assertSame(['error' => 'Unknown action: '], $this->tool()->execute([]));
    }

    #[Test]
    public function aPostSendsTheBodyParamsUnderTheDeclaredWrapperAndNothingElse(): void
    {
        $this->api->withResponse(FakeInternalApiClient::POST, 'rma', ['entity_id' => 9, 'increment_id' => '000000009']);

        $result = $this->tool()->execute([
            'action' => 'create',
            'order_id' => 7,
            'reason' => 'Broken on arrival',
            'tags' => ['refund'],
            'rogue' => 'ignored',
            '_admin_user_id' => self::ADMIN_ID,
        ]);

        $call = $this->api->calls()[0];
        self::assertSame(FakeInternalApiClient::POST, $call['method']);
        self::assertSame('rma', $call['endpoint']);
        self::assertSame(
            ['rma' => ['order_id' => 7, 'reason' => 'Broken on arrival', 'tags' => ['refund']]],
            $call['payload']
        );
        self::assertSame(['entity_id' => 9, 'increment_id' => '000000009'], $result);
    }

    #[Test]
    public function stepsRunInOrderFeedEachOtherAndSkipWhenTheirConditionIsEmpty(): void
    {
        $this->api
            ->withResponse(FakeInternalApiClient::GET, 'rma/12', ['entity_id' => 12, 'increment_id' => '000012'])
            ->withResponse(
                FakeInternalApiClient::PUT,
                'rma/12',
                ['entity_id' => 12, 'increment_id' => '000012', 'status_id' => 3]
            );

        $result = $this->tool()->execute(
            ['action' => 'approve', 'entity_id' => 12, '_admin_user_id' => self::ADMIN_ID]
        );

        $calls = $this->api->calls();
        self::assertCount(2, $calls, 'the comment step is skipped without a note');
        self::assertSame([FakeInternalApiClient::GET, FakeInternalApiClient::PUT], array_column($calls, 'method'));
        self::assertSame('rma/12', $calls[1]['endpoint']);
        self::assertSame(
            ['rma' => ['entity_id' => 12, 'status_id' => 3, 'increment_id' => '000012']],
            $calls[1]['payload']
        );
        self::assertSame(
            ['entity_id' => 12, 'increment_id' => '000012', 'status_id' => 3],
            $result,
            'result="{steps.save}" picks that step'
        );
    }

    #[Test]
    public function aConditionalStepRunsWhenItsPlaceholderIsSet(): void
    {
        $this->api->withResponse(FakeInternalApiClient::GET, 'rma/12', ['entity_id' => 12, 'increment_id' => '000012']);

        $this->tool()->execute(
            ['action' => 'approve', 'entity_id' => 12, 'note' => 'Checked by legal', '_admin_user_id' => self::ADMIN_ID]
        );

        $calls = $this->api->calls();
        self::assertCount(3, $calls);
        self::assertSame(FakeInternalApiClient::POST, $calls[2]['method']);
        self::assertSame('rma/12/comments', $calls[2]['endpoint']);
        self::assertSame(['comment' => 'Checked by legal'], $calls[2]['payload']);
    }

    #[Test]
    public function aFailingStepStopsTheActionWithItsError(): void
    {
        $this->api->withResponse(FakeInternalApiClient::GET, 'rma/12', ['error' => 'No such entity']);

        $result = $this->tool()->execute(
            ['action' => 'approve', 'entity_id' => 12, '_admin_user_id' => self::ADMIN_ID]
        );

        self::assertSame(['error' => 'No such entity'], $result);
        self::assertCount(1, $this->api->calls());
    }

    #[Test]
    public function pathPlaceholdersAreUrlEncodedAndADeleteCarriesNoBody(): void
    {
        $tool = $this->tool(
            '<config><tool name="t" acl="V::a" module="Vendor_Module" description="d">'
            . '<action name="drop" description="d" method="DELETE" path="products/{sku}">'
            . '<param name="sku" type="string" in="path" required="true"/></action>'
            . '</tool></config>'
        );
        $this->api->withResponse(FakeInternalApiClient::DELETE, 'products/AB%2F1', ['result' => true]);

        $result = $tool->execute(['action' => 'drop', 'sku' => 'AB/1', '_admin_user_id' => self::ADMIN_ID]);

        $call = $this->api->calls()[0];
        self::assertSame(FakeInternalApiClient::DELETE, $call['method']);
        self::assertSame('products/AB%2F1', $call['endpoint']);
        self::assertSame([], $call['payload']);
        self::assertSame(['result' => true], $result);
    }

    #[Test]
    public function aScalarResultExpressionIsWrappedUnderValue(): void
    {
        $tool = $this->tool(
            '<config><tool name="t" acl="V::a" module="Vendor_Module" description="d">'
            . '<action name="count" description="d" method="GET" path="x/count" readonly="true" '
            . 'result="{steps.main.total}"/>'
            . '</tool></config>'
        );
        $this->api->withResponse(FakeInternalApiClient::GET, 'x/count', ['total' => 5]);

        self::assertSame(['value' => 5], $tool->execute(['action' => 'count', '_admin_user_id' => self::ADMIN_ID]));
        self::assertSame([], $this->api->calls()[0]['payload'], 'no criteria params means a plain GET');
    }

    #[Test]
    public function queryParamsGoInTheQueryAsIsNextToTheCriteria(): void
    {
        $tool = $this->tool(
            '<config><tool name="t" acl="V::a" module="Vendor_Module" description="d">'
            . '<action name="list" description="d" method="GET" path="x" readonly="true">'
            . '<param name="status" type="string" in="criteria" criteria="status"/>'
            . '<param name="fields" type="string" in="query"/>'
            . '</action></tool></config>'
        );

        $tool->execute(
            ['action' => 'list', 'status' => 'open', 'fields' => 'items[id]', '_admin_user_id' => self::ADMIN_ID]
        );

        $payload = $this->api->calls()[0]['payload'];
        self::assertSame('items[id]', $payload['fields']);
        self::assertSame('status', $payload['searchCriteria[filter_groups][0][filters][0][field]']);
        self::assertSame('open', $payload['searchCriteria[filter_groups][0][filters][0][value]']);
        self::assertArrayNotHasKey('searchCriteria[filter_groups][0][filters][0][conditionType]', $payload);
        self::assertSame(
            20,
            $payload['searchCriteria[pageSize]'],
            'the default page size applies without a page_size param'
        );
    }

    private function tool(?string $xml = null): ManifestTool
    {
        $document = new \DOMDocument();
        if ($xml === null) {
            $document->load(__DIR__ . '/../../Model/Manifest/Config/_files/mago_tools.xml');
        } else {
            $document->loadXML($xml);
        }
        $definitions = (new Converter())->convert($document);

        $backendUrl = $this->createStub(BackendUrl::class);
        $backendUrl->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string
                => 'https://admin.test/' . $route . '/' . key($params) . '/' . current($params)
        );
        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturnCallback(
            fn (string $module): bool => $module === 'MageOS_RMA' && $this->moduleEnabled
        );

        return new ManifestTool(
            reset($definitions),
            $this->api,
            new Placeholders(),
            new SecureAdminUrl($backendUrl),
            $moduleManager
        );
    }
}
