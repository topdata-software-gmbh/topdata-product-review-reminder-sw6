# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.1] - 2026-10-05

### Changed
- Renamed the `enabled` setting label from "Send review reminders" to "Active" (`Aktiv` in
  German). The switch is the master switch for the whole feature — sending *and* the consent
  checkbox — and the old label made it look like it only controlled sending. No behaviour change.
- Consent copy: dropped the "Ja, " prefix from the checkbox label in all locales ("Yes, " / "Oui, "
  accordingly), and the explanatory hint now renders in the smaller secondary text style.

## [1.2.0] - 2026-10-05

### Changed
- **Consent UI moved to `TopdataConsentSW6`.** The review-reminder checkbox is now rendered by the
  central consent plugin on its registration, profile and post-checkout surfaces. This plugin
  contributes a `ConsentProviderInterface` implementation
  (`src/Consent/ReviewReminderConsentProvider.php`, position 20, visible while `enabled` is on; on
  registration additionally gated by the new `consentCheckboxOnRegistration` setting) and keeps
  owning its consent storage. The never-wired account card, the form partial, the widget controller
  and the Twig accessor were removed. `topdata/consent-sw6` is now a hard dependency.

### Added
- `consentCheckboxOnRegistration` setting (default on) controlling whether the review-reminder
  checkbox appears on the registration form.
- Flat `storefront.de-DE.json` / `storefront.en-GB.json` snippet files so the consent label and hint
  resolve through `|trans` (locale-subfolder files are skipped by `SnippetFileLoader`; the
  mail-specific copy continues to be read directly from the subfolder files).

### Fixed
- Declining the review reminder from an "unknown" state now creates an explicit revoked row instead
  of silently doing nothing. Without it the purpose stayed undecided and the post-checkout card
  asked again.

## [1.1.0] - 2026-09-30

### Added
- Post-purchase review reminder emails: one per order, X days after order date
- Configurable via `enabled` (default off) and `delayDays` (default 14, where `0` means "invite on the next run")
- `topdata_product_review_reminder_log` audit table with a unique index on `order_id`
- Claim-before-send lifecycle: a unique-index claim prevents two runs from mailing the same order
- `topdata:product-review-reminder:send` console command, dry-run by default
- Daily scheduled task `topdata_product_review_reminder_s_w6.send_reminders`

### Fixed
- Every send failed with a `ConstraintViolationException`: the `AbstractMailService::send()` payload was missing `contentPlain`, which `MailService` validates with `NotBlank`. `senderName` was missing too and surfaced as an `ErrorException`, since `MailService::createMail()` reads it unguarded
- `delayDays = 0` was silently ignored and clamped to the default. The guard assumed `0` would notify every order on every run, but the unique index on `order_id` already guarantees one invitation per order
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
