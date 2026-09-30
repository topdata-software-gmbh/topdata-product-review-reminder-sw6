<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Framework\Context;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

interface ReviewReminderMailerInterface
{
    /**
     * @return bool true only when the mail was handed to the transport
     */
    public function send(ReviewReminderCandidate $candidate, Context $context): bool;
}