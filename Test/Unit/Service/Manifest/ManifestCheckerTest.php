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

/**
 * mago:tool:verify is what a manifest author runs before shipping. It must fail on what would go
 * wrong in the chat, and it must not fail or warn on a manifest that is right.
 */
final class ManifestCheckerTest extends TestCase
{
    private const ACL = ['MageOS_RMA::rma', 'MageOS_RMA::rma_manage', 'MageOS_RMA::rma_status'];
    private const TOOL = '<config version="1">'
        . '<tool name="t" acl="MageOS_RMA::rma" module="MageOS_RMA" description="d">';
    private const END = '</tool></config>';
    private const ID = '<field name="entity_id" class="public"/><fields class="strip"/>';
    private const UNSENT = 'a body parameter needs a POST or PUT step without <field> elements or a '
        . '<field value="{name}"> of its own, a criteria, query or page_size parameter a GET step. The admin would '
        . 'confirm a value that is not used';

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
            'GET stores' => $this->route('/V1/stores', 'getList', ['anonymous']),
            'GET carts/mine' => $this->route('/V1/carts/mine', 'get', ['self']),
        ];
    }

    #[Test]
    public function theReferenceFixtureResolvesEveryStepAndRaisesNothingButTheAclNote(): void
    {
        $checks = $this->checker()->check($this->tool(), []);

        self::assertSame(
            ['GET rma/search', 'POST rma', 'GET rma/1', 'PUT rma/1', 'POST rma/1/comments'],
            $this->resolved,
            'every step is matched, with a stand-in in each placeholder'
        );
        self::assertSame([], $this->messages($checks, CheckStatus::Failure));
        self::assertContains('Declaring module MageOS_RMA is enabled', $this->messages($checks, CheckStatus::Pass));
        self::assertContains('Manifest format version 1', $this->messages($checks, CheckStatus::Pass));
        self::assertContains(
            'Action "search": GET /V1/rma/search -> MageOS\RMA\Api\RMARepositoryInterface::getList()',
            $this->messages($checks, CheckStatus::Pass)
        );
        self::assertContains(
            'Action "approve" step "save": the manifest gates it by MageOS_RMA::rma, the route /V1/rma/:entityId '
            . 'by MageOS_RMA::rma_status; all apply, so an admin needs each of them',
            $this->messages($checks, CheckStatus::Warning)
        );
    }

    #[Test]
    public function namingAnActionChecksThatActionAndAnUnknownNameFails(): void
    {
        $one = $this->checker()->check($this->tool(), ['action' => 'create', 'order_id' => 7]);
        self::assertSame(['POST rma'], $this->resolved);
        self::assertSame([], $this->messages($one, CheckStatus::Failure));

        $typo = $this->checker()->check($this->tool(), ['action' => 'serch']);
        self::assertSame(
            ['There is no action "serch"; the tool has: search, create, approve'],
            $this->messages($typo, CheckStatus::Failure)
        );
    }

    #[Test]
    public function aRouteThatDoesNotExistOrIsGatedByAnUnknownResourceFails(): void
    {
        unset($this->routes['POST rma']);
        self::assertContains(
            'Action "create": no route matches POST /V1/rma in any webapi.xml (Request does not match any route.)',
            $this->failures($this->tool(), ['action' => 'create'])
        );

        $this->routes['POST rma'] = $this->route('/V1/rma', 'save', ['MageOS_RMA::nope']);
        self::assertContains(
            'Action "create": route /V1/rma is gated by MageOS_RMA::nope, which no acl.xml declares',
            $this->failures($this->tool(), ['action' => 'create'])
        );
    }

    /**
     * `anonymous` is open to an admin too, so it is no reason to fail; `self` is the customer's own
     * and never works for an admin.
     */
    #[Test]
    public function anOpenRouteIsFineAndACustomerRouteFails(): void
    {
        $tool = $this->tool(
            self::TOOL
            . '<action name="stores" description="d" method="GET" path="stores">' . self::ID . '</action>'
            . '<action name="cart" description="d" method="GET" path="carts/mine">' . self::ID . '</action>'
            . self::END
        );

        $checks = $this->checker()->check($tool, []);

        self::assertSame(
            ['Action "cart": route /V1/carts/mine is the customer\'s own ("self"); an admin can never call it, '
                . 'so it cannot be a chat tool'],
            $this->messages($checks, CheckStatus::Failure)
        );
        self::assertSame([], $this->messages($checks, CheckStatus::Warning));
    }

    #[Test]
    public function aDisabledModuleAnActionWithoutARouteAndAReadonlyWriteFail(): void
    {
        $this->moduleEnabled = false;
        $tool = $this->tool(
            self::TOOL
            . '<action name="a" description="d">' . self::ID . '</action>'
            . '<action name="save" description="d" method="POST" path="rma" readonly="true">' . self::ID . '</action>'
            . self::END
        );

        self::assertSame(
            [
                'Declaring module MageOS_RMA is not enabled, so the tool is left out of the registry',
                'Action "a" has no route: give it method and path, or <step> elements',
                'Action "save" is marked readonly but has a step that writes (POST, PUT or DELETE); it is treated as '
                . 'a write, so the admin confirms it. Remove readonly="true"',
            ],
            $this->failures($tool)
        );
    }

    #[Test]
    public function aPlaceholderHasToNameAParameterOrAnEarlierStep(): void
    {
        $tool = $this->tool(
            self::TOOL
            . '<action name="items" description="d" method="GET" path="rma/{rmaId}/items" status="Loading {id}...">'
            . '<param name="rma_id" type="integer" in="path"/>' . self::ID . '</action>'
            . '<action name="chain" description="d" result="steps.save">' . self::ID
            . '<step name="load" method="GET" path="rma/{steps.later.id}"/>'
            . '<step name="save" method="PUT" path="rma/{steps.load.entity_id}" when="{steps.typo.x}">'
            . '<field name="note" value="{missing}"/></step>'
            . '<step name="later" method="GET" path="rma/search"/></action>'
            . self::END
        );

        $failures = $this->failures($tool);

        self::assertContains(
            'Action "items": the path uses {rmaId}, which no <param> of the action declares; the call would leave '
            . 'that segment empty',
            $failures
        );
        self::assertContains('Action "items": status uses {id}, which is no parameter of the action', $failures);
        self::assertContains(
            'Action "chain": step "load" uses {steps.later.id}, but no step called "later" runs before it',
            $failures
        );
        self::assertContains(
            'Action "chain": step "save" uses {steps.typo.x}, but no step called "typo" runs before it',
            $failures
        );
        self::assertContains(
            'Action "chain": step "save" uses {missing}, which no <param> of the action declares; it would be '
            . 'left empty',
            $failures
        );
        self::assertContains(
            'Action "chain": result="steps.save" must be one placeholder naming a step, such as {steps.load}',
            $failures
        );
    }

    /**
     * A field of an earlier step in a path is valid; it stands in as one segment for the route check.
     */
    #[Test]
    public function aPathBuiltFromAnEarlierStepResolves(): void
    {
        $tool = $this->tool(
            self::TOOL . '<action name="chain" description="d" result="{steps.save}">' . self::ID
            . '<step name="load" method="GET" path="rma/search"/>'
            . '<step name="save" method="PUT" path="rma/{steps.load.entity_id}"/></action>' . self::END
        );
        $this->routes['PUT rma/1'] = $this->route('/V1/rma/:entityId', 'save', ['MageOS_RMA::rma']);

        $checks = $this->checker()->check($tool, []);

        self::assertSame([], $this->messages($checks, CheckStatus::Failure));
        self::assertSame(['GET rma/search', 'PUT rma/1'], $this->resolved);
    }

    #[Test]
    public function whatTheModelIsShownMustMatchAcrossActions(): void
    {
        $tool = $this->tool(
            self::TOOL
            . '<action name="get" description="d" method="GET" path="rma/search">' . self::ID
            . '<param name="id" type="integer" in="query"/><param name="mode" type="string" enum="a,b" in="query"/>'
            . '</action>'
            . '<action name="find" description="d" method="GET" path="rma/search">' . self::ID
            . '<param name="id" type="string" in="query"/><param name="mode" type="string" enum="c,d" in="query"/>'
            . '</action>' . self::END
        );

        self::assertSame(
            [
                'Parameter "id" is integer in action "get" and string in action "find"; the model is shown one schema '
                . 'per tool, so give one of them its own name',
                'Parameter "mode" allows different values in action "get" and in action "find" (enum or items); the '
                . 'model is shown one schema per tool, so give one of them its own name',
            ],
            $this->failures($tool)
        );
    }

    #[Test]
    public function declarationsTheRuntimeWouldIgnoreOrRefuseAreReported(): void
    {
        $tool = $this->tool(
            self::TOOL
            . '<action name="list" description="d" method="GET" path="rma/search" sort="created:downwards" '
            . 'impact="Gone.">' . self::ID
            . '<param name="action" type="string" in="query"/>'
            . '<param name="steps" type="string" in="query"/>'
            . '<param name="note" type="string"/>'
            . '</action>'
            . '<action name="add" description="d" method="POST" path="rma" acl="MageOS_RMA::rma_manage">' . self::ID
            . '<param name="limit" type="integer" in="page_size"/>'
            . '</action>' . self::END
        );

        $checks = $this->checker()->check($tool, []);

        self::assertSame(
            [
                'Action "list": a parameter cannot be called "action", the tool uses that name itself',
                'Action "list": a parameter cannot be called "steps", the tool uses that name itself',
                'Action "list": sort="created:downwards" must be a field, or field:asc or field:desc',
            ],
            $this->messages($checks, CheckStatus::Failure)
        );
        self::assertSame(
            [
                'Action "list" never sends note: ' . self::UNSENT,
                'Action "list" has an impact text that is never shown: it belongs to irreversible="true"',
                'Action "add" never sends limit: ' . self::UNSENT,
            ],
            $this->messages($checks, CheckStatus::Warning)
        );
    }

    /**
     * The card lists what the model sent. A parameter that no step carries would be confirmed by
     * the admin and then not written.
     */
    #[Test]
    public function aParameterNoStepCarriesAnUnknownActionAclAndABadTokenAreReported(): void
    {
        $tool = $this->tool(
            self::TOOL
            . '<action name="set" description="d" acl="MageOS_RMA::rma_delet">' . self::ID
            . '<param name="entity_id" type="integer" in="path"/><param name="status_id" type="integer"/>'
            . '<param name="note" type="string"/><param name="extra" type="string" in="none"/>'
            . '<field name="customer_name" class="tokenise"/>'
            . '<field name="order" class="tokenise" token="order_number"/>'
            . '<step name="save" method="PUT" path="rma/{entity_id}">'
            . '<field name="status_id" value="{status_id}" as="int"/></step></action>' . self::END
        );

        $checks = $this->checker()->check($tool, []);

        self::assertSame(
            [
                'Action "set": acl="MageOS_RMA::rma_delet" is declared in no acl.xml, so no restricted admin could '
                . 'ever run it',
                'Action "set": field "customer_name" is tokenised without a token type; use lowercase letters only, '
                . 'e.g. token="name"',
                'Action "set": field "order" is tokenised with token="order_number", which is no valid token type; '
                . 'use lowercase letters only, e.g. token="name"',
            ],
            $this->messages($checks, CheckStatus::Failure)
        );
        self::assertContains(
            'Action "set" never sends note, extra: ' . self::UNSENT,
            $this->messages($checks, CheckStatus::Warning)
        );
    }

    /**
     * Things that are right and must not be reported: a path segment that is one of a few words,
     * the same enum in another order, the word TODO in a real description, a whole step in `when`.
     */
    #[Test]
    public function aCorrectManifestIsNotReported(): void
    {
        $this->routes['PUT rma/1/approve'] = $this->route('/V1/rma/:id/approve', 'approve', ['MageOS_RMA::rma']);
        $tool = $this->tool(
            self::TOOL
            . '<action name="move" description="Move the TODO list of a return" method="PUT" '
            . 'path="rma/{entity_id}/{transition}">' . self::ID
            . '<param name="entity_id" type="integer" in="path"/>'
            . '<param name="transition" type="string" in="path" enum="approve,reject"/>'
            . '<param name="mode" type="string" enum="a,b"/></action>'
            . '<action name="chain" description="d">' . self::ID
            . '<param name="mode" type="string" enum="b,a" in="none"/>'
            . '<step name="load" method="GET" path="rma/search"/>'
            . '<step name="save" method="POST" path="rma" when="{steps.load}">'
            . '<field name="mode" value="{mode}"/></step></action>' . self::END
        );
        $this->routes['POST rma'] = $this->route('/V1/rma', 'save', ['MageOS_RMA::rma']);

        $checks = $this->checker()->check($tool, []);

        self::assertSame([], $this->messages($checks, CheckStatus::Failure));
        self::assertSame([], $this->messages($checks, CheckStatus::Warning));
        self::assertContains('PUT rma/1/approve', $this->resolved);
    }

    #[Test]
    public function aStoreParameterThatIsNoTextAndAnActionWithoutACatchAllAreReported(): void
    {
        $tool = $this->tool(
            self::TOOL . '<action name="list" description="d" method="GET" path="rma/search">'
            . '<field name="entity_id" class="public"/><field name="admin_url" class="public"/>'
            . '<param name="store_id" type="integer" in="store"/>'
            . '<link label="Open {admin_url}" route="rma/rma/edit" param="id" from="entity_id"/></action>' . self::END
        );

        $checks = $this->checker()->check($tool, []);

        self::assertSame(
            [
                'Action "list": parameter "store_id" sets the store and must be type="string": a call runs in a '
                . 'store by its code',
                'Action "list": the link label "Open {admin_url}" uses {admin_url}, which the action does not '
                . 'classify public. A label reaches the model as text, so it is rendered without that value; name a '
                . 'public field instead',
            ],
            $this->messages($checks, CheckStatus::Failure)
        );
        self::assertSame(
            ['Action "list" has no <fields class="strip"/>. Without it the names of nested fields the action does '
                . 'not classify still reach the model, and in a map keyed by data (an e-mail address, a name) those '
                . 'names are data. Add <fields class="strip"/>'],
            $this->messages($checks, CheckStatus::Warning)
        );
    }

    #[Test]
    public function thingsThatReadAsIfTheyWorkAndDoNothingAreReported(): void
    {
        $tool = $this->tool(
            self::TOOL . '<action name="list" description="d">' . self::ID
            . '<param name="flag" type="boolean" in="none"/><param name="limit" type="string" in="page_size"/>'
            . '<param name="tags" type="array" items="string" in="query"/>'
            . '<step name="find" method="GET" path="rma/search" when="!{flag}"/>'
            . '<step name="one" method="GET" path="rma/{flag}"/>'
            . '<pick field="customer.email"/>'
            . '<link label="Open" route="rma/rma/edit" param="id" from="customer.id"/></action>'
            . '<action name="other" description="d" method="GET" path="rma/search">' . self::ID
            . '<param name="tags" type="array" in="query"/></action>' . self::END
        );

        $checks = $this->checker()->check($tool, []);

        self::assertSame(
            [
                'Action "list": parameter "flag" is a boolean and cannot be a segment of a path',
                'Action "list": parameter "limit" is the page size and must be type="integer"',
            ],
            $this->messages($checks, CheckStatus::Failure),
            'items="string" and no items are the same list, so tags is no clash'
        );
        $warnings = implode("\n", $this->messages($checks, CheckStatus::Warning));
        self::assertStringContainsString('when="!{flag}" is more than one placeholder', $warnings);
        self::assertStringContainsString('customer.email, customer.id names a field inside another', $warnings);
    }

    /**
     * A parameter the action demands and no call carries can never be satisfied; a result has to be
     * a whole step; a catch-all that tokenises still lets the names of nested fields through.
     */
    #[Test]
    public function aDemandedParameterThatIsNeverSentAndAResultThatIsNoStepFail(): void
    {
        $this->routes['DELETE rma/1'] = $this->route('/V1/rma/:entityId', 'deleteById', ['MageOS_RMA::rma']);
        $tool = $this->tool(
            self::TOOL . '<action name="drop" description="d" method="DELETE" path="rma/{entity_id}/{parts}" '
            . 'result="{steps.main.result}">'
            . '<field name="entity_id" class="public"/><fields class="tokenise" token="value"/>'
            . '<param name="entity_id" type="integer" in="path"/>'
            . '<param name="parts" type="array" in="none"/>'
            . '<param name="force" type="boolean" in="query" required="true"/></action>' . self::END
        );

        $checks = $this->checker()->check($tool, []);
        $failures = implode("\n", $this->messages($checks, CheckStatus::Failure));

        self::assertStringContainsString('requires force and never sends it', $failures);
        self::assertStringContainsString(
            'result="{steps.main.result}" must be one placeholder naming a step',
            $failures
        );
        self::assertStringContainsString('parameter "parts" is a list and cannot be one segment of a path', $failures);
        self::assertStringContainsString(
            'has no <fields class="strip"/>',
            implode("\n", $this->messages($checks, CheckStatus::Warning))
        );
    }

    #[Test]
    public function aLinkLabelNamingAFieldThatIsNotPublicFails(): void
    {
        $tool = $this->tool(
            self::TOOL . '<action name="list" description="d" method="GET" path="rma/search">'
            . '<field name="increment_id" class="public"/>'
            . '<field name="customer_name" class="tokenise" token="name"/>'
            . '<link label="Open {increment_id} for {customer_name} in {address.city}" route="rma/rma/edit" '
            . 'param="id" from="entity_id"/></action>' . self::END
        );

        self::assertSame(
            ['Action "list": the link label "Open {increment_id} for {customer_name} in {address.city}" uses '
                . '{customer_name}, {address.city}, which the action does not classify public. A label reaches the '
                . 'model as text, so it is rendered without that value; name a public field instead'],
            $this->failures($tool)
        );
    }

    #[Test]
    public function whatReachesTheModelIsPointedOutWithoutFlaggingIdsAndCodes(): void
    {
        $tool = $this->tool(
            self::TOOL
            . '<action name="list" description="d" method="GET" path="rma/search">' . self::ID
            . '<field name="comment" class="public"/><field name="admin_note" class="public"/>'
            . '<field name="reason" class="tokenise" token="reason"/>'
            . '<field name="reason_id" class="public"/><field name="reason_code" class="public"/>'
            . '<field name="error" class="public"/><field name="url" class="public"/><fields class="strip"/>'
            . '<pick field="entity_id"/><pick field="secret"/>'
            . '<link label="Open {entity_id}" route="rma/rma/edit" param="id" from="entity_id"/></action>'
            . '<action name="all" description="d" method="GET" path="rma/search"><fields class="public"/></action>'
            . '<action name="none" description="d" method="GET" path="rma/search"/>'
            . self::END
        );

        $warnings = $this->messages($this->checker()->check($tool, []), CheckStatus::Warning);

        self::assertSame(
            [
                'Action "list" sends comment, admin_note to the model as text. If a customer or another outside party '
                . 'can write there, classify it tokenise: that text is where a prompt injection arrives',
                'Action "list" declares a field called error. That name is the tool\'s own error message, so a route '
                . 'field called error is always left out of the result',
                'Action "list" has links and a rule for a field called url. A link\'s url is signed with the admin '
                . 'key, so every url of this action is tokenised whatever that rule says',
                'Action "list" picks secret, which no rule lets through: the model does not see it. Give it a '
                . '<field> rule',
                'Action "all" classifies every field it does not name as public (<fields class="public"/>): whatever '
                . 'the route returns reaches the model as text. Name the public fields and strip the rest',
                'Action "none" classifies no field, so the model sees nothing of its result but an error. Declare '
                . '<field name="..." class="public|tokenise|strip"/> for what it may see',
            ],
            $warnings
        );
    }

    #[Test]
    public function aDraftThatStillCarriesItsTodoMarkersFails(): void
    {
        $tool = $this->tool(
            '<config version="1"><tool name="t" acl="MageOS_RMA::rma" module="MageOS_RMA" '
            . 'description="TODO: what the model can do"><instructions>TODO: how to present</instructions>'
            . '<action name="list" description="TODO: Find rma by text" method="GET" path="rma/search">'
            . '<param name="query" type="string" in="criteria" criteria="TODO_field"/>' . self::ID . '</action>'
            . self::END
        );

        $failures = implode("\n", $this->failures($tool));

        self::assertStringContainsString('the tool description', $failures);
        self::assertStringContainsString('the tool instructions', $failures);
        self::assertStringContainsString('action "list" description', $failures);
        self::assertStringContainsString('parameter "query" criteria', $failures);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function failures(ManifestTool $tool, array $params = []): array
    {
        return $this->messages($this->checker()->check($tool, $params), CheckStatus::Failure);
    }

    /**
     * @param ToolCheck[] $checks
     * @return list<string>
     */
    private function messages(array $checks, CheckStatus $status): array
    {
        return array_values(array_map(
            static fn (ToolCheck $check): string => $check->message,
            array_filter($checks, static fn (ToolCheck $check): bool => $check->status === $status)
        ));
    }

    private function checker(): ManifestChecker
    {
        $this->resolved = [];
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
