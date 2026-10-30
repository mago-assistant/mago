<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\User\Model\User;
use MagoAssistant\Mago\Controller\Adminhtml\Chat\Export;
use MagoAssistant\Mago\Service\Acl\ToolAccess;
use MagoAssistant\Mago\Service\Export\CsvWriter;
use MagoAssistant\Mago\Service\Export\ToolCallExport;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;
use MagoAssistant\Mago\Service\Tool\ToolCallGate;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConversationRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeExportableAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePermissionChecker;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #298: the download of a tool call's full result. The backend Context, request, auth and
 * results are framework classes without an interface this controller can be given instead, so
 * they are the one place this test stubs; the export behind it runs on fakes.
 */
final class ExportTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const CONVERSATION_ID = 1;

    /** @var list<string> */
    private array $errors = [];

    private bool $isRedirectedBack = false;

    private ?int $jsonCode = null;

    /** @var array<string, mixed>|null */
    private ?array $jsonData = null;

    private ?string $contents = null;

    /** @var array<string,string> */
    private array $headers = [];

    #[Test]
    public function itAsksForTheAssistantReadResource(): void
    {
        self::assertSame('MagoAssistant_Mago::assistant_read', Export::ADMIN_RESOURCE);
    }

    #[Test]
    public function itSendsTheFileAsAnAttachmentThatIsNeverCached(): void
    {
        $this->controller(['conversation_id' => '1', 'tool_call_id' => 'call_1', 'format' => 'csv'], self::ADMIN_USER_ID)->execute();

        self::assertSame("\xEF\xBB\xBFSKU,Quantity\nMH01,0\n", $this->contents);
        self::assertSame('text/csv; charset=UTF-8', $this->headers['Content-Type']);
        self::assertMatchesRegularExpression(
            '/^attachment; filename="product-data-low-stock-\d{4}-\d{2}-\d{2}\.csv"$/',
            $this->headers['Content-Disposition']
        );
        self::assertSame('no-store', $this->headers['Cache-Control']);
        self::assertFalse($this->isRedirectedBack);
    }

    #[Test]
    public function itAnswersThePanelsBackgroundRequestWithTheReasonSoTheAdminStaysOnThePage(): void
    {
        $this->controller(['conversation_id' => '1', 'tool_call_id' => 'call_9', 'format' => 'csv'], self::ADMIN_USER_ID, true)->execute();

        self::assertSame(403, $this->jsonCode);
        self::assertSame(['error' => 'This result was not found in the conversation.'], $this->jsonData);
        self::assertFalse($this->isRedirectedBack);
        self::assertSame([], $this->errors);
        self::assertNull($this->contents);
    }

    #[Test]
    public function itSendsALinkOpenedOnItsOwnBackWhereItCameFromWithTheReason(): void
    {
        $this->controller(['conversation_id' => '1', 'tool_call_id' => 'call_9', 'format' => 'csv'], self::ADMIN_USER_ID)->execute();

        self::assertTrue($this->isRedirectedBack);
        self::assertSame(['This result was not found in the conversation.'], $this->errors);
        self::assertNull($this->jsonData);
        self::assertNull($this->contents);
    }

    #[Test]
    public function itRefusesWithoutAnAdminInTheSession(): void
    {
        $this->controller(['conversation_id' => '1', 'tool_call_id' => 'call_1', 'format' => 'csv'], null, true)->execute();

        self::assertSame(['error' => 'This conversation was not found.'], $this->jsonData);
        self::assertNull($this->contents);
    }

    /**
     * @param array<string,string> $params
     */
    private function controller(array $params, ?int $adminUserId, bool $isAjax = false): Export
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
        );
        $request->method('isAjax')->willReturn($isAjax);

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->errors[] = (string)$message;
            return $messages;
        });

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setRefererOrBaseUrl')->willReturnCallback(function () use ($redirect) {
            $this->isRedirectedBack = true;
            return $redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($raw) {
            $this->headers[$name] = $value;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function (string $contents) use ($raw) {
            $this->contents = $contents;
            return $raw;
        });
        $json = $this->createStub(Json::class);
        $json->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($json) {
            $this->jsonCode = $code;
            return $json;
        });
        $json->method('setData')->willReturnCallback(function (array $data) use ($json) {
            $this->jsonData = $data;
            return $json;
        });
        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturnCallback(
            static fn (string $type): object => $type === ResultFactory::TYPE_JSON ? $json : $raw
        );

        $user = null;
        if ($adminUserId !== null) {
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn($adminUserId);
        }
        $auth = $this->createStub(Auth::class);
        $auth->method('getUser')->willReturn($user);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getResultFactory')->willReturn($resultFactory);
        $context->method('getAuth')->willReturn($auth);

        return new Export($context, $this->toolCallExport());
    }

    private function toolCallExport(): ToolCallExport
    {
        $permissions = (new FakePermissionChecker())->withDecision('product_data', 'read', true);
        $registry = new ToolRegistry($permissions, [new FakeSkill('product_data', new FakeAuthorization(), [
            'low_stock' => new FakeExportableAction('low_stock', [['sku' => 'MH01', 'qty' => 0]]),
        ])]);
        $vault = new ConversationVault();
        $repository = new FakeConversationRepository(
            [self::CONVERSATION_ID => ['entity_id' => self::CONVERSATION_ID, 'admin_user_id' => self::ADMIN_USER_ID]],
            [self::CONVERSATION_ID => [[
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => json_encode([[
                    'id' => 'call_1',
                    'name' => 'product_data',
                    'input' => ['action' => 'low_stock'],
                    'status' => 'done',
                    'exportable' => true,
                ]]),
            ]]]
        );

        return new ToolCallExport(
            $repository,
            $registry,
            new ToolCallGate($registry, new ToolAccess(new FakeAuthorization(), $permissions)),
            new PrivacyService(new PrivacyFilter($vault, new PiiHeuristic()), $vault, new PiiHeuristic()),
            ['csv' => new CsvWriter()]
        );
    }
}
