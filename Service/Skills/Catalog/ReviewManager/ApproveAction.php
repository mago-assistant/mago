<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ReviewManager;

use Magento\Review\Model\Review;
use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class ApproveAction implements CautionedActionInterface
{
    public function __construct(
        private readonly ReviewServices $reviewServices
    ) {
    }

    public function getName(): string
    {
        return 'approve';
    }

    public function getDescription(): string
    {
        return 'Approve a pending review by review ID';
    }

    public function getParameterSchema(): array
    {
        return [
            'review_id' => [
                'type' => 'integer',
                'description' => 'The review ID to approve',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Review::reviews_all';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        // The ack message embeds a bare review id, too short for the vault-conceal pass to catch;
        // success alone tells the model the write worked.
        return [
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::STRIP],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getCautions(array $params, int $adminUserId): array
    {
        $review = $this->loadReview((int)($params['review_id'] ?? 0));
        if ($review === null || (int)$review->getStatusId() === Review::STATUS_APPROVED) {
            return [];
        }

        return [
            'Review #' . (int)$review->getId() . ' goes live on its product page in the storefront right away: '
                . 'every shopper can read its title, text and rating.',
        ];
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $reviewId = (int)($params['review_id'] ?? 0);
        if (!$reviewId) {
            return ['error' => 'review_id is required'];
        }

        $review = $this->loadReview($reviewId);
        if ($review === null) {
            return ['error' => 'Review not found: ' . $reviewId];
        }

        $review->setStatusId(Review::STATUS_APPROVED);
        $this->reviewServices->getReviewResource()->save($review);
        $review->aggregate();

        return [
            'success' => true,
            'message' => 'Review #' . $reviewId . ' has been approved',
        ];
    }

    private function loadReview(int $reviewId): ?Review
    {
        if (!$reviewId) {
            return null;
        }

        $review = $this->reviewServices->createReview();
        $this->reviewServices->getReviewResource()->load($review, $reviewId);

        return $review->getId() ? $review : null;
    }
}
