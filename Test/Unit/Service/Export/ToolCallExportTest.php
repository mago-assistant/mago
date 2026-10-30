<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Export;

use MagoAssistant\Mago\Service\Acl\ToolAccess;
use MagoAssistant\Mago\Service\Export\CsvWriter;
use MagoAssistant\Mago\Service\Export\ExportFile;
use MagoAssistant\Mago\Service\Export\ExportRefusedException;
use MagoAssistant\Mago\Service\Export\ToolCallExport;
use MagoAssistant\Mago\Service\Export\XlsxWriter;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;
use MagoAssistant\Mago\Service\Skills\ResultLimit;
use MagoAssistant\Mago\Service\Tool\ToolCallGate;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConversationRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeExportableAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeExportableTool;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePermissionChecker;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #298: downloading the full result of a read-only tool call from the chat. The stored call
 * runs again, through the same permission and ACL checks as in the chat, without the page limit.
 */
final class ToolCallExportTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const OTHER_ADMIN_USER_ID = 8;
    private const CONVERSATION_ID = 1;
    private const SKILL_ACL = 'Magento_Catalog::products';

    private FakeExportableAction $action;

    private FakeExportableTool $stockTool;

    private ConversationVault $vault;

    /** @var list<string> */
    private array $allowedResources = [self::SKILL_ACL];

    private FakePermissionChecker $permissions;

    protected function setUp(): void
    {
        $this->action = new FakeExportableAction('low_stock', [
            ['sku' => 'MH01', 'qty' => 0],
            ['sku' => 'MS04', 'qty' => 2],
        ]);
        $this->stockTool = new FakeExportableTool('stock_tool', ['list'], [['sku' => 'MH01']], self::SKILL_ACL);
        $this->vault = new ConversationVault();
        $this->permissions = (new FakePermissionChecker())
            ->withDecision('product_data', 'read', true)
            ->withDecision('stock_tool', 'read', true)
            ->withDecision('stock_tool', 'write', true);
    }

    #[Test]
    public function itRunsTheStoredCallAgainAndWritesEveryRowAsCsv(): void
    {
        $file = $this->export($this->conversation([$this->call('call_1')]), 'call_1', 'csv');

        self::assertSame("\xEF\xBB\xBFSKU,Quantity\nMH01,0\nMS04,2\n", $file->contents);
        self::assertSame('text/csv; charset=UTF-8', $file->contentType);
        self::assertMatchesRegularExpression('/^product-data-low-stock-\d{4}-\d{2}-\d{2}\.csv$/', $file->fileName);
    }

    #[Test]
    public function itWritesExcelWhenAskedFor(): void
    {
        $file = $this->export($this->conversation([$this->call('call_1')]), 'call_1', 'xlsx');

        self::assertStringStartsWith('PK', $file->contents);
        self::assertStringEndsWith('.xlsx', $file->fileName);
    }

    #[Test]
    public function itAsksForOneRowMoreThanTheCeilingAndSaysSoInTheNameWhenItCutTheResult(): void
    {
        $this->action = new FakeExportableAction('low_stock', array_fill(0, ResultLimit::EXPORT_MAX + 5, ['sku' => 'X', 'qty' => 1]));

        $file = $this->export($this->conversation([$this->call('call_1')]), 'call_1', 'csv');

        self::assertSame(ResultLimit::EXPORT_MAX + 1, $this->action->exports[0]['max_rows']);
        self::assertSame(ResultLimit::EXPORT_MAX + 1, substr_count($file->contents, "\n"));
        self::assertStringEndsWith('-first-' . ResultLimit::EXPORT_MAX . '.csv', $file->fileName);
    }

    #[Test]
    public function itDoesNotCallAResultOfExactlyTheCeilingCut(): void
    {
        $this->action = new FakeExportableAction('low_stock', array_fill(0, ResultLimit::EXPORT_MAX, ['sku' => 'X', 'qty' => 1]));

        $file = $this->export($this->conversation([$this->call('call_1')]), 'call_1', 'csv');

        self::assertSame(ResultLimit::EXPORT_MAX + 1, substr_count($file->contents, "\n"));
        self::assertStringNotContainsString('-first-', $file->fileName);
    }

    #[Test]
    public function itRunsTheCallWithTheRealValuesBehindItsPrivacyTokensAndOnlyTheKeysTheActionTakes(): void
    {
        $this->vault->beginConversation(self::CONVERSATION_ID);
        $token = $this->vault->tokenise('Oslo', 'name');

        $this->export($this->conversation([$this->call('call_1', ['query' => $token, 'capture' => true])]), 'call_1', 'csv');

        self::assertSame(
            ['action' => 'low_stock', 'query' => 'Oslo', '_admin_user_id' => self::ADMIN_USER_ID],
            $this->action->exports[0]['params']
        );
        self::assertSame(self::ADMIN_USER_ID, $this->action->exports[0]['admin_user_id']);
    }

    #[Test]
    public function itRefusesAConversationOfAnotherAdmin(): void
    {
        $repository = new FakeConversationRepository(
            [self::CONVERSATION_ID => ['entity_id' => self::CONVERSATION_ID, 'admin_user_id' => self::OTHER_ADMIN_USER_ID]],
            [self::CONVERSATION_ID => [$this->assistantMessage([$this->call('call_1')])]]
        );

        $this->assertRefused('This conversation was not found.', $repository, 'call_1', 'csv');
        self::assertSame([], $this->action->exports);
    }

    #[Test]
    public function itRefusesACallThatIsNotInTheConversation(): void
    {
        $this->assertRefused('This result was not found in the conversation.', $this->conversation([$this->call('call_1')]), 'call_2', 'csv');
    }

    #[Test]
    public function itRefusesACallThatFailed(): void
    {
        $this->assertRefused(
            'This result was not found in the conversation.',
            $this->conversation([['status' => 'failed'] + $this->call('call_1')]),
            'call_1',
            'csv'
        );
    }

    #[Test]
    public function itRefusesAFormatItHasNoWriterFor(): void
    {
        $this->assertRefused('Unknown export format.', $this->conversation([$this->call('call_1')]), 'call_1', 'pdf');
    }

    #[Test]
    public function itRefusesWhenTheAdminLostTheMagentoPermission(): void
    {
        $this->allowedResources = [];

        $this->assertRefused(
            'Access denied: you do not have the required Magento permission (Magento_Catalog::products) to use the product_data tool',
            $this->conversation([$this->call('call_1')]),
            'call_1',
            'csv'
        );
        self::assertSame([], $this->action->exports);
    }

    #[Test]
    public function itRefusesWhenTheSkillIsNoLongerGrantedToTheAdmin(): void
    {
        $this->permissions = (new FakePermissionChecker())->withDecision('product_data', 'read', false);

        $this->assertRefused('This result cannot be downloaded.', $this->conversation([$this->call('call_1')]), 'call_1', 'csv');
        self::assertSame([], $this->action->exports);
    }

    #[Test]
    public function itRefusesAnActionThatIsNotExportable(): void
    {
        $this->assertRefused(
            'This result cannot be downloaded.',
            $this->conversation([['input' => ['action' => 'get']] + $this->call('call_1')]),
            'call_1',
            'csv'
        );
    }

    #[Test]
    public function itRefusesAToolWithoutExports(): void
    {
        $this->assertRefused(
            'This result cannot be downloaded.',
            $this->conversation([['name' => 'plain_tool', 'input' => ['action' => 'list']] + $this->call('call_1')]),
            'call_1',
            'csv'
        );
    }

    #[Test]
    public function itNeverRunsAStoredWriteCallEvenWhenTheToolCallsItExportable(): void
    {
        $this->assertRefused(
            'This result cannot be downloaded.',
            $this->conversation([['name' => 'stock_tool', 'input' => ['action' => 'bulk_disable']] + $this->call('call_1')]),
            'call_1',
            'csv'
        );
        self::assertSame([], $this->stockTool->exports);
    }

    #[Test]
    public function itRunsAsTheAdminWhoDownloadsWhateverTheStoredCallSays(): void
    {
        $this->export(
            $this->conversation([['name' => 'stock_tool', 'input' => ['action' => 'list', '_admin_user_id' => 1]] + $this->call('call_1')]),
            'call_1',
            'csv'
        );

        self::assertSame(self::ADMIN_USER_ID, $this->stockTool->exports[0]['_admin_user_id']);
    }

    private function export(FakeConversationRepository $repository, string $toolCallId, string $format): ExportFile
    {
        return $this->service($repository)->export(self::CONVERSATION_ID, $toolCallId, $format, self::ADMIN_USER_ID);
    }

    private function assertRefused(string $message, FakeConversationRepository $repository, string $toolCallId, string $format): void
    {
        try {
            $this->export($repository, $toolCallId, $format);
            self::fail('The export was not refused');
        } catch (ExportRefusedException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    private function service(FakeConversationRepository $repository): ToolCallExport
    {
        $authorization = new FakeAclAuthorization($this->allowedResources);
        $registry = new ToolRegistry($this->permissions, [
            new FakeSkill('product_data', $authorization, [
                'low_stock' => $this->action,
                'get' => new FakeAction('get', true),
            ], self::SKILL_ACL),
            new FakeTool('plain_tool', ['list'], ['list'], self::SKILL_ACL),
            $this->stockTool,
        ]);

        return new ToolCallExport(
            $repository,
            $registry,
            new ToolCallGate($registry, new ToolAccess($authorization, $this->permissions)),
            new PrivacyService(new PrivacyFilter($this->vault, new PiiHeuristic()), $this->vault, new PiiHeuristic()),
            ['csv' => new CsvWriter(), 'xlsx' => new XlsxWriter()]
        );
    }

    /**
     * @param list<array<string, mixed>> $toolCalls
     */
    private function conversation(array $toolCalls): FakeConversationRepository
    {
        return new FakeConversationRepository(
            [self::CONVERSATION_ID => ['entity_id' => self::CONVERSATION_ID, 'admin_user_id' => self::ADMIN_USER_ID]],
            [self::CONVERSATION_ID => [
                ['role' => 'user', 'content' => 'Which products are running low on stock?'],
                $this->assistantMessage($toolCalls),
            ]]
        );
    }

    /**
     * @param list<array<string, mixed>> $toolCalls
     * @return array<string, mixed>
     */
    private function assistantMessage(array $toolCalls): array
    {
        return ['role' => 'assistant', 'content' => '', 'tool_calls' => json_encode($toolCalls)];
    }

    /**
     * A stored call as ChatService keeps it
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $id, array $arguments = []): array
    {
        return [
            'id' => $id,
            'name' => 'product_data',
            'input' => ['action' => 'low_stock'] + $arguments,
            'status' => 'done',
            'exportable' => true,
        ];
    }
}
