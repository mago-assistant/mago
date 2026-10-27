<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\System\CronStatus;

use Magento\Cron\Model\ResourceModel\Schedule as ScheduleResource;
use Magento\Cron\Model\ResourceModel\Schedule\Collection;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use Magento\Cron\Model\Schedule;
use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Skills\System\CronStatus\HealthCheckAction;
use MagoAssistant\Mago\Service\Skills\System\CronStatus\ListFailedAction;
use MagoAssistant\Mago\Service\Skills\System\CronStatus\ListRunningAction;
use MagoAssistant\Mago\Service\Skills\System\CronStatus\ListScheduleAction;
use MagoAssistant\Mago\Service\Skills\System\CronStatus\ScheduleJobAction;
use MagoAssistant\Mago\Service\Time\StoreTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every cron_status action returns its times on the store's clock, while the queries
 * and the stuck/health math keep comparing UTC with UTC.
 */
class CronTimesInStoreTimeTest extends TestCase
{
    private const ZONE = 'Pacific/Auckland';

    #[Test]
    public function listScheduleShowsScheduledAndCreatedOnTheStoresClock(): void
    {
        $action = new ListScheduleAction(
            $this->factoryOf([$this->row(['schedule_id' => 1, 'job_code' => 'a',
                'scheduled_at' => '2026-10-27 10:00:00', 'created_at' => '2026-10-27 09:00:00'])]),
            $this->storeTime()
        );

        $job = $action->execute([], 1)['scheduled_jobs'][0];

        self::assertSame($this->local('2026-10-27 10:00:00'), $job['scheduled_at']);
        self::assertSame($this->local('2026-10-27 09:00:00'), $job['created_at']);
    }

    #[Test]
    public function listFailedShowsItsTimesLocallyAndAMissingOneAsNull(): void
    {
        $action = new ListFailedAction(
            $this->factoryOf([$this->row(['schedule_id' => 1, 'job_code' => 'a', 'status' => 'error',
                'scheduled_at' => '2026-10-27 10:00:00', 'executed_at' => '2026-10-27 10:00:05',
                'finished_at' => null])]),
            $this->createStub(PeriodParser::class),
            $this->storeTime()
        );

        $job = $action->execute([], 1)['failed_jobs'][0];

        self::assertSame($this->local('2026-10-27 10:00:00'), $job['scheduled_at']);
        self::assertSame($this->local('2026-10-27 10:00:05'), $job['executed_at']);
        self::assertNull($job['finished_at']);
    }

    #[Test]
    public function listRunningShowsExecutedLocallyButMeasuresTheRunInUtc(): void
    {
        $twoHoursAgo = $this->utcAgo('-2 hours');
        $action = new ListRunningAction(
            $this->factoryOf([$this->row(['schedule_id' => 1, 'job_code' => 'a', 'executed_at' => $twoHoursAgo])]),
            $this->storeTime()
        );

        $job = $action->execute([], 1)['running_jobs'][0];

        self::assertSame($this->local($twoHoursAgo), $job['executed_at']);
        self::assertTrue($job['possibly_stuck']);
        self::assertStringStartsWith('2h', $job['running_since']);
    }

    #[Test]
    public function healthCheckShowsItsTimesLocallyButJudgesHealthInUtc(): void
    {
        $tenMinutesAgo = $this->utcAgo('-10 minutes');
        $stuckSince = $this->utcAgo('-3 hours');
        $oldestPending = $this->utcAgo('+5 minutes');

        $empty = $this->collection([]);
        $collections = [$empty, $empty, $empty, $empty, $empty,
            $this->collection([], $this->row(['finished_at' => $tenMinutesAgo])),
            $this->collection([$this->row(['schedule_id' => 7, 'job_code' => 'b', 'executed_at' => $stuckSince])]),
            $this->collection([], $this->row(['scheduled_at' => $oldestPending])),
        ];
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(...$collections);

        $result = (new HealthCheckAction($factory, $this->storeTime()))->execute([], 1);

        self::assertSame($this->local($tenMinutesAgo), $result['last_success']);
        self::assertSame($this->local($oldestPending), $result['oldest_pending']);
        self::assertSame($this->local($stuckSince), $result['stuck_jobs'][0]['executed_at']);
        // A success ten minutes ago is not "older than an hour": the comparison is still UTC.
        self::assertSame('warning', $result['status']);
    }

    #[Test]
    public function healthCheckWithoutAnySuccessReportsNullAndCritical(): void
    {
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($this->collection([], new DataObject()));

        $result = (new HealthCheckAction($factory, $this->storeTime()))->execute([], 1);

        self::assertNull($result['last_success']);
        self::assertNull($result['oldest_pending']);
        self::assertSame('critical', $result['status']);
    }

    #[Test]
    public function scheduleJobReportsTheStoresCurrentTimeAndStoresUtc(): void
    {
        $schedule = (new \ReflectionClass(Schedule::class))->newInstanceWithoutConstructor();
        $scheduleFactory = $this->createStub(ScheduleFactory::class);
        $scheduleFactory->method('create')->willReturn($schedule);

        $known = $this->collection([]);
        $known->method('getSize')->willReturnOnConsecutiveCalls(1, 0);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($known);

        $action = new ScheduleJobAction(
            $factory,
            $scheduleFactory,
            $this->createStub(ScheduleResource::class),
            $this->storeTime()
        );
        $result = $action->execute(['job_code' => 'indexer_reindex_all_invalid'], 1);

        $storedUtc = $schedule->getData('scheduled_at');
        self::assertSame($this->local($storedUtc), $result['scheduled_at']);
        self::assertNotSame($storedUtc, $result['scheduled_at']);
    }

    private function storeTime(): StoreTime
    {
        $config = $this->createStub(TimezoneInterface::class);
        $config->method('getConfigTimezone')->willReturn(self::ZONE);

        return new StoreTime($config);
    }

    private function local(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d H:i:s');
    }

    private function utcAgo(string $modifier): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($modifier)->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $data
     * @return DataObject
     */
    private function row(array $data): DataObject
    {
        return new DataObject($data);
    }

    /**
     * @param DataObject[] $rows
     * @return CollectionFactory
     */
    private function factoryOf(array $rows): CollectionFactory
    {
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($this->collection($rows));

        return $factory;
    }

    /**
     * @param DataObject[] $rows
     * @param DataObject|null $first
     * @return Collection&\PHPUnit\Framework\MockObject\Stub
     */
    private function collection(array $rows, ?DataObject $first = null): Collection
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));
        $collection->method('getFirstItem')->willReturn($first ?? new DataObject());

        return $collection;
    }
}
