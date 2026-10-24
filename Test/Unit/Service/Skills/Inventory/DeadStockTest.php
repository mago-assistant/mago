<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Inventory;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MagoAssistant\Mago\Service\Skills\Inventory\DeadStock;
use MagoAssistant\Mago\Service\Skills\Inventory\DeadStockReport;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeDeadStockReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DeadStockTest extends TestCase
{
    private FakeDeadStockReport $report;

    #[Test]
    public function itRefusesAnUnknownAction(): void
    {
        $result = $this->toolWith([])->execute(['action' => 'overstock']);

        self::assertSame(['error' => 'dead_stock needs action "obsolete" or "slow_moving"'], $result);
    }

    #[Test]
    public function itTotalsAllRowsButListsOnlyTheLimit(): void
    {
        $result = $this->toolWith([
            $this->row('A', 40, 1300.0),
            $this->row('B', 5, 100.0),
            $this->row('C', 2, 10.0),
        ])->execute(['action' => 'obsolete', 'limit' => 2]);

        self::assertSame(3, $result['product_count']);
        self::assertSame(47.0, $result['total_qty']);
        self::assertSame(1410.0, $result['total_stock_value']);
        self::assertSame(2, $result['shown']);
        self::assertSame(['A', 'B'], array_column($result['products'], 'sku'));
        self::assertArrayNotHasKey('product_id', $result['products'][0]);
        self::assertArrayNotHasKey('min_days_of_cover', $result);
        self::assertSame('EUR', $result['currency']);
        self::assertSame('mago/deadstock/export?type=obsolete&months=6', $result['export_url']);
    }

    #[Test]
    public function itClampsTheMonthsAndPassesTheCoverThresholdForSlowMoving(): void
    {
        $result = $this->toolWith([])->execute([
            'action' => 'slow_moving',
            'months' => 99,
            'min_days_of_cover' => 365,
        ]);

        self::assertSame(DeadStockReport::MAX_MONTHS, $result['months']);
        self::assertSame(365, $result['min_days_of_cover']);
        self::assertSame(
            'mago/deadstock/export?type=slow_moving&months=36&min_days_of_cover=365',
            $result['export_url']
        );
    }

    #[Test]
    public function itPassesTheDefaultsToTheReport(): void
    {
        $this->toolWith([])->execute(['action' => 'obsolete']);

        self::assertSame([
            ['method' => 'summary', 'type' => 'obsolete', 'months' => 6, 'min_days_of_cover' => 180],
            ['method' => 'build', 'type' => 'obsolete', 'months' => 6, 'min_days_of_cover' => 180, 'limit' => 20],
        ], $this->report->calls);
    }

    /**
     * @param array<string,mixed> $params
     */
    #[Test]
    #[DataProvider('outOfRangeParams')]
    public function itClampsWhatItPassesToTheReport(array $params, int $months, int $minDaysOfCover, int $limit): void
    {
        $this->toolWith([])->execute(['action' => 'slow_moving'] + $params);

        self::assertSame(
            [
                'method' => 'build',
                'type' => 'slow_moving',
                'months' => $months,
                'min_days_of_cover' => $minDaysOfCover,
                'limit' => $limit,
            ],
            $this->report->calls[1]
        );
        self::assertSame($months, $this->report->calls[0]['months']);
        self::assertSame($minDaysOfCover, $this->report->calls[0]['min_days_of_cover']);
    }

    /**
     * @return array<string, array{array<string,mixed>, int, int, int}>
     */
    public static function outOfRangeParams(): array
    {
        return [
            'above the maximum' => [['months' => 99, 'limit' => 500], 36, 180, 50],
            'below the minimum' => [['months' => 0, 'min_days_of_cover' => -5, 'limit' => 0], 1, 1, 1],
            'numeric strings' => [['months' => '12', 'min_days_of_cover' => '365', 'limit' => '5'], 12, 365, 5],
        ];
    }

    #[Test]
    public function itClassifiesEveryReturnedFieldAndTokenisesTheExportUrl(): void
    {
        $tool = $this->toolWith([$this->row('A', 1, 1.0)]);
        $classes = $tool->getFieldClassification();
        $result = $tool->execute(['action' => 'slow_moving']);

        foreach ([...array_keys($result), ...array_keys($result['products'][0])] as $key) {
            if ($key !== 'products') {
                self::assertArrayHasKey($key, $classes, $key . ' is not classified');
            }
        }
        self::assertSame(['tokenise', 'url'], $classes['export_url']);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function toolWith(array $rows): DeadStock
    {
        $this->report = new FakeDeadStockReport($rows);

        $url = $this->createStub(SecureAdminUrl::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => $route . '?' . http_build_query($params)
        );

        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('EUR');

        return new DeadStock($this->report, $url, $config);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $sku, int $qty, float $value): array
    {
        return [
            'product_id' => 1,
            'sku' => $sku,
            'product_name' => $sku,
            'qty' => (float)$qty,
            'units_sold' => 0.0,
            'last_sold_at' => null,
            'created_at' => '2025-01-01 00:00:00',
            'days_of_cover' => null,
            'unit_value' => 1.0,
            'value_basis' => 'price',
            'stock_value' => $value,
        ];
    }
}
