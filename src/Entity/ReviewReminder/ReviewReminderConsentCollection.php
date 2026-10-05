<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Entity\ReviewReminder;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ReviewReminderConsentEntity>
 */
class ReviewReminderConsentCollection extends EntityCollection
{
    protected string $entityClass = ReviewReminderConsentEntity::class;
}