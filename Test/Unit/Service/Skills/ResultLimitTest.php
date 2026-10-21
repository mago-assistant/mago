<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills;

use MagoAssistant\Mago\Service\Skills\ResultLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResultLimitTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function requestedLimits(): array
    {
        return [
            'no limit given' => [[], 20],
            'zero, which Magento reads as no limit at all' => [['limit' => 0], 1],
            'zero as a string' => [['limit' => '0'], 1],
            'negative' => [['limit' => -5], 1],
            'above the maximum' => [['limit' => 5000], ResultLimit::MAX],
            'within range' => [['limit' => 30], 30],
            'within range as a string' => [['limit' => '30'], 30],
            'not a number' => [['limit' => 'all'], 20],
            'null' => [['limit' => null], 20],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[Test]
    #[DataProvider('requestedLimits')]
    public function itKeepsTheLimitBetweenOneAndTheMaximum(array $params, int $expected): void
    {
        self::assertSame($expected, ResultLimit::fromParams($params, 20));
    }

    #[Test]
    public function anActionCanSetALowerMaximum(): void
    {
        self::assertSame(10, ResultLimit::fromParams(['limit' => 40], 5, 10));
    }
}
