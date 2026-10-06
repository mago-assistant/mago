<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ReviewManager;

use Magento\Framework\ObjectManagerInterface;
use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\Review\Model\ResourceModel\Review\Collection;
use Magento\Review\Model\Review;

/**
 * The Magento_Review services, resolved when an action runs rather than injected. Stores remove the
 * Review module, and a constructor dependency on it would then break the whole tool registry, which
 * every admin page builds, and setup:di:compile. ReviewManager is unavailable without the module,
 * so these are only reached when it is installed.
 */
class ReviewServices
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function createReview(): Review
    {
        return $this->objectManager->create(Review::class);
    }

    public function getReviewResource(): ReviewResource
    {
        return $this->objectManager->get(ReviewResource::class);
    }

    public function createCollection(): Collection
    {
        return $this->objectManager->create(Collection::class);
    }
}
