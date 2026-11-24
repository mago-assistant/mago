<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Console\Command;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Module\Manager as ModuleManager;
use MagoAssistant\Mago\Api\Tool\ToolProviderInterface;
use MagoAssistant\Mago\Console\Command\VerifyTool;
use MagoAssistant\Mago\Model\Manifest\Config\Converter;
use MagoAssistant\Mago\Service\Acl\ToolAccess;
use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;
use MagoAssistant\Mago\Service\Api\InProcess\RouteResolver;
use MagoAssistant\Mago\Service\Manifest\ManifestChecker;
use MagoAssistant\Mago\Service\Manifest\ManifestTool;
use MagoAssistant\Mago\Service\Manifest\Placeholders;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Service\Tool\Verify\AclResourceIndex;
use MagoAssistant\Mago\Service\Tool\Verify\ToolVerifier;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclResourceProvider;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAdminArea;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePermissionChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * mago:tool:verify on a tool from a manifest, with the real tool, checker, verifier and registry:
 * what a module author sees when running the command the guide tells them to run.
 */
final class VerifyManifestToolTest extends TestCase
{
    private const ACL = ['MageOS_RMA::rma', 'MageOS_RMA::rma_manage', 'MageOS_RMA::rma_status'];

    private FakeInternalApiClient $api;

    protected function setUp(): void
    {
        $this->api = new FakeInternalApiClient();
    }

    #[Test]
    public function withoutAnActionEveryRouteIsCheckedAndNothingIsCalled(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['tool' => 'rma']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode, $display);
        self::assertStringContainsString('[ok] Action "search": GET /V1/rma/search', $display);
        self::assertStringContainsString('[ok] Action "approve" step "save": PUT /V1/rma/1', $display);
        self::assertStringContainsString('No action named, so nothing was called.', $display);
        self::assertStringNotContainsString('Field classification is empty', $display);
        self::assertSame([], $this->api->calls());
    }

    #[Test]
    public function aReadIsCalledAndShownAsTheModelSeesIt(): void
    {
        $this->api->withResponse(FakeInternalApiClient::GET, 'rma/search', [
            'items' => [['entity_id' => 5, 'increment_id' => 'R5', 'customer_name' => 'Jan Jansen']],
            'total_count' => 1,
        ]);
        $tester = $this->tester();

        $exitCode = $tester->execute(['tool' => 'rma', 'params' => '{"action": "search", "query": "R"}']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode, $display);
        self::assertStringContainsString('"increment_id": "R5"', $display);
        self::assertStringNotContainsString('Jan Jansen', $display);
        self::assertCount(1, $this->api->calls());
    }

    #[Test]
    public function anActionTheToolDoesNotHaveFailsAndAMissingToolSaysWhereToLook(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['tool' => 'rma', 'params' => '{"action": "serch"}']));
        self::assertStringContainsString('[fail] There is no action "serch"', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['tool' => 'rmaa']));
        self::assertStringContainsString('var/log/mago-error.log (ManifestTools)', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $routes = new class extends RouteResolver {
            public function __construct()
            {
            }

            public function resolve(ApiCall $call): ResolvedRoute
            {
                return new ResolvedRoute('Repo', 'method', '/V1/' . $call->endpoint, ['MageOS_RMA::rma'], []);
            }
        };
        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturn(true);
        $backendUrl = $this->createStub(BackendUrl::class);
        $backendUrl->method('getUrl')->willReturn('https://admin.test/rma');

        $document = new \DOMDocument();
        $document->load(__DIR__ . '/../../Model/Manifest/Config/_files/mago_tools.xml');
        $definitions = (new Converter())->convert($document);
        $tool = new ManifestTool(
            reset($definitions),
            $this->api,
            new Placeholders(),
            new SecureAdminUrl($backendUrl),
            $moduleManager,
            $routes
        );
        $provider = new class ($tool) implements ToolProviderInterface {
            public function __construct(private readonly ManifestTool $tool)
            {
            }

            public function getTools(): array
            {
                return [$this->tool];
            }
        };
        $aclIndex = new AclResourceIndex(FakeAclResourceProvider::withResources(...self::ACL));

        return new CommandTester(new VerifyTool(
            new ToolRegistry(null, [], [$provider]),
            new ToolVerifier(
                new PrivacyFilter(new ConversationVault(), new PiiHeuristic()),
                $aclIndex,
                new ToolAccess(new FakeAuthorization(), new FakePermissionChecker()),
                new ManifestChecker($routes, $aclIndex, $moduleManager, new Placeholders())
            ),
            new FakeAdminArea()
        ));
    }
}
