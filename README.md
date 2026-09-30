# Topdata Product Review Reminder SW6

![Plugin Icon](src/Resources/config/plugin.png)

Sends customers a single email asking them to review the products in an order,
`delayDays` after the order date. Built on Shopware's native
`product_review` table, so the reviews themselves land in the product detail
page — this plugin only decides who to invite and when.

## Requirements

- Shopware 6.7.*
- A sales channel domain, so review links can be built
- `topdata-foundation-sw6` (provides `AbstractTopdataCommand` / `CliLogger`)

## What triggers a reminder

**The delay counts from `order.order_date`, not from delivery.** No order in
this shop ever reaches a shipped or completed state, so there is no delivery
date to hook. The plugin runs a daily sweep over orders whose `order_date` is
older than `delayDays`.

An order is eligible when all of the following hold:

- it is not `cancelled` (`in_progress` and `completed` are eligible too)
- it has a registered customer — a guest cannot log in, so it can never review
- it is not already in the log table with a `sent_at` timestamp
- at least one of its products is not already reviewed by that customer
  (variant and parent are both considered, since reviews aggregate over the
  family) and has a reachable detail page

## Installation

See [docs/installation.md](docs/installation.md).

## Configuration

See [docs/configuration.md](docs/configuration.md). Both settings live in
**Settings → System → Plugins → Topdata Product Review Reminder SW6**.

| Setting      | Default | Meaning                                                        |
|--------------|---------|----------------------------------------------------------------|
| `enabled`    | `false` | Master switch. The plugin ships **off** and sends nothing.       |
| `delayDays`  | `14`    | Days after `order_date` before the customer is invited. `0` = next run. |

## Running it

The console command is a **dry run unless `--send` is passed**.

```bash
# 1. Look at what would happen. Nothing is sent.
bin/console topdata:product-review-reminder:send

# 2. Render the actual mail HTML of the first candidate and open it.
bin/console topdata:product-review-reminder:send --preview
#    -> var/log/topdata-product-review-reminder-preview.html

# 3. Override the disabled master switch for this run.
bin/console topdata:product-review-reminder:send --ignore-enabled

# 4. Actually send. Asks for confirmation and defaults to ONE order.
bin/console topdata:product-review-reminder:send --send --ignore-enabled --limit=1
```

Options:

| Option            | Default | Meaning                                              |
|-------------------|---------|------------------------------------------------------|
| `--send`          | off     | Without it, nothing is sent.                          |
| `-o, --order`     | –       | Restrict to specific order IDs (repeatable).          |
| `-l, --limit`     | `1`     | Max orders processed in this run.                     |
| `--ignore-enabled`| off     | Run even though the plugin config has `enabled=false`.|
| `--preview`       | off     | Write the mail HTML to `var/log/`, never sends.       |

### Scheduled task

`topdata_product_review_reminder_s_w6.send_reminders` runs daily through the
messenger worker. It is a no-op while `enabled` is `false`.

```bash
bin/console scheduled-task:list
bin/console scheduled-task:run-single topdata_product_review_reminder_s_w6.send_reminders
```

> **A running worker holds a stale container.** After deploying or enabling the
> plugin, restart the messenger worker — otherwise the new task handler is never
> called. Note also that unrelated plugins in this shop register message
> handlers without a `handles:` argument, so they also receive this task's
> message.

## Log table

`topdata_product_review_reminder_log` is an append-only audit trail, one row per
invited order:

| Column               | Meaning                                                    |
|----------------------|------------------------------------------------------------|
| `order_id`           | Unique. This index is the concurrency authority.            |
| `order_version_id`   | Order version at the time of the send.                      |
| `customer_id`        | Recipient.                                                  |
| `sales_channel_id`   | Used for the mail's sales channel context.                  |
| `email`              | Address the mail went to.                                   |
| `review_product_ids` | JSON array of the product ids that were offered for review.  |
| `sent_at`            | `NULL` while only claimed, set once the mail was handed off. |
| `created_at`         | Set by the DAL on insert.                                   |
| `updated_at`         | Set by the DAL on update.                                   |

The claim is written **before** the mail is sent, and `sent_at` is stamped only
afterwards:

- claim insert hits the unique index → the order is already being handled, skip
- send fails → the claim is deleted, so the order is retried on the next run
- send succeeds → `sent_at` is stamped, so the order is never picked up again

A row with `sent_at IS NULL` is therefore a crashed run, not a sent reminder,
and stays eligible on purpose.

## ⚠️ This shop delivers real email

`core.mailerSettings` points at a live SMTP relay with delivery **enabled**, so
`--send` reaches real inboxes — including customers of focusshop.ch. Always
start with a dry run and `--preview`, check the recipient table, and keep
`enabled` off until you intend to send.

## License

MIT
