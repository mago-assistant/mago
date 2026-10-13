<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Usage;

use MagoAssistant\Mago\Service\Usage\CostEstimator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CostEstimatorTest extends TestCase
{
    private const LUNA = ['input' => 0.10, 'cached_input' => 0.01, 'cache_write' => 0.125, 'output' => 0.50];

    #[Test]
    public function itPricesCachedInputTokensAtTheCachedRate(): void
    {
        $estimator = new CostEstimator(['gpt-6-luna' => self::LUNA]);

        // 46,374 plain + 125,383 cached + 1,669 output (a measured conversation)
        $cost = $estimator->estimate('gpt-6-luna', 171757, 1669, 125383);

        self::assertSame(0.0067, $cost);
    }

    #[Test]
    public function itPricesEverythingAtTheInputRateWhenNoCacheFigureIsReported(): void
    {
        $estimator = new CostEstimator(['gpt-6-luna' => self::LUNA]);

        self::assertSame(0.018, $estimator->estimate('gpt-6-luna', 171757, 1669, null));
    }

    #[Test]
    public function itTakesCacheWritesOutOfThePlainInput(): void
    {
        $estimator = new CostEstimator([
            'm' => ['input' => 1.0, 'cached_input' => 0.1, 'cache_write' => 2.0, 'output' => 0.0],
        ]);

        // 600,000 plain at 1.0 + 300,000 read at 0.1 + 100,000 written at 2.0
        self::assertSame(0.83, $estimator->estimate('m', 1000000, 0, 300000, 100000));
    }

    #[Test]
    public function aModelWithoutAPriceUsesTheFallbackWithoutACacheDiscount(): void
    {
        $estimator = new CostEstimator([], ['input' => 3.0, 'output' => 15.0]);

        self::assertSame(3.0 + 15.0, $estimator->estimate('unknown', 1000000, 1000000, 900000));
    }

    #[Test]
    public function aPriceWithoutACachedRateChargesCachedTokensAsInput(): void
    {
        $estimator = new CostEstimator(['m' => ['input' => 2.0, 'output' => 4.0]]);

        self::assertSame(2.0, $estimator->estimate('m', 1000000, 0, 1000000));
    }

    #[Test]
    public function aCacheReadAboveTheInputIsCappedAtTheInput(): void
    {
        $estimator = new CostEstimator(['m' => ['input' => 1.0, 'cached_input' => 0.5, 'output' => 0.0]]);

        self::assertSame(0.5, $estimator->estimate('m', 1000000, 0, 5000000));
    }

    #[Test]
    public function itSumsRowsOfDifferentModels(): void
    {
        $estimator = new CostEstimator(['gpt-6-luna' => self::LUNA, 'm' => ['input' => 1.0, 'output' => 2.0]]);

        $total = $estimator->estimateRows([
            [
                'model' => 'gpt-6-luna',
                'input_tokens' => '1000000',
                'output_tokens' => '0',
                'cache_read_tokens' => '1000000',
            ],
            ['model' => 'm', 'input_tokens' => 1000000, 'output_tokens' => 1000000, 'cache_read_tokens' => null],
        ]);

        self::assertSame(3.01, $total);
    }
}
