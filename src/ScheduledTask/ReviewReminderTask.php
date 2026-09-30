<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ReviewReminderTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'topdata_product_review_reminder_s_w6.send_reminders';
    }

    /**
     * Sweep cadence, not the reminder delay — `delayDays` is the operator's
     * setting. 15 minutes is what bounds "the mail goes out shortly after the
     * delay elapses"; `scheduled-task:run` polls at least every 15s, so this
     * value is the real latency, not the 10-minute Ofelia cron that starts it.
     *
     * Deliberately a code default rather than something an operator sets in
     * the admin: Shopware only re-applies this value while `run_interval`
     * still equals the previously registered default, so anyone who edits the
     * interval by hand takes ownership of it permanently and silently.
     * One setting in one place means `delayDays` is the only knob.
     */
    public static function getDefaultInterval(): int
    {
        return 900;
    }
}