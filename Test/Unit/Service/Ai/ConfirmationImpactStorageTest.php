<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Ai;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Acl\ToolAccess;
use MagoAssistant\Mago\Service\Ai\AnswerWidgets;
use MagoAssistant\Mago\Service\Ai\ChatService;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Service\Tool\ToolCallGate;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Test\Unit\Fakes\BuildsStoreLayouts;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeChatClient;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeOrderNamingIrreversibleAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePermissionChecker;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeUsageLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Security audit 2026-11-02, finding 2: the tool calls of a pending confirmation are what the
 * caller stores in mago_message.tool_calls. Their input stays tokenised (#97 decision 1), but the
 * impact lines are built from the rehydrated input and stored with the real value in them.
 */
final class ConfirmationImpactStorageTest extends TestCase
{
    use BuildsStoreLayouts;

    private const ADMIN_ID = 7;

    private const ORDER_NUMBER = '000000549';

    private FakeChatClient $client;

    private ConversationVault $vault;

    protected function setUp(): void
    {
        $this->client = new FakeChatClient();
        $this->vault = new ConversationVault();
    }

    #[Test]
    public function theCardShowsTheRealOrderNumberInItsImpactLines(): void
    {
        $confirm = [];

        $this->chatService()->prepareToolConfirmation(
            [$this->cancelCall($this->vault->tokenise(self::ORDER_NUMBER, 'order'))],
            static function (string $type, array $data) use (&$confirm): void {
                $confirm = $data;
            },
            self::ADMIN_ID
        );

        self::assertSame(
            ['Order #' . self::ORDER_NUMBER . ' is canceled and cannot be reopened.'],
            $confirm['tools'][0]['impacts']
        );
    }

    #[Test]
    public function theStoredCallsOfAModelProposedWriteDoNotCarryTheRealOrderNumber(): void
    {
        $token = $this->vault->tokenise(self::ORDER_NUMBER, 'order');
        $proposal = ['content' => '', 'tool_calls' => [$this->cancelCall($token)]];
        $this->client->withTurn($proposal)->withTurn($proposal);

        $result = $this->chatService()->processMessage(
            [['role' => 'user', 'content' => 'Cancel that order']],
            null,
            self::ADMIN_ID
        );

        self::assertTrue($result['pending_confirmation'] ?? false);
        self::assertSame($token, $result['tool_calls'][0]['input']['order_number']);
        self::assertSame(
            ['Order #' . $token . ' is canceled and cannot be reopened.'],
            $result['tool_calls'][0]['impacts'],
            'The stored call must keep its impact lines, concealed to tokens, for the reload path'
        );
        self::assertStringNotContainsString(
            self::ORDER_NUMBER,
            (new Json())->serialize($result['tool_calls']),
            'The tool calls stored in mago_message.tool_calls carry the real order number'
        );
    }

    #[Test]
    public function theStoredCallsOfATypedWriteDoNotCarryTheRealOrderNumber(): void
    {
        $token = $this->vault->tokenise(self::ORDER_NUMBER, 'order');

        $result = $this->chatService()->prepareToolConfirmation([$this->cancelCall($token)], null, self::ADMIN_ID);

        self::assertSame($token, $result['tool_calls'][0]['input']['order_number']);
        self::assertSame(
            ['Order #' . $token . ' is canceled and cannot be reopened.'],
            $result['tool_calls'][0]['impacts'],
            'The stored call must keep its impact lines, concealed to tokens, for the reload path'
        );
        self::assertStringNotContainsString(
            self::ORDER_NUMBER,
            (new Json())->serialize($result['tool_calls']),
            'The tool calls stored in mago_message.tool_calls carry the real order number'
        );
    }

    /**
     * @return array{id: string, name: string, input: array<string, string>}
     */
    private function cancelCall(string $orderNumber): array
    {
        return [
            'id' => 'call_1',
            'name' => 'order_manager',
            'input' => ['action' => 'cancel', 'order_number' => $orderNumber],
        ];
    }

    private function chatService(): ChatService
    {
        $authorization = new FakeAuthorization();
        $permissions = (new FakePermissionChecker())->withDecision('order_manager', 'write', true);
        $json = new Json();
        $toolRegistry = new ToolRegistry($permissions, [
            new FakeSkill('order_manager', $authorization, [
                'cancel' => new FakeOrderNamingIrreversibleAction('cancel'),
            ]),
        ]);

        return new ChatService(
            (new FakeConfigRepository())->withMaxToolIterations(5)->withMaxResponseTokens(4000),
            $this->client,
            $toolRegistry,
            new DebugLogger(new FakeLogger(), $json, new FakeConfigRepository()),
            new ErrorReporter(new ErrorLogger(new FakeLogger(), $json), new PiiHeuristic()),
            new FakeUsageLogger(),
            new StoreScopeContext($this->singleStoreManager()),
            new AnswerWidgets(new ErrorLogger(new FakeLogger(), $json)),
            new PageContextHolder(),
            new PrivacyService(new PrivacyFilter($this->vault, new PiiHeuristic()), $this->vault, new PiiHeuristic()),
            new ToolCallGate($toolRegistry, new ToolAccess($authorization, $permissions)),
            new ErrorLogger(new FakeLogger(), $json)
        );
    }
}
