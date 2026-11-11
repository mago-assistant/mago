<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Manifest;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Webapi\Exception as WebapiException;
use MagoAssistant\Mago\Model\Manifest\Config\Converter;
use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;
use MagoAssistant\Mago\Service\Api\InProcess\RouteResolver;
use MagoAssistant\Mago\Service\Manifest\ManifestChecker;
use MagoAssistant\Mago\Service\Manifest\ManifestTool;
use MagoAssistant\Mago\Service\Manifest\Placeholders;
use MagoAssistant\Mago\Service\Tool\Verify\AclResourceIndex;
use MagoAssistant\Mago\Service\Tool\Verify\CheckStatus;
use MagoAssistant\Mago\Service\Tool\Verify\ToolCheck;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclResourceProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManifestCheckerTest extends TestCase
{
    private const ACL = ['MageOS_RMA::rma', 'MageOS_RMA::rma_manage', 'MageOS_RMA::rma_status'];

    /** @var array<string, ResolvedRoute> "METHOD path" => route */
    private array $routes = [];

    private bool $moduleEnabled = true;

    /** @var list<string> */
    private array $resolved = [];

    protected function setUp(): void
    {
        $this->routes = [
            'GET rma/search' => $this->route('/V1/rma/search', 'getList', ['MageOS_RMA::rma']),
            'POST rma' => $this->route('/V1/rma', 'save', ['MageOS_RMA::rma_manage']),
            'GET rma/1' => $this->route('/V1/rma/:entityId', 'get', ['MageOS_RMA::rma']),
            'PUT rma/1' => $this->route('/V1/rma/:entityId', 'save', ['MageOS_RMA::rma_status']),
            'POST rma/1/comments' => $this->route('/V1/rma/:rmaId/comments', 'save', []),
        ];
    }

    #[Test]
    public function everyStepOfEveryActionIsMatchedWithPlaceholdersFilledIn(): void
    {
        $checks = $this->checker()->check($this->tool(), []);

        self::assertSame(
            ['GET rma/search', 'POST rma', 'GET rma/1', 'PUT rma/1', 'POST rma/1/comments'],
            $this->resolved
        );
        self::assertSame(CheckStatus::Pass, $checks[0]->status);
        self::assertSame('Declaring module MageOS_RMA is enabled', $checks[0]->message);
        self::assertSame(
            'Action "search": GET /V1/rma/search -> MageOS\RMA\Api\RMARepositoryInterface::getList()',
            $checks[2]->message
        );
        self::assertSame(CheckStatus::Pass, $checks[3]->status, 'create names the route\'s own resource');
        self::assertSame(CheckStatus::Pass, $checks[4]->status);
        self::assertStringStartsWith('Action "approve" step "load":', $checks[4]->message);
    }

    #[Test]
    public function aResourceThatDiffersFromTheRoutesIsAWarningNotAFailure(): void
    {
        $checks = $this->checker()->check($this->tool(), []);

        $save = $checks[5];
        self::assertSame(CheckStatus::Warning, $save->status);
        self::assertSame(
            'Action "approve" step "save": the manifest gates it by MageOS_RMA::rma, the route /V1/rma/:entityId '
            . 'by MageOS_RMA::rma_status; all apply, so an admin needs each of them',
            $save->message
        );
        self::assertSame(CheckStatus::Pass, $checks[6]->status, 'a route without resources cannot disagree');
    }

    #[Test]
    public function namingAnActionChecksOnlyThatAction(): void
    {
        $checks = $this->checker()->check($this->tool(), ['action' => 'create', 'order_id' => 7]);

        self::assertSame(['POST rma'], $this->resolved);
        self::assertCount(3, $checks);
    }

    #[Test]
    public function aRouteNoWebapiDeclaresFails(): void
    {
        unset($this->routes['POST rma']);

        $checks = $this->checker()->check($this->tool(), ['action' => 'create']);

        self::assertSame(CheckStatus::Failure, $checks[2]->status);
        self::assertSame(
            'Action "create": no route matches POST /V1/rma in any webapi.xml (Request does not match any route.)',
            $checks[2]->message
        );
    }

    #[Test]
    public function aRouteGatedByAnUndeclaredResourceFails(): void
    {
        $this->routes['POST rma'] = $this->route('/V1/rma', 'save', ['MageOS_RMA::nope']);

        $checks = $this->checker()->check($this->tool(), ['action' => 'create']);

        self::assertSame(CheckStatus::Failure, $checks[2]->status);
        self::assertSame(
            'Action "create": route /V1/rma is gated by MageOS_RMA::nope, which no acl.xml declares',
            $checks[2]->message
        );
    }

    #[Test]
    public function anActionMarkedReadonlyOnAWritingRouteFails(): void
    {
        $tool = $this->tool(
            '<config version="1"><tool name="t" acl="MageOS_RMA::rma" module="MageOS_RMA" description="d">'
            . '<action name="save" description="d" method="POST" path="rma" readonly="true"/></tool></config>'
        );

        $checks = $this->checker()->check($tool, []);

        self::assertSame(CheckStatus::Failure, $checks[2]->status);
        self::assertSame(
            'Action "save" is marked readonly but has a step that writes (POST, PUT or DELETE); it is treated as a '
            . 'write, so the admin confirms it. Remove readonly="true"',
            $checks[2]->message
        );
    }

    #[Test]
    public function aDisabledModuleFailsAndAnActionWithoutARouteFails(): void
    {
        $this->moduleEnabled = false;
        $tool = $this->tool(
            '<config version="1"><tool name="t" acl="MageOS_RMA::rma" module="MageOS_RMA" description="d">'
            . '<action name="a" description="d"/></tool></config>'
        );

        $checks = $this->checker()->check($tool, []);

        self::assertSame(
            [CheckStatus::Failure, CheckStatus::Pass, CheckStatus::Failure],
            array_map(static fn (ToolCheck $c): CheckStatus => $c->status, $checks)
        );
        self::assertSame(
            'Declaring module MageOS_RMA is not enabled, so the tool is left out of the registry',
            $checks[0]->message
        );
        self::assertSame('Action "a" has no route: give it method and path, or <step> elements', $checks[2]->message);
        self::assertSame([], $this->resolved);
    }

    #[Test]
    public function aPathPlaceholderWithoutAParamFails(): void
    {
        $tool = $this->tool(
            '<config version="1"><tool name="t" acl="MageOS_RMA::rma" module="MageOS_RMA" description="d">'
            . '<action name="items" description="d" method="GET" path="rma/{rmaId}/items">'
            . '<param name="rma_id" type="integer" in="path"/></action></tool></config>'
        );

        $checks = $this->checker()->check($tool, []);

        self::assertSame(CheckStatus::Failure, $checks[2]->status);
        self::assertSame(
            'Action "items": the path uses {rmaId}, which no <param> of the action declares; the call would leave '
            . 'that segment empty',
            $checks[2]->message
        );
        self::assertSame([], $this->resolved, 'nothing is matched against the routes');
    }

    #[Test]
    public function aDraftThatStillCarriesItsTodoMarkersFails(): void
    {
        $tool = $this->tool(
            '<config version="1"><tool name="t" acl="MageOS_RMA::rma" module="MageOS_RMA" '
            . 'description="TODO: what the model can do">'
            . '<action name="list" description="TODO: Find rma by text" method="GET" path="rma/search">'
            . '<param name="query" type="string" criteria="TODO_field"/></action></tool></config>'
        );

        $checks = $this->checker()->check($tool, []);

        self::assertSame(CheckStatus::Failure, $checks[2]->status);
        self::assertStringContainsString('the tool description', $checks[2]->message);
        self::assertStringContainsString('action "list" description', $checks[2]->message);
        self::assertStringContainsString('parameter "query" criteria', $checks[2]->message);
    }

    #[Test]
    public function oneParameterNameWithTwoTypesAcrossActionsFails(): void
    {
        $tool = $this->tool(
            '<config version="1"><tool name="t" acl="MageOS_RMA::rma" module="MageOS_RMA" description="d">'
            . '<action name="get" description="d" method="GET" path="rma/search">'
            . '<param name="id" type="integer"/></action>'
            . '<action name="find" description="d" method="GET" path="rma/search">'
            . '<param name="id" type="string"/></action></tool></config>'
        );

        $checks = $this->checker()->check($tool, []);

        self::assertSame(CheckStatus::Failure, $checks[2]->status);
        self::assertSame(
            'Parameter "id" is integer in action "get" and string in action "find"; the model is shown one schema '
            . 'per tool, so give one of them its own name',
            $checks[2]->message
        );
    }

    #[Test]
    public function aManifestInAnOlderFormatIsAWarning(): void
    {
        $tool = $this->tool(
            '<config version="1"><tool name="t" acl="MageOS_RMA::rma" module="MageOS_RMA" description="d" schema="0">'
            . '<action name="a" description="d" method="GET" path="rma/search"/></tool></config>'
        );

        $older = $this->checker()->check($tool, [])[1];
        $current = $this->checker()->check($this->tool(), [])[1];

        self::assertSame(CheckStatus::Warning, $older->status);
        self::assertSame(
            'Manifest format version 0 is older than the current 1; it is still read, but check the release notes '
            . 'for what changed',
            $older->message
        );
        self::assertSame(CheckStatus::Pass, $current->status);
        self::assertSame('Manifest format version 1', $current->message);
    }

    private function checker(): ManifestChecker
    {
        $resolver = new class ($this->routes, $this->resolved) extends RouteResolver {
            /**
             * @param array<string, ResolvedRoute> $routes
             * @param list<string> $resolved
             */
            public function __construct(private readonly array $routes, private array &$resolved)
            {
            }

            public function resolve(ApiCall $call): ResolvedRoute
            {
                $key = $call->httpMethod . ' ' . $call->endpoint;
                $this->resolved[] = $key;
                if (!isset($this->routes[$key])) {
                    throw new WebapiException(
                        __('Request does not match any route.'),
                        0,
                        WebapiException::HTTP_NOT_FOUND
                    );
                }

                return $this->routes[$key];
            }
        };
        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturnCallback(fn (): bool => $this->moduleEnabled);

        return new ManifestChecker(
            $resolver,
            new AclResourceIndex(FakeAclResourceProvider::withResources(...self::ACL)),
            $moduleManager,
            new Placeholders()
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
        $tool = $this->createStub(ManifestTool::class);
        $tool->method('getDefinition')->willReturn(reset($definitions));

        return $tool;
    }

    /**
     * @param list<string> $acl
     */
    private function route(string $path, string $method, array $acl): ResolvedRoute
    {
        return new ResolvedRoute('MageOS\RMA\Api\RMARepositoryInterface', $method, $path, $acl, []);
    }
}
