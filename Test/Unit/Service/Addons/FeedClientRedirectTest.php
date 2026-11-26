<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Addons;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Addons\FeedClient;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCurl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Security audit 2026-11-02, finding 3: the feed lives at one fixed address, so an answer that
 * redirects elsewhere must not make the server call wherever it points.
 */
final class FeedClientRedirectTest extends TestCase
{
    private const HTTP_OK = 200;

    #[Test]
    public function itDoesNotFollowRedirectsAwayFromTheFeed(): void
    {
        $curl = new FakeCurl(self::HTTP_OK, '{"addons": []}');
        $errorLogger = new ErrorLogger(new FakeLogger(), new Json());

        (new FeedClient($curl, new Json(), $errorLogger, new ErrorReporter($errorLogger, new PiiHeuristic())))->fetch();

        self::assertSame(['https://askmago.com/addons.json'], $curl->getRequestedUrls());
        self::assertFalse(
            (bool)($curl->getRecordedOptions()[CURLOPT_FOLLOWLOCATION] ?? false),
            'The feed client tells cURL to follow redirects to any https host.'
        );
    }
}
