<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ExampleTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'topdata_product_review_reminder_s_w6.example';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // 24 hours in seconds
    }
}