<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Ai;

use MageOS\AiBase\Api\AiClientInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Api\Acl;
use MagoAssistant\Mago\Api\Config\RepositoryInterface;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Ai\AiNotConfiguredException;
use MagoAssistant\Mago\Service\Ai\AnswerWidgets;
use MagoAssistant\Mago\Service\Ai\ChatService;
use MagoAssistant\Mago\Service\Ai\Client;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;
use MagoAssistant\Mago\Service\Skills\PermissionChecker;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Service\Usage\UsageLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\BuildsStoreLayouts;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeHighImpactTool;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeIrreversibleAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePresentableTool;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTool;
use PHPUnit\Framework\Attributes\DataProvider;
use MagoAssistant\Mago\Service\Acl\ToolAccess;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePermissionChecker;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeValidatingTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatServiceTest extends TestCase
{
    use BuildsStoreLayouts;

    private const SYSTEM_PROMPT = 'You are a Magento store assistant.';

    private const ADMIN_ID = 7;

    /** 100 tokens is 400 bytes of tool output, small enough to cross in a test and never in life */
    private const MAX_RESPONSE_TOKENS = 100;

    /** @var array<int, array<string, mixed>> Messages the provider received on the last call */
    private array $sentMessages = [];

    /** @var list<array<string, mixed>> Messages the provider received per chat() call */
    private array $requests = [];

    /** @var list<array<string, mixed>> Canned provider responses, consumed in order */
    private array $responses = [];

    /** @var array<string, string> skill name => read|write|disabled */
    private array $grants = [];

    private ChatService $chatService;

    private FakeLogger $debugLog;

    protected function setUp(): void
    {
        $this->grants = ['cms_data' => 'read'];
        $this->chatService = $this->buildChatService();
    }

    /**
     * The service under test: a cms_data skill with one read and one write action, plus whatever
     * extra skills a test needs (an irreversible order action, a tool that fails, ...).
     *
     * @param FakeSkill[] $extraSkills
     */
    private function buildChatService(
        array $extraSkills = [],
        ?PrivacyService $privacy = null,
        bool $answerWidgets = false,
        bool $isDebugEnabled = false
    ): ChatService
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);

        $cmsData = new FakeSkill('cms_data', $authorization, [
            'list_pages' => new FakeAction('list_pages', true, [], 'Always mention the page count.'),
            'update_page' => new FakeAction('update_page', false, ['content' => ['type' => 'string']]),
            'check_links' => new FakeAction('check_links', true, [], '', ['error' => 'Link checker is offline']),
        ]);

        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('isAllowed')->willReturnCallback(
            fn (int $adminUserId, string $skill, string $action): bool => match ($this->grants[$skill] ?? 'disabled') {
                'write' => true,
                'read' => $action === 'read',
                default => false,
            }
        );

        $client = $this->createMock(Client::class);
        $client->method('resolve')->willReturn($this->createMock(AiClientInterface::class));
        $client->method('chat')->willReturnCallback(function (AiClientInterface $c, array $messages): array {
            $this->requests[] = $messages;
            return array_shift($this->responses) ?? ['content' => 'done', 'tool_calls' => []];
        });
        $client->method('stream')->willReturnCallback(
            function (AiClientInterface $c, array $messages, array $tools, callable $onChunk): array {
                $this->requests[] = $messages;
                $response = array_shift($this->responses) ?? ['content' => 'done', 'tool_calls' => []];
                foreach ($response['streamed'] ?? [] as $text) {
                    $onChunk('text', ['text' => $text]);
                }
                unset($response['streamed']);

                return $response;
            }
        );

        $json = new Json();
        $config = (new FakeConfigRepository())->withMaxToolIterations(5)->withMaxResponseTokens(4000)
            ->withAnswerWidgets($answerWidgets)->withDebugEnabled($isDebugEnabled);
        $this->debugLog = new FakeLogger();
        return new ChatService(
            $config,
            $client,
            new ToolRegistry($checker, array_merge([$cmsData], $extraSkills)),
            new DebugLogger($this->debugLog, $json, $config),
            new ErrorReporter(new ErrorLogger(new FakeLogger(), $json), new PiiHeuristic()),
            $this->createMock(UsageLogger::class),
            new StoreScopeContext($this->singleStoreManager()),
            new AnswerWidgets(new ErrorLogger(new FakeLogger(), new Json())),
            new PageContextHolder(),
            $privacy ?? $this->privacyService(),
            new ToolAccess($authorization, $checker)
        );
    }

    private function privacyService(?ConversationVault $vault = null): PrivacyService
    {
        $vault ??= new ConversationVault();

        return new PrivacyService(new PrivacyFilter($vault, new PiiHeuristic()), $vault, new PiiHeuristic());
    }

    /**
     * An order_manager skill whose "cancel" action cannot be undone
     */
    private function orderManagerSkill(?\Throwable $impactsFailure = null): FakeSkill
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);

        return new FakeSkill('order_manager', $authorization, [
            'cancel' => new FakeIrreversibleAction(
                'cancel',
                ['Order #100 is canceled and cannot be reopened.', 'Reserved stock returns to inventory.'],
                $impactsFailure
            ),
        ]);
    }

    /**
     * #245: a reversible write that changes something to weigh comes with its cautions, and a
     * broken caution lookup still asks with a caution instead of blocking the ask or dropping it.
     */
    #[Test]
    public function confirmationCarriesTheCautionsOfAHighImpactWrite(): void
    {
        $this->grants = ['cms_data' => 'write', 'config_writer' => 'write', 'broken_writer' => 'write'];
        $service = $this->buildChatService([
            new FakeHighImpactTool('config_writer', ['Adds or changes HTML and scripts on every storefront page.']),
            new FakeHighImpactTool('broken_writer', [], new \RuntimeException('lookup failed')),
        ]);
        $this->responses = [[
            'content' => '',
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'config_writer', 'input' => ['path' => 'design/head/includes']],
                ['id' => 'call_2', 'name' => 'broken_writer', 'input' => ['path' => 'x']],
                ['id' => 'call_3', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'x']],
            ],
        ]];
        $confirm = null;
        $onChunk = static function (string $type, array $data) use (&$confirm): void {
            if ($type === 'confirm') {
                $confirm = $data;
            }
        };

        $service->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertNotNull($confirm);
        self::assertTrue($confirm['tools'][0]['caution']);
        self::assertArrayNotHasKey('irreversible', $confirm['tools'][0]);
        self::assertSame(
            ['Adds or changes HTML and scripts on every storefront page.'],
            $confirm['tools'][0]['impacts']
        );
        self::assertTrue($confirm['tools'][1]['caution']);
        self::assertSame(
            ['Could not work out what this changes. Check it before you allow it.'],
            $confirm['tools'][1]['impacts']
        );
        self::assertArrayNotHasKey('caution', $confirm['tools'][2]);
    }

    #[Test]
    public function confirmationNamesEachToolCallAndMarksIrreversibleActions(): void
    {
        $this->grants = ['cms_data' => 'write', 'order_manager' => 'write'];
        $service = $this->buildChatService([$this->orderManagerSkill()]);
        $this->responses = [[
            'content' => '',
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'x']],
                ['id' => 'call_2', 'name' => 'order_manager', 'input' => ['action' => 'cancel', 'order_number' => '100']],
            ],
        ]];
        $confirm = null;
        $onChunk = static function (string $type, array $data) use (&$confirm): void {
            if ($type === 'confirm') {
                $confirm = $data;
            }
        };

        $service->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertNotNull($confirm);
        self::assertSame(['call_1', 'call_2'], array_column($confirm['tools'], 'id'));
        self::assertArrayNotHasKey('irreversible', $confirm['tools'][0]);
        self::assertTrue($confirm['tools'][1]['irreversible']);
        self::assertSame(
            ['Order #100 is canceled and cannot be reopened.', 'Reserved stock returns to inventory.'],
            $confirm['tools'][1]['impacts']
        );
    }

    #[Test]
    public function confirmationFlagsAWriteCarryingAMaskedPersonalValue(): void
    {
        $this->grants = ['cms_data' => 'write'];
        $service = $this->buildChatService();
        $this->responses = [[
            'content' => '',
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'Mail mago://email_1']],
                ['id' => 'call_2', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'About us']],
            ],
        ]];
        $confirm = null;
        $onChunk = static function (string $type, array $data) use (&$confirm): void {
            if ($type === 'confirm') {
                $confirm = $data;
            }
        };

        $service->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertTrue($confirm['tools'][0]['sensitive']);
        self::assertArrayNotHasKey('sensitive', $confirm['tools'][1]);
    }

    #[Test]
    public function aBrokenImpactLookupStillAsksWithAnEmptyImpactList(): void
    {
        $this->grants = ['order_manager' => 'write'];
        $service = $this->buildChatService([$this->orderManagerSkill(new \RuntimeException('orders API down'))]);
        $this->responses = [[
            'content' => '',
            'tool_calls' => [['id' => 'call_2', 'name' => 'order_manager', 'input' => ['action' => 'cancel']]],
        ]];
        $confirm = null;
        $onChunk = static function (string $type, array $data) use (&$confirm): void {
            if ($type === 'confirm') {
                $confirm = $data;
            }
        };

        $service->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertTrue($confirm['tools'][0]['irreversible']);
        self::assertSame([], $confirm['tools'][0]['impacts']);
    }

    #[Test]
    public function executeConfirmedToolsSkipsTheCallsTheUserLeftUnticked(): void
    {
        $this->grants = ['cms_data' => 'write'];
        $this->chatService = $this->buildChatService();
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type . ':' . ($data['status'] ?? '');
        };

        $results = $this->chatService->executeConfirmedTools(
            [
                ['id' => 'call_1', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'a']],
                ['id' => 'call_2', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'b']],
            ],
            self::ADMIN_ID,
            $onChunk,
            ['call_2']
        );

        self::assertTrue($results['call_1']['skipped']);
        self::assertSame('update_page', $results['call_2']['executed']);
        self::assertSame(['tool_status:running', 'tool_status:done'], $events);
    }

    #[Test]
    public function executeConfirmedToolsRunsEverythingWhenNoSelectionIsGiven(): void
    {
        $this->grants = ['cms_data' => 'write'];
        $this->chatService = $this->buildChatService();

        $results = $this->chatService->executeConfirmedTools([
            ['id' => 'call_1', 'name' => 'cms_data', 'input' => ['action' => 'update_page']],
            ['id' => 'call_2', 'name' => 'cms_data', 'input' => ['action' => 'update_page']],
        ], self::ADMIN_ID);

        self::assertSame('update_page', $results['call_1']['executed']);
        self::assertSame('update_page', $results['call_2']['executed']);
    }

    #[Test]
    public function aToolThatAnswersWithAnErrorIsAnnouncedAsFailed(): void
    {
        $this->responses = [$this->toolCallResponse('check_links')];
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type . ':' . ($data['status'] ?? '') . ($data['status'] === 'failed' ? ':' . $data['message'] : '');
        };

        $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertSame(['tool_status:running', 'tool_status:failed:Link checker is offline'], $events);
    }

    #[Test]
    public function deniedWriteActionIsAnsweredImmediatelyInsteadOfAskingForConfirmation(): void
    {
        $this->responses = [
            $this->toolCallResponse('update_page', ['content' => 'x']),
            ['content' => 'Sorry, I cannot do that.', 'tool_calls' => []],
        ];

        $result = $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertArrayNotHasKey('pending_confirmation', $result);
        self::assertSame('Sorry, I cannot do that.', $result['content']);
        self::assertCount(2, $this->requests);

        $toolResult = $this->lastMessageOfRole($this->requests[1], 'tool');
        self::assertStringContainsString('Access denied', $toolResult['content']);
        self::assertStringContainsString('cms_data', $toolResult['content']);
    }

    #[Test]
    public function permittedWriteActionStillRequiresConfirmation(): void
    {
        $this->grants = ['cms_data' => 'write'];
        $this->responses = [$this->toolCallResponse('update_page', ['content' => 'x'])];

        $result = $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertTrue($result['pending_confirmation']);
        self::assertCount(1, $this->requests);
    }

    #[Test]
    public function instructionsAreNotInjectedForADeniedCall(): void
    {
        $this->responses = [$this->toolCallResponse('update_page', ['content' => 'x'])];

        $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertNull($this->instructionMessage($this->requests[1]));
    }

    #[Test]
    public function instructionsAreInjectedAfterAnExecutedCall(): void
    {
        $this->responses = [$this->toolCallResponse('list_pages')];

        $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        $instruction = $this->instructionMessage($this->requests[1]);
        self::assertNotNull($instruction);
        self::assertStringContainsString('Always mention the page count.', $instruction['content']);
        self::assertStringNotContainsString(AnswerWidgets::MARKER, $instruction['content']);
    }

    #[Test]
    public function instructionsRemindTheModelOfTheWidgetsWhenAnswerWidgetsAreOn(): void
    {
        $chatService = $this->buildChatService(answerWidgets: true);
        $this->responses = [$this->toolCallResponse('list_pages')];

        $chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        $instruction = $this->instructionMessage($this->requests[1]);
        self::assertNotNull($instruction);
        self::assertStringContainsString('Always mention the page count.', $instruction['content']);
        self::assertStringContainsString((new AnswerWidgets(new ErrorLogger(new FakeLogger(), new Json())))->toToolReminder(), $instruction['content']);
    }

    #[Test]
    public function theDebugLogRecordsWhichToolRanButNotItsInputOrOutput(): void
    {
        $chatService = $this->buildChatService(isDebugEnabled: true);
        $this->responses = [$this->toolCallResponse('list_pages', ['query' => 'jane@example.com'])];

        $chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        $log = implode("\n", $this->debugLog->getMessages());
        self::assertStringContainsString('Tool Execute: {"tool":"cms_data","action":"list_pages","input_keys":["action","query"]}', $log);
        self::assertStringContainsString('Tool Result: {"tool":"cms_data","is_error":false', $log);
        self::assertStringNotContainsString('jane@example.com', $log);
        self::assertStringNotContainsString('admin_user_id', $log);
    }

    #[Test]
    public function theDebugLogStaysEmptyWhenDebugModeIsOff(): void
    {
        $this->responses = [$this->toolCallResponse('list_pages')];

        $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertSame([], $this->debugLog->getMessages());
    }

    #[Test]
    public function streamingDoesNotAnnounceADeniedCallAsRunningNorAskForConfirmation(): void
    {
        $this->responses = [
            $this->toolCallResponse('update_page', ['content' => 'x']),
            ['content' => 'Sorry, I cannot do that.', 'tool_calls' => []],
        ];
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type;
        };

        $result = $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertArrayNotHasKey('pending_confirmation', $result);
        self::assertNotContains('confirm', $events);
        self::assertNotContains('tool_status', $events);
        self::assertStringContainsString('Access denied', $this->lastMessageOfRole($this->requests[1], 'tool')['content']);
    }

    #[Test]
    public function streamingAnnouncesAnExecutedReadCall(): void
    {
        $this->responses = [$this->toolCallResponse('list_pages')];
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type . ':' . ($data['status'] ?? '');
        };

        $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertSame(['tool_status:running', 'tool_status:done'], $events);
    }

    #[Test]
    public function itAppendsTheStoreScopeToTheSystemPromptOfEveryRequest(): void
    {
        $service = $this->serviceWith($this->multiStoreManager());

        $service->processMessage([['role' => 'user', 'content' => 'Change the store name']]);

        self::assertSame('system', $this->sentMessages[0]['role']);
        self::assertStringStartsWith(self::SYSTEM_PROMPT . "\n\n[Store scope]", $this->sentMessages[0]['content']);
        self::assertStringContainsString('Store view "Luma" (id 2, code "luma")', $this->sentMessages[0]['content']);
        self::assertSame('user', $this->sentMessages[1]['role']);
    }

    #[Test]
    public function itAddsTheStoreScopeRightAfterACallerSuppliedSystemMessage(): void
    {
        $service = $this->serviceWith($this->multiStoreManager());

        $service->processMessage([
            ['role' => 'system', 'content' => 'Custom prompt'],
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        self::assertSame(['system', 'system', 'user'], array_column($this->sentMessages, 'role'));
        self::assertSame('Custom prompt', $this->sentMessages[0]['content']);
        self::assertStringStartsWith('[Store scope]', $this->sentMessages[1]['content']);
    }

    #[Test]
    public function itNeverInjectsTheStoreScopeTwice(): void
    {
        $service = $this->serviceWith($this->multiStoreManager());

        $service->processMessage([
            ['role' => 'system', 'content' => "Custom prompt\n\n[Store scope] already here"],
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        self::assertSame(['system', 'user'], array_column($this->sentMessages, 'role'));
    }

    #[Test]
    public function itTellsTheAssistantNotToAskOnASingleStoreView(): void
    {
        $service = $this->serviceWith($this->singleStoreManager());

        $service->processMessage([['role' => 'user', 'content' => 'Hi']]);

        self::assertStringContainsString(
            'never ask the user which store view to use',
            $this->sentMessages[0]['content']
        );
    }

    #[Test]
    public function itKeepsChattingWhenTheStoreLayoutCannotBeLoaded(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willThrowException(new \RuntimeException('stores table gone'));
        $errorLogger = $this->createMock(ErrorLogger::class);
        $errorLogger->expects(self::once())->method('addLog')
            ->with('StoreScopeContext', self::stringContains('stores table gone'));

        $response = $this->serviceWith($storeManager, $errorLogger)
            ->processMessage([['role' => 'user', 'content' => 'Hi']]);

        self::assertSame('ok', $response['content']);
        self::assertSame(self::SYSTEM_PROMPT, $this->sentMessages[0]['content']);
    }

    #[Test]
    public function itAppendsTheWidgetGuideAfterTheStoreScopeWhenAnswerWidgetsAreOn(): void
    {
        $service = $this->serviceWith($this->singleStoreManager(), null, true);

        $service->processMessage([['role' => 'user', 'content' => 'How did we do this week?']]);

        $content = $this->sentMessages[0]['content'];
        self::assertStringStartsWith(self::SYSTEM_PROMPT . "\n\n[Store scope]", $content);
        self::assertStringContainsString("\n\n[Answer widgets]", $content);
        self::assertStringContainsString('"type":"rankedBars"', $content);
        self::assertSame('user', $this->sentMessages[1]['role']);
    }

    #[Test]
    public function itLeavesTheWidgetGuideOutWhenAnswerWidgetsAreOff(): void
    {
        $service = $this->serviceWith($this->singleStoreManager());

        $service->processMessage([['role' => 'user', 'content' => 'Hi']]);

        self::assertStringNotContainsString('[Answer widgets]', $this->sentMessages[0]['content']);
    }

    #[Test]
    public function itNeverInjectsTheWidgetGuideTwice(): void
    {
        $service = $this->serviceWith($this->singleStoreManager(), null, true);

        $service->processMessage([
            ['role' => 'system', 'content' => "Custom prompt\n\n[Store scope] here\n\n[Answer widgets] here"],
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        self::assertSame(['system', 'user'], array_column($this->sentMessages, 'role'));
    }

    /**
     * A failing provider call puts its endpoint, key and response in the exception (#202)
     */
    #[Test]
    public function aFailedProviderCallReachesTheAdminOnlyAsAReference(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('resolve')->willReturn($this->createStub(AiClientInterface::class));
        $client->method('chat')->willThrowException(
            new \RuntimeException('HTTP 401 from https://api.example.test/v1?key=sk-live-123')
        );

        $response = $this->serviceWith($this->singleStoreManager(), null, false, $client)
            ->processMessage([['role' => 'user', 'content' => 'Hi']]);

        self::assertStringNotContainsString('sk-live-123', $response['content']);
        self::assertStringContainsString('Reference:', $response['content']);
    }

    /**
     * Resolving the service makes no request; its message is the setup step the admin has to take
     */
    #[Test]
    public function aServiceThatIsNotConfiguredIsExplainedToTheAdmin(): void
    {
        $setup = 'No AI service configured. Configure one under Stores > Configuration.';
        $client = $this->createStub(Client::class);
        $client->method('resolve')->willThrowException(new AiNotConfiguredException(__($setup)));

        $response = $this->serviceWith($this->singleStoreManager(), null, false, $client)
            ->processMessage([['role' => 'user', 'content' => 'Hi']]);

        self::assertSame($setup, $response['content']);
    }

    /**
     * The streaming path leaves a failed resolve to its controller, which reports it as an error
     * event; streamed as text it would be stored as the assistant's answer and replayed (#202)
     */
    #[Test]
    public function theStreamingPathLeavesAFailedResolveToItsController(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('resolve')->willThrowException(new \RuntimeException('SQLSTATE[HY000] connection lost'));
        $streamed = [];

        try {
            $this->serviceWith($this->singleStoreManager(), null, false, $client)->processMessageStreaming(
                [['role' => 'user', 'content' => 'Hi']],
                static function (string $event, array $data) use (&$streamed): void {
                    $streamed[] = $event;
                }
            );
            self::fail('A failed resolve has to reach the controller');
        } catch (\RuntimeException $e) {
            self::assertSame('SQLSTATE[HY000] connection lost', $e->getMessage());
        }

        self::assertSame([], $streamed);
    }

    private function serviceWith(
        StoreManagerInterface $storeManager,
        ?ErrorLogger $errorLogger = null,
        bool $answerWidgets = false,
        ?Client $client = null
    ): ChatService {
        $configRepository = $this->createMock(RepositoryInterface::class);
        $configRepository->method('getSystemPrompt')->willReturn(self::SYSTEM_PROMPT);
        $configRepository->method('getMaxToolIterations')->willReturn(1);
        $configRepository->method('isAnswerWidgetsEnabled')->willReturn($answerWidgets);

        if ($client === null) {
            $client = $this->createMock(Client::class);
            $client->method('resolve')->willReturn($this->createMock(AiClientInterface::class));
        }
        $client->method('chat')->willReturnCallback(function (AiClientInterface $aiClient, array $messages): array {
            $this->sentMessages = $messages;
            return ['content' => 'ok', 'tool_calls' => []];
        });

        return new ChatService(
            $configRepository,
            $client,
            new ToolRegistry(null, []),
            $this->createMock(DebugLogger::class),
            new ErrorReporter($errorLogger ?? $this->createMock(ErrorLogger::class), new PiiHeuristic()),
            $this->createMock(UsageLogger::class),
            new StoreScopeContext($storeManager),
            new AnswerWidgets(new ErrorLogger(new FakeLogger(), new Json())),
            new PageContextHolder(),
            $this->privacyService(),
            new ToolAccess(new FakeAuthorization(), new FakePermissionChecker())
        );
    }

    #[Test]
    public function itStagesTheFormEvenWhenTheToolResultIsTooBigToKeep(): void
    {
        $directive = ['action' => 'form_write', 'changes' => [['path' => 'description', 'value' => 'Nieuwe tekst']]];
        $service = $this->serviceWithTool((new FakeTool('page_form', ['write_fields'], []))->withResult([
            'staged' => true,
            'fields' => array_fill(0, 40, str_repeat('a', 500)),
            'client_directive' => $directive,
        ]));

        $events = [];
        $results = $service->executeConfirmedTools(
            [['id' => 'call_1', 'name' => 'page_form', 'input' => ['action' => 'write_fields']]],
            null,
            function (string $event, array $data) use (&$events): void {
                $events[] = [$event, $data];
            }
        );

        self::assertContains(['form_apply', $directive], $events);
        self::assertTrue($results['call_1']['_truncated']);
        self::assertArrayNotHasKey('client_directive', $results['call_1']);
    }

    #[Test]
    public function itDoesNotSpendTheResponseBudgetOnWhatOnlyTheBrowserSees(): void
    {
        $directive = ['action' => 'form_write', 'changes' => array_fill(0, 40, [
            'path' => 'description',
            'value' => str_repeat('a', 500),
        ])];
        $service = $this->serviceWithTool((new FakeTool('page_form', ['write_fields'], []))->withResult([
            'staged' => true,
            'client_directive' => $directive,
        ]));

        $results = $service->executeConfirmedTools(
            [['id' => 'call_1', 'name' => 'page_form', 'input' => ['action' => 'write_fields']]],
            null,
            function (string $event, array $data): void {
            }
        );

        self::assertSame(['staged' => true], $results['call_1']);
    }

    #[Test]
    public function itSendsTheBrowserTheDirectiveUnfilteredByThePrivacyClassification(): void
    {
        $directive = [
            'type' => 'form_navigate',
            'target' => ['namespace' => 'product_form', 'entity_id' => '3754', 'store_id' => '', 'is_new' => false],
            'url' => 'https://shop.test/admin/catalog/product/edit/id/3754/key/abc123/',
            'changes' => [['path' => 'data.product.description', 'value' => 'Mail jan@example.com for sizes']],
        ];
        $service = $this->serviceWithTool($this->pageFormTool([
            'staged' => true,
            'entity_id' => '3754',
            'client_directive' => $directive,
        ]));

        $events = [];
        $service->executeConfirmedTools(
            [['id' => 'call_1', 'name' => 'page_form', 'input' => ['action' => 'write_fields']]],
            null,
            function (string $event, array $data) use (&$events): void {
                $events[] = [$event, $data];
            }
        );

        self::assertContains(['form_apply', $directive], $events);
    }

    #[Test]
    public function itStillTokenisesTheEntityIdInTheResultTheModelSees(): void
    {
        $service = $this->serviceWithTool($this->pageFormTool([
            'staged' => true,
            'entity_id' => '3754',
            'client_directive' => ['type' => 'form_write', 'target' => ['entity_id' => '3754']],
        ]));

        $results = $service->executeConfirmedTools(
            [['id' => 'call_1', 'name' => 'page_form', 'input' => ['action' => 'write_fields']]],
            null,
            function (string $event, array $data): void {
            }
        );

        self::assertSame(['staged' => true, 'entity_id' => 'mago://entity_1'], $results['call_1']);
    }

    #[Test]
    public function itKeepsTheDirectiveOutOfTheToolMessageWhenNotStreaming(): void
    {
        $this->grants['page_form'] = 'read';
        $auth = $this->createMock(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturn(true);
        $pageForm = new FakeSkill('page_form', $auth, [
            'describe_form' => new FakeAction('describe_form', true, [], '', [
                'staged' => true,
                'client_directive' => ['type' => 'form_write', 'target' => ['entity_id' => '3754']],
            ]),
        ]);
        $this->responses = [[
            'content' => '',
            'tool_calls' => [['id' => 'call_1', 'name' => 'page_form', 'input' => ['action' => 'describe_form']]],
        ]];

        $this->buildChatService([$pageForm])->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertSame('{"staged":true}', $this->lastMessageOfRole($this->requests[1], 'tool')['content']);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function pageFormTool(array $result): FakeTool
    {
        return (new FakeTool('page_form', ['write_fields'], []))
            ->withResult($result)
            ->withFieldClassification([
                'client_directive' => [PiiClass::PUBLIC],
                'staged' => [PiiClass::PUBLIC],
                'target' => [PiiClass::PUBLIC],
                'namespace' => [PiiClass::PUBLIC],
                'store_id' => [PiiClass::PUBLIC],
                'is_new' => [PiiClass::PUBLIC],
                'type' => [PiiClass::PUBLIC],
                'changes' => [PiiClass::PUBLIC],
                'path' => [PiiClass::PUBLIC],
                'value' => [PiiClass::PUBLIC],
                'entity_id' => [PiiClass::TOKENISE, 'entity'],
                'url' => [PiiClass::TOKENISE, 'url'],
            ]);
    }

    #[Test]
    public function aToolFromAnotherModuleWritesItsOwnStatusLine(): void
    {
        $service = $this->serviceWithAnyTool(new FakePresentableTool('stock_alerts', 'Stock alerts', 'Checking %s...'));
        $messages = [];

        $service->executeConfirmedTools(
            [['id' => 'call_1', 'name' => 'stock_alerts', 'input' => ['action' => 'low_stock']]],
            null,
            function (string $event, array $data) use (&$messages): void {
                if ($event === 'tool_status' && $data['status'] === 'running') {
                    $messages[] = $data['message'];
                }
            }
        );

        self::assertSame(['Checking low_stock...'], $messages);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function missingStatusLines(): array
    {
        return [
            'no status line' => [null],
            'an empty status line' => [''],
        ];
    }

    #[Test]
    #[DataProvider('missingStatusLines')]
    public function aToolWithoutItsOwnStatusLineKeepsTheDefault(?string $statusMessage): void
    {
        $service = $this->serviceWithAnyTool(new FakePresentableTool('stock_alerts', 'Stock alerts', $statusMessage));
        $messages = [];

        $service->executeConfirmedTools(
            [['id' => 'call_1', 'name' => 'stock_alerts', 'input' => ['action' => 'low_stock']]],
            null,
            function (string $event, array $data) use (&$messages): void {
                if ($event === 'tool_status' && $data['status'] === 'running') {
                    $messages[] = $data['message'];
                }
            }
        );

        self::assertSame(['Running stock_alerts...'], $messages);
    }

    #[Test]
    public function itAppendsAnEntityEditLinkToTheAnswerAfterAConfirmedWrite(): void
    {
        $this->grants = ['cms_data' => 'read', 'widgets' => 'write'];
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);
        $widgets = new FakeSkill('widgets', $authorization, [
            'make_thing' => new FakeAction('make_thing', false, [], '', [
                'success' => true,
                'message' => 'Thing created',
                '_links' => [['label' => 'Edit Thing', 'url' => 'https://shop.test/admin/x/edit/id/2/key/abc/']],
            ]),
        ]);
        $service = $this->buildChatService([$widgets]);

        // The confirmed write hands its link to the server; the model's copy of the result loses it.
        $results = $service->executeConfirmedTools(
            [['id' => 'call_1', 'name' => 'widgets', 'input' => ['action' => 'make_thing']]],
            self::ADMIN_ID
        );
        self::assertArrayNotHasKey('_links', $results['call_1']);

        // The answer that follows carries the link, appended by the server rather than the model.
        $this->responses = [['content' => 'Thing created.', 'tool_calls' => []]];
        $result = $service->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertStringContainsString(
            '[Edit Thing](https://shop.test/admin/x/edit/id/2/key/abc/)',
            $result['content']
        );
    }

    #[Test]
    public function itDoesNotAppendALinkForAReadResult(): void
    {
        $this->grants = ['cms_data' => 'read', 'widgets' => 'write'];
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);
        $widgets = new FakeSkill('widgets', $authorization, [
            'peek' => new FakeAction('peek', true, [], '', [
                '_links' => [['label' => 'Edit Thing', 'url' => 'https://shop.test/admin/x/edit/id/2/key/abc/']],
            ]),
        ]);
        $service = $this->buildChatService([$widgets]);

        $this->responses = [
            ['content' => '', 'tool_calls' => [['id' => 'r1', 'name' => 'widgets', 'input' => ['action' => 'peek']]]],
            ['content' => 'Here you go.', 'tool_calls' => []],
        ];
        $result = $service->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertStringNotContainsString('https://shop.test/admin', $result['content']);
    }

    private function serviceWithTool(FakeTool $tool): ChatService
    {
        return $this->serviceWithAnyTool($tool);
    }

    private function serviceWithAnyTool(ToolInterface $tool): ChatService
    {
        return new ChatService(
            (new FakeConfigRepository())->withMaxResponseTokens(self::MAX_RESPONSE_TOKENS),
            $this->createMock(Client::class),
            new ToolRegistry(null, [$tool]),
            $this->createMock(DebugLogger::class),
            new ErrorReporter($this->createMock(ErrorLogger::class), new PiiHeuristic()),
            $this->createMock(UsageLogger::class),
            new StoreScopeContext($this->createMock(StoreManagerInterface::class)),
            new AnswerWidgets(new ErrorLogger(new FakeLogger(), new Json())),
            new PageContextHolder(),
            $this->privacyService(),
            new ToolAccess(new FakeAuthorization(), new FakePermissionChecker())
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    #[Test]
    public function itKeepsCustomerPiiOutOfThePayloadSentToTheProvider(): void
    {
        $this->grants['customer_data'] = 'read';
        $auth = $this->createMock(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturn(true);
        $customerData = new FakeSkill('customer_data', $auth, [
            'lookup_customer' => new FakeAction('lookup_customer', true, [], '', [
                'results' => [[
                    'entity_id' => 42,
                    'name' => 'Jan Jansen',
                    'email' => 'jan@example.com',
                    'city' => 'Amsterdam',
                    'telephone' => '0612345678',
                    'admin_url' => 'https://shop.test/admin/customer/index/edit/id/42/key/abc123secret/',
                ]],
            ], [
                'entity_id' => [PiiClass::TOKENISE, 'customer'],
                'name' => [PiiClass::STRIP],
                'email' => [PiiClass::STRIP],
                'telephone' => [PiiClass::STRIP],
                'city' => [PiiClass::PUBLIC],
            ]),
        ]);
        $service = $this->buildChatService([$customerData]);
        $this->responses = [[
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_1',
                'name' => 'customer_data',
                'input' => ['action' => 'lookup_customer', 'search' => 'Jan'],
            ]],
        ]];

        $service->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        $toolMessage = (string)$this->lastMessageOfRole($this->requests[1], 'tool')['content'];
        self::assertStringNotContainsString('Jan Jansen', $toolMessage);
        self::assertStringNotContainsString('jan@example.com', $toolMessage);
        self::assertStringNotContainsString('0612345678', $toolMessage);
        self::assertStringNotContainsString('abc123secret', $toolMessage);
        self::assertStringContainsString('mago://customer_1', $toolMessage);
        self::assertStringContainsString('Amsterdam', $toolMessage);
    }

    #[Test]
    public function itRehydratesAConfirmedPersonalValueIntoTheWrite(): void
    {
        $this->grants['cms_data'] = 'write';
        $echo = new class implements \MagoAssistant\Mago\Api\Skill\ActionInterface {
            /** @var array<string, mixed>|null The params the write actually ran with */
            public ?array $received = null;
            public function getName(): string
            {
                return 'update_page';
            }
            public function getDescription(): string
            {
                return 'update';
            }
            public function getParameterSchema(): array
            {
                return [];
            }
            public function getAclResource(): ?string
            {
                return null;
            }
            public function isReadOnly(): bool
            {
                return false;
            }
            public function execute(array $params, int $adminUserId): array
            {
                $this->received = $params;
                return ['updated' => true];
            }
            public function getInstructions(): string
            {
                return '';
            }
            public function getFieldClassification(): array
            {
                return ['updated' => [PiiClass::PUBLIC]];
            }
        };
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);
        $service = $this->buildChatService([new FakeSkill('page_writer', $authorization, ['update_page' => $echo])]);
        $this->grants['page_writer'] = 'write';

        // Turn 1 mints mago://email_1 into the service's vault via the input scrubber.
        $this->responses = [['content' => 'noted', 'tool_calls' => []]];
        $service->processMessage(
            [['role' => 'user', 'content' => 'Use jan@example.com on the contact page']],
            null,
            self::ADMIN_ID
        );

        $results = $service->executeConfirmedTools([[
            'id' => 'call_1',
            'name' => 'page_writer',
            'input' => ['action' => 'update_page', 'content' => 'Contact: mago://email_1'],
        ]], self::ADMIN_ID);

        // #114: a personal value the admin approved on the card (shown there in plain text, with a
        // warning) is written as itself, not refused and not as its token.
        self::assertArrayNotHasKey('error', $results['call_1']);
        self::assertSame('Contact: jan@example.com', $echo->received['content'] ?? null);
    }

    #[Test]
    public function itRehydratesAnIdTokenIntoAConfirmedWriteOnAColdRequest(): void
    {
        // Shared storage, two separate PrivacyService instances: turn N mints the token, the
        // confirm arrives as a fresh request whose vault must be re-bound via $conversationId.
        $storage = new class implements \MagoAssistant\Mago\Api\Privacy\VaultStorageInterface {
            /** @var array<int,array<int,array{token:string,value:string,type:string}>> */
            public array $rows = [];
            public function loadForConversation(int $conversationId): array
            {
                return $this->rows[$conversationId] ?? [];
            }
            public function persist(int $conversationId, string $token, string $value, string $type): void
            {
                $this->rows[$conversationId][] = ['token' => $token, 'value' => $value, 'type' => $type];
            }
        };

        $warmVault = new ConversationVault($storage);
        $warmVault->beginConversation(7);
        self::assertSame('mago://order_1', $warmVault->tokenise('000000549', 'order'));

        $this->grants['cms_data'] = 'write';
        $echo = new class implements \MagoAssistant\Mago\Api\Skill\ActionInterface {
            /** @var array<string, mixed>|null */
            public ?array $received = null;
            public function getName(): string
            {
                return 'update_page';
            }
            public function getDescription(): string
            {
                return 'update';
            }
            public function getParameterSchema(): array
            {
                return [];
            }
            public function getAclResource(): ?string
            {
                return null;
            }
            public function isReadOnly(): bool
            {
                return false;
            }
            public function execute(array $params, int $adminUserId): array
            {
                $this->received = $params;
                return ['updated' => true];
            }
            public function getInstructions(): string
            {
                return '';
            }
            public function getFieldClassification(): array
            {
                return ['updated' => [PiiClass::PUBLIC]];
            }
        };
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);
        $coldService = $this->buildChatService(
            [new FakeSkill('page_writer', $authorization, ['update_page' => $echo])],
            $this->privacyService(new ConversationVault($storage))
        );
        $this->grants['page_writer'] = 'write';

        $results = $coldService->executeConfirmedTools([[
            'id' => 'call_1',
            'name' => 'page_writer',
            'input' => ['action' => 'update_page', 'comment' => 'Note for mago://order_1'],
        ]], self::ADMIN_ID, null, null, 7);

        self::assertSame(['updated' => true], $results['call_1']);
        self::assertIsArray($echo->received);
        self::assertSame('Note for 000000549', $echo->received['comment']);
    }

    #[Test]
    public function itRefusesAConfirmedWriteCarryingAnUnresolvableToken(): void
    {
        $this->grants['cms_data'] = 'write';
        $service = $this->buildChatService();

        $results = $service->executeConfirmedTools([[
            'id' => 'call_1',
            'name' => 'cms_data',
            'input' => ['action' => 'update_page', 'content' => 'Ship to mago://customer_99'],
        ]], self::ADMIN_ID);

        self::assertArrayHasKey('error', $results['call_1']);
        self::assertStringContainsString('masked for privacy', (string)$results['call_1']['error']);
    }

    #[Test]
    public function itRefusesToCopyCustomerWrittenTextIntoAWrite(): void
    {
        $this->grants['cms_data'] = 'write';
        $vault = new ConversationVault();
        $review = $vault->tokenise('Best chair ever <img src=x onerror=alert(1)>', 'reviewtext');
        $service = $this->buildChatService([], $this->privacyService($vault));

        $results = $service->executeConfirmedTools([[
            'id' => 'call_1',
            'name' => 'cms_data',
            'input' => ['action' => 'update_page', 'content' => 'Our customers say: ' . $review],
        ]], self::ADMIN_ID);

        self::assertArrayHasKey('error', $results['call_1']);
        self::assertStringContainsString('text a customer wrote', (string)$results['call_1']['error']);
    }

    #[Test]
    public function itRefusesToCopyAReviewersNicknameIntoAWrite(): void
    {
        $this->grants['cms_data'] = 'write';
        $vault = new ConversationVault();
        $nickname = $vault->tokenise('Jan', 'nickname');
        $service = $this->buildChatService([], $this->privacyService($vault));

        $results = $service->executeConfirmedTools([[
            'id' => 'call_1',
            'name' => 'cms_data',
            'input' => ['action' => 'update_page', 'content' => 'Thanks ' . $nickname],
        ]], self::ADMIN_ID);

        self::assertStringContainsString('text a customer wrote', (string)($results['call_1']['error'] ?? ''));
    }

    #[Test]
    public function confirmationFlagsAWriteCarryingAnyMaskedValueButAnAdminUrl(): void
    {
        $this->grants = ['cms_data' => 'write'];
        $service = $this->buildChatService();
        $this->responses = [[
            'content' => '',
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'About mago://order_1']],
                ['id' => 'call_2', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'Shop in mago://city_1']],
                ['id' => 'call_3', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'See mago://url_1']],
            ],
        ]];
        $confirm = null;
        $onChunk = static function (string $type, array $data) use (&$confirm): void {
            if ($type === 'confirm') {
                $confirm = $data;
            }
        };

        $service->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertTrue($confirm['tools'][0]['sensitive']);
        self::assertTrue($confirm['tools'][1]['sensitive']);
        self::assertArrayNotHasKey('sensitive', $confirm['tools'][2]);
    }

    #[Test]
    public function itStreamsTheAnswerWithItsTokensIntactAndTheirValuesBeside(): void
    {
        $vault = new ConversationVault();
        $review = $vault->tokenise('**bold** [click](https://evil.example)', 'reviewtext');
        $url = $vault->tokenise('https://shop.test/admin/review/product/edit/id/3/key/abc/', 'url');
        $service = $this->buildChatService([], $this->privacyService($vault));
        $this->responses = [[
            'content' => '',
            'tool_calls' => [],
            'streamed' => ['The review says ', substr($review, 0, 9), substr($review, 9) . ', [open it](' . $url . ').'],
        ]];
        $texts = [];
        $onChunk = static function (string $type, array $data) use (&$texts): void {
            if ($type === 'text') {
                $texts[] = $data;
            }
        };

        $service->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        $streamed = implode('', array_column($texts, 'text'));
        $tokens = array_merge(...array_map(static fn (array $text): array => $text['tokens'] ?? [], $texts));
        self::assertSame('The review says ' . $review . ', [open it](' . $url . ').', $streamed);
        self::assertSame([
            $review => '**bold** [click](https://evil.example)',
            $url => 'https://shop.test/admin/review/product/edit/id/3/key/abc/',
        ], $tokens);
    }

    #[Test]
    public function itStreamsATextDeltaWithoutTokensAsBefore(): void
    {
        $this->responses = [['content' => '', 'tool_calls' => [], 'streamed' => ['Nothing masked.']]];
        $texts = [];
        $onChunk = static function (string $type, array $data) use (&$texts): void {
            if ($type === 'text') {
                $texts[] = $data;
            }
        };

        $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertSame([['text' => 'Nothing masked.']], $texts);
    }

    #[Test]
    public function streamingRePresentsAnAnswerThatDumpedRawJson(): void
    {
        $this->responses = [
            ['content' => 'Here is the last order: {"type":"record","title":"Order #2",'
                . '"rows":[{"label":"Total","value":"€39.64"}]}', 'tool_calls' => []],
            ['content' => 'The last order is #2 for €39.64.', 'tool_calls' => []],
        ];
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type;
        };

        $result = $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertContains('replace', $events, 'the panel is told to drop the raw JSON it streamed');
        self::assertSame('The last order is #2 for €39.64.', $result['content'], 'the clean re-presentation is returned');
        self::assertCount(2, $this->requests, 'the model was re-prompted once');
        self::assertStringContainsString('raw structured data', $this->lastMessageOfRole($this->requests[1], 'user')['content']);
    }

    #[Test]
    public function streamingRePresentsAnAnswerThatDumpedHeaderlessXml(): void
    {
        $this->responses = [
            ['content' => 'The config is <config><item>a</item><item>b</item></config>', 'tool_calls' => []],
            ['content' => 'The config holds two items: a and b.', 'tool_calls' => []],
        ];
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type;
        };

        $result = $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertContains('replace', $events, 'xml-shaped output with no <?xml header is still caught');
        self::assertSame('The config holds two items: a and b.', $result['content']);
        self::assertCount(2, $this->requests);
    }

    /**
     * Validation can reach the external service of a per-user tool, so an ungranted call is answered
     * with its denial and the tool is never asked to validate it.
     */
    #[Test]
    public function anUngrantedPerUserWriteIsDeniedWithoutBeingValidated(): void
    {
        $this->grants = ['cms_data' => 'write', 'issue_tracker' => 'write'];
        $tool = new FakeValidatingTool('issue_tracker', ['known'], Acl::MAGO_PER_USER);
        $service = $this->buildChatService([$tool]);
        $this->responses = [[
            'content' => '',
            'tool_calls' => [['id' => 'call_1', 'name' => 'issue_tracker', 'input' => ['args' => ['unknown']]]],
        ]];

        $service->processMessageStreaming([$this->userMessage()], static function (): void {
        }, null, self::ADMIN_ID);

        self::assertSame(0, $tool->getRefusalChecks());
        self::assertStringContainsString('you have not been given it', (string)json_encode($this->requests[1]));
    }

    #[Test]
    public function aCleanProseAnswerIsNotRePresented(): void
    {
        $this->responses = [['content' => 'The last order is #2 for €39.64.', 'tool_calls' => []]];
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type;
        };

        $result = $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertNotContains('replace', $events);
        self::assertCount(1, $this->requests);
        self::assertSame('The last order is #2 for €39.64.', $result['content']);
    }

    #[Test]
    public function aProperlyFencedWidgetIsNotRePresented(): void
    {
        $this->responses = [['content' => "Here is the order:\n```mago\n"
            . '{"type":"record","title":"Order #2","rows":[{"label":"Total","value":"€39.64"}]}'
            . "\n```", 'tool_calls' => []]];
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type;
        };

        $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertNotContains('replace', $events, 'a correctly fenced widget is intentional, not a leak');
        self::assertCount(1, $this->requests);
    }

    #[Test]
    public function nonStreamingRePresentsAnAnswerThatDumpedRawJson(): void
    {
        $this->responses = [
            ['content' => 'Order: {"type":"record","title":"Order #2",'
                . '"rows":[{"label":"Total","value":"€39.64"}]}', 'tool_calls' => []],
            ['content' => 'The last order is #2 for €39.64.', 'tool_calls' => []],
        ];

        $result = $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertSame('The last order is #2 for €39.64.', $result['content']);
        self::assertCount(2, $this->requests);
        self::assertStringContainsString('raw structured data', $this->lastMessageOfRole($this->requests[1], 'user')['content']);
    }

    #[Test]
    public function itRePromptsAtMostOnceEvenWhenTheModelKeepsDumpingRawData(): void
    {
        $raw = 'Order: {"type":"record","title":"Order #2","rows":[{"label":"Total","value":"€39.64"}]}';
        $this->responses = [
            ['content' => $raw, 'tool_calls' => []],
            ['content' => $raw, 'tool_calls' => []],
        ];
        $replaces = 0;
        $onChunk = static function (string $type, array $data) use (&$replaces): void {
            if ($type === 'replace') {
                $replaces++;
            }
        };

        $result = $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertSame(1, $replaces, 're-presented once, then the second answer is accepted rather than looping');
        self::assertCount(2, $this->requests);
        self::assertSame($raw, $result['content']);
    }

    #[Test]
    public function itTellsThePanelToReplaceBetweenTheRawAnswerAndTheRePresentedOne(): void
    {
        $raw = 'Order: {"title":"Order #2","total":"€39.64"}';
        $clean = 'The last order is #2 for €39.64.';
        $this->responses = [
            ['content' => $raw, 'tool_calls' => [], 'streamed' => [$raw]],
            ['content' => $clean, 'tool_calls' => [], 'streamed' => [$clean]],
        ];
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type . ':' . ($data['text'] ?? '');
        };

        $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertSame(['text:' . $raw, 'replace:', 'text:' . $clean], $events);
    }

    #[Test]
    public function itKeepsTheExecutedToolCallsWhenTheAnswerAfterThemIsRePresented(): void
    {
        $this->responses = [
            $this->toolCallResponse('list_pages'),
            ['content' => 'Pages: [{"id":1,"title":"Home"},{"id":2,"title":"About"}]', 'tool_calls' => []],
            ['content' => 'There are two pages: Home and About.', 'tool_calls' => []],
        ];

        $result = $this->chatService->processMessageStreaming([$this->userMessage()], static function (): void {
        }, null, self::ADMIN_ID);

        self::assertSame('There are two pages: Home and About.', $result['content']);
        self::assertSame(['call_1'], array_column($result['executed_tool_calls'], 'id'));
        self::assertCount(3, $this->requests);
    }

    #[Test]
    public function itDoesNotRePresentJsonTheModelPutInInlineCode(): void
    {
        $this->responses = [['content' => 'Send `{"sku":"24-MB01","qty":2}` as the request body.', 'tool_calls' => []]];

        $result = $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertCount(1, $this->requests);
        self::assertSame('Send `{"sku":"24-MB01","qty":2}` as the request body.', $result['content']);
    }

    #[Test]
    public function itDoesNotRePresentASingleMemberObject(): void
    {
        $this->responses = [['content' => 'The total is {"total":"€39"}.', 'tool_calls' => []]];

        $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertCount(1, $this->requests);
    }

    #[Test]
    public function itRePresentsAnObjectWithTwoMembers(): void
    {
        $this->responses = [
            ['content' => 'The totals are {"total":"€39","tax":"€7"}.', 'tool_calls' => []],
            ['content' => 'The total is €39, of which €7 is tax.', 'tool_calls' => []],
        ];

        $result = $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertCount(2, $this->requests);
        self::assertSame('The total is €39, of which €7 is tax.', $result['content']);
    }

    #[Test]
    public function itDoesNotRePresentBracesThatAreNotValidJson(): void
    {
        $this->responses = [
            ['content' => 'Fill in {name: your name, email: your email} and [first, second].', 'tool_calls' => []],
        ];

        $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertCount(1, $this->requests);
    }

    #[Test]
    public function itRePresentsAnAnswerThatStartsWithAnXmlDeclaration(): void
    {
        $this->responses = [
            ['content' => '<?xml version="1.0"?><config/>', 'tool_calls' => []],
            ['content' => 'The config file is empty.', 'tool_calls' => []],
        ];

        $result = $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertCount(2, $this->requests);
        self::assertSame('The config file is empty.', $result['content']);
    }

    private function toolCallResponse(string $action, array $input = []): array
    {
        return [
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_1',
                'name' => 'cms_data',
                'input' => ['action' => $action] + $input,
            ]],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function userMessage(): array
    {
        return ['role' => 'user', 'content' => 'Update the home page'];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function lastMessageOfRole(array $messages, string $role): array
    {
        $matching = array_values(array_filter($messages, static fn (array $m): bool => ($m['role'] ?? '') === $role));
        self::assertNotEmpty($matching, "No message with role {$role}");

        return end($matching);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>|null
     */
    private function instructionMessage(array $messages): ?array
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system' && str_starts_with($message['content'], '[Instructions for')) {
                return $message;
            }
        }

        return null;
    }
}
