<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "error" crosses whatever a tool declares, because a failure the model cannot read is a failure it
 * cannot explain. "message" does not: it carries an ordinary answer, and prose is where a name rides
 * along unrecognised by any pattern.
 */
final class MessageEnvelopeTest extends TestCase
{
    private function filter(): PrivacyFilter
    {
        return new PrivacyFilter(new ConversationVault(), new PiiHeuristic());
    }

    #[Test]
    public function anErrorIsKeptEvenWhenNothingDeclaresIt(): void
    {
        $result = $this->filter()->filter([], ['error' => 'Order not found: 000000549']);

        self::assertSame('Order not found: 000000549', $result['error']);
    }

    #[Test]
    public function aMessageNoToolDeclaresIsStripped(): void
    {
        $result = $this->filter()->filter(
            ['success' => [PiiClass::PUBLIC]],
            ['success' => true, 'message' => 'Order 000000549 belongs to Jan Jansen']
        );

        self::assertSame(['success' => true], $result);
    }

    #[Test]
    public function aDeclaredMessageCrossesLikeAnyOtherField(): void
    {
        $result = $this->filter()->filter(
            ['message' => [PiiClass::PUBLIC]],
            ['message' => 'Cache type "config" has been flushed']
        );

        self::assertSame('Cache type "config" has been flushed', $result['message']);
    }

    #[Test]
    public function aNameInAMissMessageNoLongerReachesTheModel(): void
    {
        $classes = (new \ReflectionClass(
            \MagoAssistant\Mago\Service\Skills\Analytics\CustomerData\LookupCustomerAction::class
        ))->newInstanceWithoutConstructor()->getFieldClassification();

        $result = $this->filter()->filter($classes, [
            'results' => [],
            'message' => 'No customers found matching "Jan Jansen"',
        ]);

        self::assertStringNotContainsString('Jan Jansen', (string)json_encode($result));
    }
}
