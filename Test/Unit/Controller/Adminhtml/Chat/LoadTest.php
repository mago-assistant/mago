<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\User\Model\User;
use MagoAssistant\Mago\Controller\Adminhtml\Chat\Load;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Flag\FlagRepository;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConversationRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The history the panel reloads keeps its privacy tokens, with the values beside them: the panel
 * renders each message first and puts the values in as text afterwards, so a review or a name can
 * never turn into markup on the way. The framework classes are doubles because a backend action
 * cannot be built without them.
 */
final class LoadTest extends TestCase
{
    private const CONVERSATION_ID = 12;
    private const ADMIN_ID = 3;
    private const REVIEW = '**bold** [click](https://evil.example) <img src=x onerror=alert(1)> # heading';

    /** @var array<string, mixed> What the controller handed the JSON result */
    private array $response = [];

    #[Test]
    public function itSendsTheStoredMessagesWithTheirTokensIntact(): void
    {
        $vault = new ConversationVault();
        $review = $vault->tokenise(self::REVIEW, 'reviewtext');

        $this->load($vault, ['The review says ' . $review]);

        self::assertSame('The review says ' . $review, $this->response['messages'][0]['content']);
    }

    #[Test]
    public function itSendsTheValueOfEveryTokenInTheConversation(): void
    {
        $vault = new ConversationVault();
        $review = $vault->tokenise(self::REVIEW, 'reviewtext');
        $email = $vault->tokenise('jan@example.com', 'email');

        $this->load($vault, ['Mail ' . $email, 'The review says ' . $review . ', not mago://name_9']);

        self::assertSame([$email => 'jan@example.com', $review => self::REVIEW], $this->response['tokens']);
    }

    #[Test]
    public function itSendsAnEmptyTokenMapForAConversationWithoutTokens(): void
    {
        $this->load(new ConversationVault(), ['Nothing masked here.']);

        self::assertSame([], $this->response['tokens']);
    }

    /**
     * @param list<string> $contents
     */
    private function load(ConversationVault $vault, array $contents): void
    {
        $messages = array_map(
            static fn (string $content): array => ['role' => 'assistant', 'content' => $content],
            $contents
        );
        $repository = new FakeConversationRepository(
            [self::CONVERSATION_ID => ['entity_id' => self::CONVERSATION_ID, 'admin_user_id' => self::ADMIN_ID, 'title' => 'Reviews']],
            [self::CONVERSATION_ID => $messages]
        );

        $controller = new Load(
            $this->context(),
            $repository,
            $this->jsonFactory(),
            new PrivacyService(new PrivacyFilter($vault, new PiiHeuristic()), $vault, new PiiHeuristic()),
            (new \ReflectionClass(FlagRepository::class))->newInstanceWithoutConstructor(),
            new ErrorReporter(new ErrorLogger(new FakeLogger(), new Json()), new PiiHeuristic())
        );

        $controller->execute();
    }

    private function context(): Context
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['conversation_id', 0, self::CONVERSATION_ID]]);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::ADMIN_ID);
        $auth = $this->createStub(Auth::class);
        $auth->method('getUser')->willReturn($user);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getAuth')->willReturn($auth);

        return $context;
    }

    private function jsonFactory(): JsonFactory
    {
        $result = $this->createStub(JsonResult::class);
        $result->method('setData')->willReturnCallback(function (array $data) use ($result): JsonResult {
            $this->response = $data;

            return $result;
        });
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($result);

        return $factory;
    }
}
