<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Model;

final readonly class ReviewReminderProduct
{
    public function __construct(
        public string $productId,
        public string $parentId,
        public string $name,
        public ?string $reviewUrl,
        public ?string $coverMediaUrl
    ) {
    }

    /**
     * The product a review is attached to. Reviews aggregate onto the parent
     * across a variant family, so a review left on the parent shows up for
     * every variant.
     */
    public function getReviewTargetId(): string
    {
        return $this->parentId !== '' ? $this->parentId : $this->productId;
    }
}