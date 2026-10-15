<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml\Voice;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\User\Model\User;
use MagoAssistant\Mago\Controller\Adminhtml\Voice\Preference;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeVoicePreferenceRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The backend Context, request, auth and JSON result are framework classes without an interface
 * this controller can be given instead, so they are the one place this test stubs.
 */
final class PreferenceTest extends TestCase
{
    private FakeVoicePreferenceRepository $repository;

    /** @var list<array<string, mixed>> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->repository = new FakeVoicePreferenceRepository();
    }

    #[Test]
    public function itRecordsConsentForTheLoggedInAdminOnly(): void
    {
        $this->controller(7, ['consent' => true])->execute();

        self::assertSame([['success' => true]], $this->responses);
        self::assertTrue($this->repository->hasConsented(7));
        self::assertFalse($this->repository->hasConsented(8));
    }

    #[Test]
    public function itStoresTheChosenMode(): void
    {
        $this->controller(7, ['mode' => 'dictate'])->execute();

        self::assertSame('dictate', $this->repository->getMode(7));
        self::assertFalse($this->repository->hasConsented(7));
    }

    #[Test]
    public function itRejectsAnUnknownMode(): void
    {
        $this->controller(7, ['mode' => 'shout'])->execute();

        self::assertSame([['error' => 'Unknown voice mode']], $this->responses);
        self::assertNull($this->repository->getMode(7));
    }

    #[Test]
    public function itStoresNothingWithoutALoggedInAdmin(): void
    {
        $this->controller(null, ['consent' => true, 'mode' => 'confirm'])->execute();

        self::assertSame([['error' => 'Not authorized']], $this->responses);
        self::assertFalse($this->repository->hasConsented(0));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function controller(?int $adminUserId, array $body): Preference
    {
        $request = $this->createStub(Http::class);
        $request->method('getContent')->willReturn((string)json_encode($body));

        $user = null;
        if ($adminUserId !== null) {
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn($adminUserId);
        }
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

        return new Preference(
            $context,
            $this->repository,
            $jsonFactory,
            new Json(),
            new ErrorReporter(new ErrorLogger(new FakeLogger(), new Json()), new PiiHeuristic()),
            $this->createStub(FormKey::class)
        );
    }
}
