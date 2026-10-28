<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ReviewManager;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Review\Model\ResourceModel\Review\Collection;
use Magento\Review\Model\Review;
use MagoAssistant\Mago\Service\Skills\Catalog\ReviewManager\ListApprovedAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ReviewManager\ListPendingAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ReviewManager\ReviewServices;
use MagoAssistant\Mago\Service\Time\StoreTime;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The review lists show created_at as the review grid does, on the store's clock.
 */
class ReviewDatesInStoreTimeTest extends TestCase
{
    #[Test]
    public function pendingAndApprovedReviewsShowCreatedAtOnTheStoresClock(): void
    {
        $actions = [[ListPendingAction::class, 'pending_reviews'], [ListApprovedAction::class, 'approved_reviews']];
        foreach ($actions as [$class, $key]) {
            $review = (new \ReflectionClass(Review::class))->newInstanceWithoutConstructor();
            $review->setData(['created_at' => '2026-10-07 22:30:00', 'entity_pk_value' => 3]);

            $collection = $this->createStub(Collection::class);
            $collection->method('getIterator')->willReturn(new \ArrayIterator([$review]));
            $services = $this->createStub(ReviewServices::class);
            $services->method('createCollection')->willReturn($collection);

            $config = $this->createStub(TimezoneInterface::class);
            $config->method('getConfigTimezone')->willReturn('Europe/Amsterdam');

            $action = new $class($services, $this->createStub(SecureAdminUrl::class), new StoreTime($config));

            self::assertSame('2026-10-08 00:30:00', $action->execute([], 1)[$key][0]['created_at'], $class);
        }
    }
}
