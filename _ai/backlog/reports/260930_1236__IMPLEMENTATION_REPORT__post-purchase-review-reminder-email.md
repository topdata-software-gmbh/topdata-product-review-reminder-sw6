---
filename: "_ai/backlog/reports/260930_1236__IMPLEMENTATION_REPORT__post-purchase-review-reminder-email.md"
title: "Report: Post-purchase product review reminder email"
createdAt: 2026-09-30 11:30
updatedAt: 2026-09-30 11:30
planFile: "_ai/backlog/active/260930_1236__IMPLEMENTATION_PLAN__post-purchase-review-reminder-email.md"
project: "topdata-product-review-reminder-sw6"
status: completed
filesCreated: 16
filesModified: 10
filesDeleted: 2
tags: [shopware6, plugin, mail, product-review, scheduled-task]
documentType: IMPLEMENTATION_REPORT
---

## Summary

Implemented the post-purchase review reminder for Shopware 6.7: a daily sweep
that invites customers to review the products of an eligible order once, `delayDays`
after the order date. Selection, URL building and dispatch are three separate
services behind interfaces; the audit trail lives in
`topdata_product_review_reminder_log` with a unique index on `order_id` that
acts as a claim-before-send guard.

Delivered dry-run-first: `enabled` ships `false`, the console command sends
nothing without `--send`, `--limit` defaults to `1`, and an interactive
confirmation gates the real send. `--preview` was added on top of the plan to
render the actual mail HTML to `var/log/` for inspection.

## Files Changed

### Created
- `src/Migration/V1767214800_CreateReviewReminderLogTable.php`
- `src/Entity/ReviewReminder/ReviewReminderLogDefinition.php`
- `src/Entity/ReviewReminder/ReviewReminderLogEntity.php`
- `src/Entity/ReviewReminder/ReviewReminderLogCollection.php`
- `src/Model/ReviewReminderCandidate.php`
- `src/Model/ReviewReminderProduct.php`
- `src/Service/ReviewReminderConfigService.php`
- `src/Service/ReviewReminderService.php` + `ReviewReminderServiceInterface.php`
- `src/Service/ReviewReminderUrlBuilder.php`
- `src/Service/ReviewReminderMailer.php` + `ReviewReminderMailerInterface.php`
- `src/Service/ReviewReminderTemplateRenderer.php`
- `src/Service/ReviewReminderTranslationService.php` *(not in the plan — see D1)*
- `src/Service/ReviewReminderLogService.php`
- `src/Service/ReviewReminderDispatcher.php`
- `src/Command/SendReviewRemindersCommand.php`
- `src/ScheduledTask/ReviewReminderTask.php` + `ReviewReminderTaskHandler.php`
- `src/Resources/views/storefront/email/review-reminder.html.twig`

### Modified
- `src/Resources/config/services.yaml` — `Entity` added to the glob, interface
  aliases, `AbstractMailService` alias, repository arguments by service id
- `src/Resources/config/config.xml` — `enabled` (bool, `false`), `delayDays` (int, `14`)
- `src/Resources/snippet/{de-DE,en-GB}/topdata-product-review-reminder-sw6.json`
- `src/ScheduledTask/ExampleTaskHandler.php` — both parent constructor args
- `composer.json` (1.1.0), `CHANGELOG.md`, `README.md`, `docs/configuration.md`,
  `docs/installation.md`, `AGENTS.md`

### Deleted
- `src/Controller/StorefrontExampleController.php`
- `src/Resources/views/storefront/example.html.twig`

## Deviations from the plan

### D1 — Mail copy no longer comes from the `snippet` table (largest deviation)

The plan resolved the body copy with `{{ "…"|trans }}` and the subject by
reading the `snippet` table through `SnippetService::findSnippetSetId()`.

