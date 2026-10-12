<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\User\Model\User;
use MagoAssistant\Mago\Controller\Adminhtml\Chat\Reject;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Conversation\ConfirmationClaim;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConversationRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The backend Context, request, auth and JSON result are framework classes without an interface
 * this controller can be given instead, so they are the one place this test stubs.
 */
final class RejectTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private FakeConversationRepository $repository;
    private int $conversationId;
    private int $messageId;

    /** @var list<array<string, mixed>> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->repository = new FakeConversationRepository();
        $this->conversationId = $this->repository->create(self::ADMIN_USER_ID);
        $this->messageId = $this->repository->addMessage(
            $this->conversationId,
            'assistant',
            'Creating the invoice.',
            [['id' => 'call_1', 'name' => 'order_manager', 'input' => ['action' => 'create_invoice']]],
            true
        );
    }

    #[Test]
    public function itRejectsAPendingProposalAndMarksItsCallsSkipped(): void
    {
        $this->controller()->execute();

        self::assertSame([['success' => true]], $this->responses);
        $toolCalls = json_decode((string)$this->repository->getMessageById($this->messageId)['tool_calls'], true);
        self::assertSame('skipped', $toolCalls[0]['status']);
    }

    #[Test]
    public function itAnswersAlreadyHandledWhenTheProposalWasRejectedBefore(): void
    {
        $this->controller()->execute();

        $this->controller()->execute();

        self::assertStringContainsString('already been handled', (string)($this->responses[1]['error'] ?? ''));
        self::assertCount(1, array_filter(
            $this->repository->messagesWithRole($this->conversationId, 'assistant'),
            static fn (array $message): bool => str_contains((string)$message['content'], 'rejected')
        ));
    }

    #[Test]
    public function itAnswersAlreadyHandledWhenTheProposalWasConfirmedBefore(): void
    {
        (new ConfirmationClaim($this->repository))->claimToConfirm($this->messageId, self::ADMIN_USER_ID);

        $this->controller()->execute();

        self::assertStringContainsString('already been handled', (string)($this->responses[0]['error'] ?? ''));
    }

    private function controller(): Reject
    {
        $request = $this->createStub(Http::class);
        $request->method('getContent')->willReturn((string)json_encode(['message_id' => $this->messageId]));

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::ADMIN_USER_ID);
        $auth = $this->createStub(Auth::class);
        $auth->method('getUser')->willReturn($user);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getAuth')->willReturn($auth);

        $result = $this->createStub(JsonResult::class);
        $result->method('setData')->willReturnCallback(function (array $data) use (&$result) {
            $this->responses[] = $data;
            return $result;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($result);

        return new Reject(
            $context,
            $this->repository,
            $jsonFactory,
            new Json(),
            new ErrorReporter(new ErrorLogger(new FakeLogger(), new Json()), new PiiHeuristic()),
            $this->createStub(FormKey::class),
            new ConfirmationClaim($this->repository)
        );
    }
}
