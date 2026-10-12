<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess;

use Magento\AsynchronousOperations\Model\MassSchedule;
use Magento\WebapiAsync\Model\Config as AsyncConfig;
use MagoAssistant\Mago\Service\Api\InProcess\WebapiAsyncQueue;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAsyncConfig;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeMassSchedule;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeModuleManager;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebapiAsyncQueueTest extends TestCase
{
    private const TOPIC = 'async.magoassistant.mago.api.webapi.indexermanagementinterface.reindex.post';

    #[Test]
    public function itRecordsTheBulkUnderTheAdminUser(): void
    {
        $massSchedule = new FakeMassSchedule();

        $this->queue($this->moduleManager(), $massSchedule)->publish(self::TOPIC, ['catalog_product_price'], 7);

        self::assertSame(
            [['topic' => self::TOPIC, 'entities' => [['catalog_product_price']], 'group_id' => null, 'user_id' => '7']],
            $massSchedule->published()
        );
    }

    #[Test]
    public function itTakesTheTopicNameFromTheAsyncWebApiConfig(): void
    {
        $topic = $this->queue($this->moduleManager(), new FakeMassSchedule())
            ->getTopicName('/V1/mago/indexers/reindex', 'POST');

        self::assertSame(self::TOPIC, $topic);
    }

    #[Test]
    public function itIsAvailableWhenBothAsyncModulesAreEnabled(): void
    {
        self::assertTrue($this->queue($this->moduleManager(), new FakeMassSchedule())->isAvailable());
    }

    #[Test]
    public function itIsUnavailableWhenTheStoreRemovedWebapiAsync(): void
    {
        $moduleManager = (new FakeModuleManager())->withEnabledModule('Magento_AsynchronousOperations');

        self::assertFalse($this->queue($moduleManager, new FakeMassSchedule())->isAvailable());
    }

    private function moduleManager(): FakeModuleManager
    {
        return (new FakeModuleManager())
            ->withEnabledModule('Magento_AsynchronousOperations')
            ->withEnabledModule('Magento_WebapiAsync');
    }

    private function queue(FakeModuleManager $moduleManager, FakeMassSchedule $massSchedule): WebapiAsyncQueue
    {
        return new WebapiAsyncQueue($moduleManager, new FakeObjectManager([
            MassSchedule::class => $massSchedule,
            AsyncConfig::class => new FakeAsyncConfig(['POST /V1/mago/indexers/reindex' => self::TOPIC]),
        ]));
    }
}