**This cannot work in Shopware 6.7.** `Shopware\Core\Framework\Adapter\Translation\Translator`
resolves exclusively from the `snippet` table via
`SnippetService::getStorefrontSnippets()`, scoped to a sales channel's snippet
set. `SnippetFileLoader` builds a `SnippetFileCollection` that is consumed only
by `SnippetController` and `SnippetValidator` — nothing writes plugin JSON under
`src/Resources/snippet/` into that table. The first `--preview` run rendered
`TopdataProductReviewReminderSW6.reviewReminderHeadline` literally into the mail
body and subject.

`ReviewReminderTranslationService` now reads the plugin's own snippet JSON
directly, with `de-DE` / `en-GB` files as the editable source of truth and an
in-class built-in copy as a last resort so a missing file cannot break a send.
The Twig template takes a pre-resolved `labels` map instead of calling `|trans`.

The plan's own risk table predicted this failure ("Snippets silently rendering
raw keys") but prescribed the wrong mitigation — a matching JSON root key. The
root key is unchanged; it simply is not what `trans` reads.

### D2 — Migration gained `created_at` / `updated_at`

The plan's `CREATE TABLE` had 8 columns. `EntityDefinition::defaultFields()`
adds a `CreatedAtField` and `UpdatedAtField` to **every** definition, so the DAL
selects both on any read. Without them the plugin fails at query time with
`Unknown column 'topdata_product_review_reminder_log.created_at'` — not at
migration time. The table was recreated and re-verified with 10 columns.

### D3 — `LongTextField` instead of `TextField`

`Shopware\Core\Framework\DataAbstractionLayer\Field\TextField` does not exist in
6.7. The unbounded text field is `LongTextField`.

### D4 — `Entity::getTranslation()` takes a field name

`getTranslation()` in 6.7 is `getTranslation(string $field): mixed|null`, not a
fluent accessor. `ReviewReminderUrlBuilder::loadProductName()` uses
`getTranslation('name')`.

### D5 — `--preview` flag added

Not in the plan. It renders the real mail HTML to
`var/log/topdata-product-review-reminder-preview.html` without sending, which is
the only safe way to verify template, copy and links on a shop whose SMTP relay
delivers.

### D6 — Product without a reachable URL drops the order

The plan left `reviewUrl` nullable but had no rule. An invitation with no link is
worse than no invitation, so a product without a detail page is dropped and the
order is skipped for this run (it stays eligible once the domain or SEO URL
exists).

### D7 — `languageLocale` carried on the candidate

The plan resolved the locale in `ReviewReminderMailer` via a `language.repository`
lookup. The candidate now carries the resolved locale code from one batched
lookup in the selection service, which keeps the send path free of queries and
removes two repository dependencies from the mailer.

## Key Changes

- **Claim before send.** `ReviewReminderLogService::claim()` inserts the row
  before the mail call; `UniqueConstraintViolationException` means another run
  owns the order and increments `skipped`. `markSent()` stamps `sent_at` only
  after a successful hand-off; `release()` deletes an unstamped claim so a
  failed send is retried and a crashed run stays eligible.
- **Guest exclusion** via `order_customer.customer_id` — `order` has no
  `customer_id` column.
- **Variant-family collapsing**: line items resolve to the parent product id, so
  three sizes produce one review request, and both ids count as already reviewed.
- **Locale copy from plugin JSON** (D1), with `gsw-CH`/`de-CH`/`fr-CH` mapped to
  the `de-DE` file.

## Bugs found during verification (all fixed)

| Bug | Impact |
|---|---|
| `markSent()` passed a `Criteria` to `EntityRepository::update()` | `sent_at` was **never written**, so every order stayed eligible forever and customers would be re-invited daily |
| `release()` passed a `Criteria` to `delete()`, and a bare id list | Every failed send threw a `TypeError` instead of releasing the claim |
| `getElements()` is keyed by primary key | The DAL writer rejects non-lists; needed `array_values()` around `array_map` |
| Missing `created_at` / `updated_at` columns (D2) | Any DAL read of the log table failed |

