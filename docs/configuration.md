# Configuration

Both settings live in **Settings → System → Plugins → Topdata Product Review
Reminder SW6** and are read under the
`TopdataProductReviewReminderSW6.config.` prefix.

## `enabled`

| | |
|---|---|
| Type | `bool` |
| Default | `false` |
| Key | `TopdataProductReviewReminderSW6.config.enabled` |

Master switch for both entry points: the console command and the daily
scheduled task. While it is `false`:

- the scheduled task logs `Product review reminders are disabled — skipping run.`
  and does nothing
- the console command refuses to run unless `--ignore-enabled` is passed

The plugin ships disabled because this shop's SMTP relay has delivery enabled,
so a mistake would reach real customer inboxes. It is not enabled by a plugin
update or a config import.

## `delayDays`

| | |
|---|---|
| Type | `int` |
| Default | `14` |
| Key | `TopdataProductReviewReminderSW6.config.delayDays` |

How many days after the order date a customer is invited.

**This counts from `order.order_date`, not from delivery.** There is no
delivery date to count from: in this shop no order ever reaches a `shipped` or
`completed` state, so a delivery-triggered design would never fire. The
alternative — firing on a state transition — was rejected for the same reason.

Lowering the value makes the plugin eligible for orders that are already older
than the new threshold on the next run. The default command `--limit` of `1`
stops that from becoming a mass send.
