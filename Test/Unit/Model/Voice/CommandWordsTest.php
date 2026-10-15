<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Voice;

use MagoAssistant\Mago\Model\Voice\CommandWords;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommandWordsTest extends TestCase
{
    #[Test]
    public function itPutsTheShopsWordsBeforeTheEnglishOnes(): void
    {
        $words = CommandWords::withEnglish('yes', ' Ja , Verstuur,,ja');

        self::assertSame(['ja', 'verstuur', 'yes'], array_slice($words, 0, 3));
        self::assertContains('send', $words);
    }

    #[Test]
    public function itKeepsTheEnglishWordsWhenNothingIsConfigured(): void
    {
        self::assertSame('stop', CommandWords::withEnglish('stop', '')[0]);
    }

    #[Test]
    public function itReadsNumberWordsInOrderWithAlternatives(): void
    {
        self::assertSame(
            [1 => ['een', 'één', 'eerste'], 2 => ['twee'], 4 => ['vier']],
            CommandWords::numbers('Een/één/eerste, twee, , vier')
        );
    }
}
