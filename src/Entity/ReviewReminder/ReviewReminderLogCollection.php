<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Entity\ReviewReminder;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ReviewReminderLogEntity>
 */
class ReviewReminderLogCollection extends EntityCollection
{
    protected string $entityClass = ReviewReminderLogEntity::class;
}