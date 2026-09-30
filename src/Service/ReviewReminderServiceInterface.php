<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Framework\Context;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

interface ReviewReminderServiceInterface
{
    /**
     * @param list<string> $orderIds Limit to these orders, or empty for "all eligible"
     * @return list<ReviewReminderCandidate>
     */
    public function collectCandidates(Context $context, array $orderIds = []): array;
}