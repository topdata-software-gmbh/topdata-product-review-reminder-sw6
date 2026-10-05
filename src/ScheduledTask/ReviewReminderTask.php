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
     * Reconciliation cadence, NOT the reminder delay. `delayDays` is the
     * operator's setting and the delivery path is event-driven: every placed
     * order queues its own message with a `DelayStamp`, so the mail is due at
     * exactly the right moment. This sweep exists only to catch orders whose
     * queued message was lost — a queue row dropped by a deployment, a
     * dispatch that threw — which would otherwise leave a customer
     * permanently un-reminded with nobody noticing.
     *
     * Daily is the right frequency for that: it is a repair run, not the
     * primary path, and the unique index on `order_id` means it cannot
     * duplicate anything the event path already delivered.
     *
     * Deliberately a code default rather than something an operator sets in
     * the admin: Shopware only re-applies this value while `run_interval`
     * still equals the previously registered default, so anyone who edits the
     * interval by hand takes ownership of it permanently and silently.
     */
    public static function getDefaultInterval(): int
    {
        return 86400;
    }
}