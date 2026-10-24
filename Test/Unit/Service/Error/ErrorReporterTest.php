<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Error;

use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Model\Conversation\ConversationNotFoundException;
use MagoAssistant\Mago\Service\Ai\AiNotConfiguredException;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorReporterTest extends TestCase
{
    private FakeLogger $logger;

    private ErrorReporter $reporter;

    protected function setUp(): void
    {
        $this->logger = new FakeLogger();
        $this->reporter = new ErrorReporter(new ErrorLogger($this->logger, new Json()), new PiiHeuristic());
    }

    /**
     * A provider or driver puts the endpoint, its key and the data it choked on in the message
     */
    #[Test]
    public function anUnexpectedExceptionReachesTheCallerOnlyAsAReference(): void
    {
        $exception = new \RuntimeException(
            'HTTP 500 from https://api.example.test/v1/chat?key=sk-live-123: duplicate entry jan@example.test'
        );

        $message = $this->reporter->report('ChatService', $exception);

        self::assertStringNotContainsString('example.test', $message);
        self::assertStringNotContainsString('sk-live-123', $message);
        self::assertMatchesRegularExpression('/Reference: ([0-9a-f]{8})/', $message);
        preg_match('/Reference: ([0-9a-f]{8})/', $message, $match);

        $logged = implode("\n", $this->logger->getMessages());
        self::assertStringContainsString('ChatService: [' . $match[1] . '] RuntimeException: HTTP 500', $logged);
        self::assertStringNotContainsString('jan@example.test', $logged, 'personal data is masked in the log');
        self::assertStringContainsString(__FILE__, $logged);
        self::assertStringNotContainsString('sk-live-123', $logged, 'credentials are redacted in the log');
        self::assertStringContainsString('key=[redacted]', $logged);
    }

    #[Test]
    public function theLogNamesTheCauseAWrappedExceptionCarries(): void
    {
        $cause = new \RuntimeException('cURL error 6: Could not resolve host; Authorization: Bearer abc.def-123');

        $this->reporter->report('ChatService', new \LogicException('Request failed', 0, $cause));

        $logged = implode("\n", $this->logger->getMessages());
        self::assertStringContainsString('caused by RuntimeException: cURL error 6', $logged);
        self::assertStringNotContainsString('abc.def-123', $logged);
        self::assertStringContainsString('Authorization: [redacted]', $logged);
    }

    /**
     * @return array<string, array{0: \Throwable}>
     */
    public static function messagesWrittenForTheAdmin(): array
    {
        return [
            'no AI service configured' => [new AiNotConfiguredException(__('No AI service configured.'))],
            'not an admin token' => [new AuthorizationException(__('This endpoint requires an admin user token.'))],
            'conversation not found' => [new ConversationNotFoundException('Conversation not found: 12')],
            'no such entity' => [new NoSuchEntityException(__('No such entity.'))],
        ];
    }

    #[Test]
    #[DataProvider('messagesWrittenForTheAdmin')]
    public function aMessageWrittenForTheAdminPassesAsWritten(\Throwable $exception): void
    {
        self::assertSame($exception->getMessage(), $this->reporter->report('ChatManagement', $exception));
        self::assertSame([], $this->logger->getMessages());
    }

    /**
     * Magento's own validation tells the model what to change; a driver exception carries the query
     */
    #[Test]
    public function aFailedToolCallKeepsMagentosReasonButNotTheQuery(): void
    {
        $validation = new AlreadyExistsException(__('URL key for specified store already exists.'));
        $driver = new \RuntimeException("SQLSTATE[23000]: Duplicate entry 'jan@example.test' for key 'email'");

        self::assertSame($validation->getMessage(), $this->reporter->reportToolFailure('Tool Error', $validation));

        $message = $this->reporter->reportToolFailure('Tool Error url_rewrite_manager', $driver);
        self::assertStringNotContainsString('SQLSTATE', $message);
        self::assertStringContainsString('Reference:', $message);
        self::assertStringContainsString('SQLSTATE[23000]', implode("\n", $this->logger->getMessages()));
    }

    /**
     * Core repositories put the driver's text in their own message ("Could not save the page: %1")
     */
    #[Test]
    public function aMagentoMessageWrappingADriverErrorIsTreatedAsTheDriverError(): void
    {
        $driver = new \RuntimeException("SQLSTATE[23000]: Duplicate entry, query was: INSERT INTO cms_page");
        $wrapped = new LocalizedException(__('Could not save the page: %1', $driver->getMessage()), $driver);

        $message = $this->reporter->reportToolFailure('Tool Error cms_page', $wrapped);

        self::assertStringNotContainsString('INSERT INTO', $message);
        self::assertStringContainsString('Reference:', $message);
    }

    /**
     * An HTTP client can also quote the request headers it sent (#225)
     */
    #[Test]
    public function theLogRedactsCredentialsInHeaderForm(): void
    {
        $this->reporter->log('AddonFeed', new \RuntimeException(
            'Request failed. Headers: x-api-key: abc123; Authorization: Basic dXNlcjpwYXNz, Accept: json'
        ));

        $logged = implode("\n", $this->logger->getMessages());
        self::assertStringNotContainsString('abc123', $logged);
        self::assertStringNotContainsString('dXNlcjpwYXNz', $logged);
        self::assertStringContainsString('x-api-key: [redacted]', $logged);
        self::assertStringContainsString('Accept: json', $logged);
    }

    #[Test]
    public function theLogRedactsJsonQuotedHeadersButNotProse(): void
    {
        $this->reporter->log('AddonFeed', new \RuntimeException(
            '{"x-api-key":"abc123def","api_key": "zyx987wvu"} {"x-api-key": ["qrs456tuv"]} '
            . "'Authorization': 'Basic mno321pqr' Missing authorization: see the docs"
        ));

        $logged = implode("\n", $this->logger->getMessages());
        self::assertStringNotContainsString('abc123def', $logged);
        self::assertStringNotContainsString('zyx987wvu', $logged);
        self::assertStringNotContainsString('qrs456tuv', $logged);
        self::assertStringNotContainsString('mno321pqr', $logged);
        self::assertStringContainsString('Missing authorization: see the docs', $logged);
    }

    /**
     * Mago's own "No store view is available." quotes nothing and still reaches the admin (#225)
     */
    #[Test]
    public function aNoSuchEntityWithoutParametersPassesAsWritten(): void
    {
        $exception = new NoSuchEntityException(__('No store view is available.'));

        self::assertSame('No store view is available.', $this->reporter->report('ChatManagement', $exception));
    }

    /**
     * Core puts the value it looked up in "No such entity with %fieldName = %fieldValue" (#225)
     */
    #[Test]
    public function aNoSuchEntityThatQuotesTheValueItLookedUpIsReportedByReference(): void
    {
        $exception = NoSuchEntityException::singleField('email', 'jan@example.test');

        $message = $this->reporter->report('ChatManagement', $exception);

        self::assertStringNotContainsString('jan@example.test', $message);
        self::assertStringContainsString('Reference:', $message);
    }
}
