# Installation

## Requirements

- Shopware 6.7.*
- `topdata-foundation-sw6`, active (supplies `AbstractTopdataCommand` and
  `CliLogger`)
- One messenger worker consuming the `async` transport

## Steps

1. Place the plugin in `custom/plugins/topdata-product-review-reminder-sw6/`.
2. Create the log table:

   ```bash
   bin/console plugin:update TopdataProductReviewReminderSW6 --skip-asset-build
   ```

   This is what runs the migration. It creates
   `topdata_product_review_reminder_log` with a unique index on `order_id`.

   `created_at` and `updated_at` are not optional extras: Shopware's
   `EntityDefinition::defaultFields()` adds a `CreatedAtField` and an
   `UpdatedAtField` to **every** definition, so the DAL selects both columns on
   any read. A table without them fails at query time with
   `Unknown column ... created_at`, not at migration time.

3. After adding, removing or renaming any PHP class, refresh the plugin and
   clear the cache. This is required for `config.xml`, route and DI changes:

   ```bash
   bin/console plugin:refresh
   bin/console cache:clear
   ```

4. Verify:

   ```bash
   bin/console list topdata
   bin/console scheduled-task:list
   bin/console messenger:stats
   ```

   The plugin's task must appear as
   `topdata_product_review_reminder_s_w6.send_reminders`.

5. Restart the messenger worker. A running worker keeps the container it booted
   with, so a newly added task handler is silently never called until restart.

## After installing

The plugin is **disabled** by default and sends nothing. Before enabling it:

```bash
bin/console topdata:product-review-reminder:send --ignore-enabled
bin/console topdata:product-review-reminder:send --ignore-enabled --preview
```

Read the recipient table, open the rendered HTML, and only then consider
`--send`. See [../README.md](../README.md) for the full workflow and
[configuration.md](configuration.md) for the two settings.

## Uninstalling

`bin/console plugin:uninstall` drops the table via the migration's
`updateDestructive()`, taking the audit trail with it.
