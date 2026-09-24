<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use Magento\Framework\App\State;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Privacy\EgressTripwire;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EgressTripwireTest extends TestCase
{
    private function tripwire(bool $throwOnHit, string $mode, ?ErrorLogger $logger = null): EgressTripwire
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn($mode);

        return new EgressTripwire(
            new PiiHeuristic(),
            $state,
            $logger ?? $this->createMock(ErrorLogger::class),
            $throwOnHit
        );
    }

    #[Test]
    public function itThrowsUnderTheTestFlagWhenPiiSurvivesToTheWire(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/email/');

        $this->tripwire(true, State::MODE_DEVELOPER)->inspect([
            ['role' => 'tool', 'content' => '{"results":[{"email":"jan@example.com"}]}'],
        ]);
    }

    #[Test]
    public function itStaysSilentOnACleanTokenisedPayload(): void
    {
        $logger = $this->createMock(ErrorLogger::class);
        $logger->expects(self::never())->method('addLog');

        $this->tripwire(true, State::MODE_DEVELOPER, $logger)->inspect([
            ['role' => 'user', 'content' => 'Mail mago://email_1 about mago://order_1'],
            ['role' => 'assistant', 'content' => 'Done.'],
        ]);
    }

    #[Test]
    public function itLogsInsteadOfThrowingInDeveloperMode(): void
    {
        $logger = $this->createMock(ErrorLogger::class);
        $logger->expects(self::once())->method('addLog')
            ->with('Privacy Tripwire', self::callback(
                static fn (array $context): bool => $context['classes'] === ['email']
                    && !str_contains((string)json_encode($context), 'jan@example.com')
            ));

        $this->tripwire(false, State::MODE_DEVELOPER, $logger)->inspect([
            ['role' => 'tool', 'content' => 'contact jan@example.com'],
        ]);
    }

    #[Test]
    public function itSkipsTheScanEntirelyInProduction(): void
    {
        $logger = $this->createMock(ErrorLogger::class);
        $logger->expects(self::never())->method('addLog');

        $this->tripwire(false, State::MODE_PRODUCTION, $logger)->inspect([
            ['role' => 'tool', 'content' => 'contact jan@example.com'],
        ]);
    }
}
