<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ReviewManager;

use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\Review\Model\Review;
use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Service\Skills\Catalog\ReviewManager\ApproveAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ReviewManager\ReviewServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ApproveActionTest extends TestCase
{
    #[Test]
    public function approvingAPendingReviewWarnsItGoesLiveOnTheStorefront(): void
    {
        $action = $this->actionWithReviews([12 => Review::STATUS_PENDING]);

        self::assertInstanceOf(CautionedActionInterface::class, $action);
        self::assertSame(
            ['Review #12 goes live on its product page in the storefront right away: '
                . 'every shopper can read its title, text and rating.'],
            $action->getCautions(['review_id' => 12], 1)
        );
    }

    #[Test]
    public function anAlreadyApprovedOrUnknownReviewGetsThePlainCard(): void
    {
        $action = $this->actionWithReviews([12 => Review::STATUS_APPROVED]);

        self::assertSame([], $action->getCautions(['review_id' => 12], 1));
        self::assertSame([], $action->getCautions(['review_id' => 99], 1));
        self::assertSame([], $action->getCautions([], 1));
    }

    #[Test]
    public function anUnknownReviewIsStillReportedOnExecute(): void
    {
        $action = $this->actionWithReviews([]);

        self::assertSame(['error' => 'Review not found: 99'], $action->execute(['review_id' => 99], 1));
    }

    /**
     * @param array<int, int> $statusById
     * @return ApproveAction
     */
    private function actionWithReviews(array $statusById): ApproveAction
    {
        $resource = $this->createStub(ReviewResource::class);
        $resource->method('load')->willReturnCallback(
            function (Review $review, int $id) use ($statusById, $resource): ReviewResource {
                if (isset($statusById[$id])) {
                    $review->setData(['review_id' => $id, 'status_id' => $statusById[$id]]);
                }

                return $resource;
            }
        );

        $services = $this->createStub(ReviewServices::class);
        $services->method('createReview')->willReturnCallback(function (): Review {
            $review = (new \ReflectionClass(Review::class))->newInstanceWithoutConstructor();
            \Closure::bind(fn () => $this->_idFieldName = 'review_id', $review, Review::class)();

            return $review;
        });
        $services->method('getReviewResource')->willReturn($resource);

        return new ApproveAction($services);
    }
}
