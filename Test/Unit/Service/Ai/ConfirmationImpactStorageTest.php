<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Ai;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Ai\AnswerWidgets;
use MagoAssistant\Mago\Service\Ai\ChatService;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CancelAction;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\OrderResolver;
use MagoAssistant\Mago\Service\Acl\ToolAccess;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Service\Tool\ToolCallGate;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\BuildsStoreLayouts;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeChatClient;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePermissionChecker;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeUsageLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Privacy mode decision 1 (#97): mago_message holds tokens, never the values behind them. The
 * confirmation card shows the real order number, but the call that is stored for it must not.
 */
final class ConfirmationImpactStorageTest extends TestCase
{
    use BuildsStoreLayouts;

    private const ADMIN_ID = 7;
    private const ORDER_NUMBER = '000000549';

    private ConversationVault $vault;

    protected function setUp(): void
    {
        $this->vault = new ConversationVault();
    }

    #[Test]
    public function theCardShowsTheRealOrderNumberInItsImpacts(): void
    {
        $shown = [];

        $this->chatService()->prepareToolConfirmation(
            [$this->cancelCallForMaskedOrder()],
            static function (string $type, array $data) use (&$shown): void {
                $shown = $data['tools'];
            },
            self::ADMIN_ID
        );

        self::assertStringContainsString('Order #' . self::ORDER_NUMBER, $shown[0]['impacts'][0]);
    }

    #[Test]
    public function theStoredCallOfATypedWriteDoesNotCarryTheRealOrderNumber(): void
    {
        $result = $this->chatService()->prepareToolConfirmation(
            [$this->cancelCallForMaskedOrder()],
            null,
            self::ADMIN_ID
        );

        $this->assertStoredWithoutTheOrderNumber($result['tool_calls']);
    }

    #[Test]
    public function theStoredCallOfAWriteTheModelProposedDoesNotCarryTheRealOrderNumber(): void
    {
        $proposal = ['content' => '', 'tool_calls' => [$this->cancelCallForMaskedOrder()]];
        $client = (new FakeChatClient())->withTurn($proposal)->withTurn($proposal);

        $result = $this->chatService($client)->processMessageStreaming(
            [['role' => 'user', 'content' => 'Cancel that order']],
            static function (): void {
            },
            null,
            self::ADMIN_ID
        );

        self::assertTrue($result['pending_confirmation'] ?? false);
        $this->assertStoredWithoutTheOrderNumber($result['tool_calls']);
    }

    /**
     * Serialised the way Model\Conversation\Repository::addMessage() writes mago_message.tool_calls
     *
     * @param array<int, array<string, mixed>> $toolCalls
     */
    private function assertStoredWithoutTheOrderNumber(array $toolCalls): void
    {
        self::assertStringNotContainsString(
            self::ORDER_NUMBER,
            (string)(new Json())->serialize($toolCalls),
            'The tool_calls stored in mago_message carry the real order number behind the token'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelCallForMaskedOrder(): array
    {
        return [
            'id' => 'call_1',
            'name' => 'order_manager',
            'input' => [
                'action' => 'cancel',
                'order_number' => $this->vault->tokenise(self::ORDER_NUMBER, 'order'),
            ],
        ];
    }

    private function chatService(?FakeChatClient $client = null): ChatService
    {
        $authorization = new FakeAuthorization();
        $permissions = (new FakePermissionChecker())
            ->withDecision('order_manager', 'read', true)
            ->withDecision('order_manager', 'write', true);
        $apiClient = (new FakeInternalApiClient())->withResponseForEvery(
            FakeInternalApiClient::GET,
            ['items' => [['entity_id' => 12, 'status' => 'pending', 'increment_id' => self::ORDER_NUMBER]]]
        );
        $toolRegistry = new ToolRegistry($permissions, [
            new FakeSkill('order_manager', $authorization, [
                'cancel' => new CancelAction(
                    $apiClient,
                    new SecureAdminUrl(new FakeBackendUrl()),
                    new OrderResolver($apiClient)
                ),
            ]),
        ]);
        $json = new Json();

        return new ChatService(
            (new FakeConfigRepository())->withMaxToolIterations(5)->withMaxResponseTokens(4000),
            $client ?? new FakeChatClient(),
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
