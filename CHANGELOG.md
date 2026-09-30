# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-09-30

### Added
- Post-purchase review reminder emails: one per order, X days after order date
- Configurable via `enabled` (default off) and `delayDays` (default 14)
- `topdata_product_review_reminder_log` audit table with a unique index on `order_id`
- Claim-before-send lifecycle: a unique-index claim prevents two runs from mailing the same order
- `topdata:product-review-reminder:send` console command, dry-run by default
- Daily scheduled task `topdata_product_review_reminder_s_w6.send_reminders`

### Fixed
- `ExampleTaskHandler` fatal `ArgumentCountError` — `ScheduledTaskHandler` takes a logger argument in 6.7
- The log table was missing `created_at` / `updated_at`, which `EntityDefinition::defaultFields()` adds to every definition
- `markSent()` and `release()` passed a `Criteria` to `update()` / `delete()`, which take lists of id maps in 6.7; the `sent_at` stamp was never written, so orders stayed eligible forever

### Changed
- Mail copy is resolved from the plugin's own snippet JSON through `ReviewReminderTranslationService` instead of the `snippet` table, which never contains plugin keys in 6.7 and rendered the literal translation key into the mail

### Removed
- Unused storefront example controller and template (referenced a Twig parent removed in 6.7)

## [1.0.0] - 2026-09-30

### Added
- Initial release
