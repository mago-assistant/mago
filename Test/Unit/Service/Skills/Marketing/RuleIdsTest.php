<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing;

use MagoAssistant\Mago\Service\Skills\Marketing\RuleIds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuleIdsTest extends TestCase
{
    #[Test]
    public function itReadsAMissingParameterAsNotGiven(): void
    {
        self::assertNull(RuleIds::fromInput(null));
    }

    #[Test]
    public function itReadsAListOfIds(): void
    {
        self::assertSame([1, 0], RuleIds::fromInput(['1', 0]));
    }

    #[Test]
    public function itSplitsACommaListTheWayMagentosSaveDoes(): void
    {
        self::assertSame([1, 0], RuleIds::fromInput('1, 0'));
    }

    #[Test]
    public function itSplitsACommaListInsideAList(): void
    {
        self::assertSame([1, 0, 2], RuleIds::fromInput(['1,0', 2]));
    }

    #[Test]
    public function itReadsAnEmptyStringAsNoIds(): void
    {
        self::assertSame([], RuleIds::fromInput(''));
    }

    #[Test]
    public function itReadsASingleIdAsAList(): void
    {
        self::assertSame([3], RuleIds::fromInput(3));
    }
}