All three DAL write bugs came from the same root cause: in 6.7 only `search()`
takes a `Criteria`; `update()` and `delete()` take lists of id maps.

## Testing Notes

PHPUnit is **unavailable** in this project: `tests/` is empty,
`/www/vendor/bin/phpunit` does not exist, and the root `composer.json` has no
`require-dev`. No unit or integration test was written or run. Do not read the
following as a passing test suite.

What was verified:

- `php -l` clean across every PHP file in `src/`.
- `plugin:refresh` records version 1.1.0; `cache:clear` succeeds (container
  compiles, so all DI wiring resolves).
- Migration applied; table has 10 columns, unique `order_id`, customer and
  `sent_at` indices.
- Dry run: 91 eligible orders from 106 (14 cancelled, 1 dropped), correct
  recipients, product counts and absolute review URLs.
- `--preview` renders real HTML: German copy for `gsw-CH`, product names,
  cover images and `#review-form` links all resolve.
- Claim lifecycle, exercised with a throwaway kernel-boot script against the
  live schema: claim #1 `true`, claim #2 `false` (unique index), `release()`
  drops the unstamped claim, `markSent()` stamps it, the selector then excludes
  the order, and `release()` on a stamped row is a no-op (no re-invite).
- Scheduled task registered as `topdata_product_review_reminder_s_w6.send_reminders`
  and run synchronously via `scheduled-task:run-single`, where it logged
  `Product review reminders are disabled — skipping run.`

What could **not** be verified:

- **No mail was ever sent.** SMTP delivery is live, so the send path
  (`AbstractMailService::send()` returning a non-null `MailEntity`) is
  untested end-to-end. Only rendering and the claim lifecycle are proven.
- The messenger worker has a stale boot-time container, so the new handler was
  only exercised synchronously. A worker restart is required before the task
  runs in production.
- Multi-locale rendering is verified for `gsw-CH` → German; `en-GB` was only
  unit-probed via the translation service.

**No real email was sent during this work.** `topdata_product_review_reminder_log`
is empty and `enabled` is `false`.

## CLI Usage

```bash
# Dry run — nothing is sent (refuses while enabled=false)
bin/console topdata:product-review-reminder:send --ignore-enabled

# Render the mail HTML without sending
bin/console topdata:product-review-reminder:send --ignore-enabled --preview
# -> var/log/topdata-product-review-reminder-preview.html

# Real send: interactive confirmation, one order by default
bin/console topdata:product-review-reminder:send --send --ignore-enabled --limit=1

# Specific orders
bin/console topdata:product-review-reminder:send --ignore-enabled -o <orderId>

# Task
bin/console scheduled-task:list
bin/console scheduled-task:run-single topdata_product_review_reminder_s_w6.send_reminders
```

## Documentation Updates

- `README.md` — purpose, trigger semantics, eligibility rules, CLI workflow,
  log table, live-mailer warning.
- `docs/configuration.md` — both settings, and that `delayDays` counts from
  `order.order_date` because no order here reaches a shipped or completed state.
- `docs/installation.md` — migration step, refresh/cache sequence, worker
  restart caveat, uninstall behaviour.
- `AGENTS.md` — recorded `enabled=false`, the `order_customer` join, the
  absence of terminal order states, the 6.7 `snippet` behaviour, the DAL write
  signature rule and the stale-worker gotcha.

## Next Steps

1. Restart the messenger worker so the task handler is actually picked up.
2. Decide on French copy: `fr-CH` currently falls back to German. Add
   `snippet/fr-CH/` if that matters.
3. Enable `enabled` in the admin only after reviewing a preview for a
   de-CH and an en-GB order.
4. Consider deleting the remaining scaffold: `ExampleCommand`, `ExampleTask`,
   `ExampleTaskHandler`, `ExampleSubscriber`, `AdminApiExampleController`.
5. Consider batching the per-product queries in `ReviewReminderUrlBuilder`; the
   plan promised three batched queries but URL construction still issues
   per-product lookups.