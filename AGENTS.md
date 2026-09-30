# AGENTS.md — Topdata Product Review Reminder SW6

Shopware 6.7 plugin for the `focus` project (focusshop.ch). Purpose: remind customers to review a product from a past order. **As of 1.1.0 the reminder feature is implemented**; scaffold from the original skeleton is still present — see "State of the code".

Project-level notes (container topology, DB creds, all sibling plugins) live in `/topdata/clones/focus/AGENTS.md`.

## Everything runs in Docker

Your cwd is the plugin dir; the app does not run on the host. `vol/www` on the host is `/www` in the container.

```bash
cd /topdata/clones/focus
docker-compose exec focus-www php /www/bin/console <command>            # console
docker-compose exec focus-mariadb mariadb -u root -p11111 sw6            # DB client
```

- Shopware **6.7.10.2**, PHP **8.4.21**, env `dev`, debug on. `docker-compose` walks up to find the compose file, so no `-f` needed from the plugin dir.
- The plugin is **already installed and active** in the DB (`TopdataProductReviewReminderSW6`, 1.0.0). After adding/removing PHP classes: `bin/console plugin:refresh`, then `bin/console cache:clear` (needed for `config.xml` / route / DI changes).
- One messenger worker is already running in `focus-www`: `messenger:consume --all --no-debug --time-limit=295`. Do not start a second one; anything you dispatch to `async` will be picked up without you.

### This is a live shop — do not send real email

`sw6` contains **4,959 customers, 106 orders, 19,832 products**. `core.mailerSettings` is a **remote SMTP relay** (`mailpit.topinfra.de:1025`, user `focusshop`) with `disableDelivery = false` — a "quick test send" reaches real inboxes. Always gate dispatch behind a limit/dry-run or an explicit recipient override, and confirm the address list before running.

**The plugin ships `enabled=false`** and stays that way through updates and config imports. Both entry points refuse to send while it is off: the scheduled task logs `Product review reminders are disabled — skipping run.`, and the command needs `--ignore-enabled`. Do not flip it on to "test" — use `--preview`, which renders the real HTML to `var/log/` and never sends.

## Domain facts discovered while building the reminder (do not rediscover these)

- **The customer is not on `order`.** `order` has no `customer_id` column; the account link is `order_customer.customer_id`, joined on `order_id` + `order_version_id`. See `loadEligibleOrders()` in `ReviewReminderService`.
- **There is no completed or in-progress order in this shop.** All 106 orders are `open` (92) or `cancelled` (14), so a trigger on a state transition would fire exactly zero times. The reminder is a **daily sweep** on `order_date` older than `delayDays` instead, and only `cancelled` is excluded.
- **Guests are excluded on purpose.** A review needs a logged-in customer (`ProductReviewSaveRoute`), so a guest can never act on an invitation.
- **Reviews aggregate over a variant family.** Both the variant id and the parent id count as "already reviewed", otherwise customers are re-nagged for the parent.
- **The mail's own copy cannot come from `snippet`.** In 6.7 `|trans` and `Shopware\Core\Framework\Adapter\Translation\Translator` read *only* the `snippet` table, scoped to a sales channel's snippet set. Plugin JSON under `src/Resources/snippet/` is loaded by `SnippetFileLoader` solely for the admin snippet editor and is never written to that table. A CLI/worker render therefore prints the literal key. `ReviewReminderTranslationService` reads the plugin's own JSON instead.
- **DAL write methods take id maps, not Criteria.** `EntityRepository::update()` and `delete()` take a list of `['id' => ...]`, and the writer rejects a non-list (`getElements()` is keyed by primary key, so wrap `array_map` in `array_values`). Only `search()` accepts a Criteria. This silently broke the `sent_at` stamp before it was caught.
- **A running messenger worker keeps its boot-time container.** New task handlers are never called until the worker restarts. Also note that several sibling plugins register `#[AsMessageHandler]` with no `handles:` argument and therefore receive *every* message on the transport, including this plugin's task.

## State of the code

The reminder feature (1.1.0) is implemented and verified by dry run, `--preview`
and a scripted claim/release lifecycle test. The **original skeleton is still
present** around it:

1. ~~`ExampleTaskHandler` fatals~~ — **fixed**, both parent constructor args are
   passed now. Reproduce with `bin/console scheduled-task:run-single topdata_product_review_reminder_s_w6.example`.
2. ~~The storefront example route 500s~~ — **removed**.
   `src/Controller/StorefrontExampleController.php` and
   `src/Resources/views/storefront/example.html.twig` were deleted: the template
   extended `@Storefront/storefront/page/content-section.html.twig`, removed in
   6.7, and its generic name was shadowed by other plugins anyway (see below).
3. **Twig template names are a global namespace across all installed plugins.** Five plugins ship an identical `src/Resources/views/storefront/example.html.twig`. Never scaffold generic filenames like `example.html.twig` / `default.html.twig`; prefix with this plugin. The mail template is `storefront/email/review-reminder.html.twig`.
4. `src/Resources/app/storefront/{src/js/main.js,src/scss/base.scss}` are **dead files** — there is no `package.json`, so the storefront build never picks them up. Adding one is the only way they load.
5. `src/TopdataProductReviewReminderSW6.php` is still an empty `Plugin` subclass — no install/uninstall hooks, no custom fields.
6. `ExampleCommand` writes raw `$output->writeln()` and `ExampleTask` still runs as `topdata_product_review_reminder_s_w6.example`. `ExampleSubscriber` hooks `ProductEvents::PRODUCT_WRITTEN_EVENT` and does nothing. None of these are referenced by the reminder feature — delete them when convenient.
7. There is **no test suite**: `tests/` is empty, `/www/vendor/bin/phpunit` does not exist, and the root `composer.json` has no `require-dev`. Do not claim tests pass; `php -l` plus console/DB checks are the available gates.

## Wiring — how new code gets registered

- `src/Resources/config/services.yaml` autowires the glob `'../../{Command,Controller,Entity,Service,Subscriber,ScheduledTask}'` with `autoconfigure: true, public: false`. **A new top-level namespace (e.g. `src/Message`) is silently ignored until you extend that glob** — the class then fails with "service not found" at runtime, not at build time. Migrations are intentionally *not* in it.
- Subscribers and message handlers are picked up by `autoconfigure` via `EventSubscriberInterface` / `#[AsMessageHandler]` — no manual tag needed (some sibling plugins tag manually; both work).
- `routes.xml` imports only `../../Controller/**/*Controller*.php` with `type="attribute"`. Controllers in other directories get no routes.
- `src/Migration/V<unix-ts>_<Name>.php` is auto-discovered by Shopware — no registration step.
- Snippet files live in `snippet/<locale>/topdata-product-review-reminder-sw6.json`; `en-GB` and `de-DE` both exist. Their root key is `TopdataProductReviewReminderSW6` so the copy stays editable in the admin snippet editor, but **runtime lookups go through `ReviewReminderTranslationService`, not the `snippet` table** — see the domain facts above.
- `config.xml` declares the two admin fields `enabled` (bool, default `false`) and `delayDays` (int, default `14`). `SystemConfigService::get()` reads them under the `TopdataProductReviewReminderSW6.config.` prefix.
- `Symfony\Component\Routing\Annotation\Route` (used by both example controllers) still works on Symfony 7.2.3 — it is a `class_alias` to `Attribute\Route`. Prefer `Attribute\Route` in new code.

## Shopware 6.7 facts worth knowing before you design

- **Native product reviews already exist.** `product_review` table + `Shopware\Core\Content\Product\Aggregate\ProductReview\ProductReviewDefinition`; storefront routes `ProductReviewRoute` / `ProductReviewSaveRoute`; core's `Checkout\Customer\Subscriber\ProductReviewSubscriber` keeps `point_count` in sync. Build on this — do not invent a second review store.
- **There is no "delivered" state.** `order_delivery.state` states are `open`, `hold`, `shipped`, `shipped_partially`, `cancelled`, `returned`, `returned_partially`. `order.state` is `open`, `in_progress`, `completed`, `cancelled`. To fire on fulfilment, hook `order_delivery.written` / `order.written` and diff the previous state — there is no `delivered_at` column on `order_delivery` (only `shipping_date_earliest` / `shipping_date_latest`).
- **Documents moved.** Everything is under `Shopware\Core\Checkout\Document\...` (not `Content\Document`, which does not exist in 6.7). `MailService::send(array $data, Context $context, array $templateData = [])` at `Shopware\Core\Content\Mail\Service\MailService` is the plain-mail entry point; the `Document` subsystem is the logged/attachment-capable path and writes to the `document` table.
- State changes are plain DAL writes, so there is no dedicated "order shipped" event — `EntityWrittenEvent` plus a `state_id` comparison is the pattern used by sibling plugins.
- **Twig precedence is global and cross-plugin**: theme first (`TopdataThemeFocusSW6` is active alongside the `Storefront` default theme), then plugins. If a template override "does nothing", suspect a name collision with another plugin before debugging your own code.
- Elasticsearch is disabled in this project; do not build on search indexing.

## Conventions

- **Command prefix `topdata:product-review-reminder:`** — do not abbreviate the vendor segment.
- **DB table prefix `topdata_product_review_reminder_`** for entities, definitions and migrations alike.
- Namespace `Topdata\TopdataProductReviewReminderSW6\`, PSR-4 from `src/`. Every file starts `<?php declare(strict_types=1);`.
- Constructor property promotion with `private readonly`; constructor injection resolved by FQCN.
- **`topdata-foundation-sw6` is installed and active** and provides `Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand` (adds `-t/--topic`, arg/option table, `done()` with memory + duration) and `Util\CliLogger`. Sibling plugins (`topdata-focus-migration-sw6`, `topdata-better-checkout-sw6`) route all CLI output through `CliLogger` and never call `$output->writeln()` directly. You can use it **without adding a composer `require`** — `KernelPluginLoader` calls `addPsr4()` for every active plugin — but be aware that ordering then depends on foundation being installed.
- This plugin is its **own git repo** on branch `main` with a single commit (`initial skeleton`). Commit per plugin, not per project.

## Verifying changes (there is no test suite)

- **PHPUnit is unavailable**: `tests/` is empty, `/www/vendor/bin/phpunit` does not exist anywhere in the container, and the Shopware root `composer.json` has no `require-dev`. Some sibling plugins ship a `phpunit.xml.dist` that cannot currently be executed. Do not claim tests pass.
- `bin/console list topdata` — confirms new commands are registered.
- `bin/console debug:router | grep -i reviewreminder` — confirms controller routes compiled.
- `bin/console scheduled-task:list` — shows task name, `status` and `next_execution_time`. The scaffold task is registered as `topdata_product_review_reminder_s_w6.example`.
- `bin/console messenger:stats` — queue depths across `async`, `low_priority`, `failed`.
- Storefront route smoke test (needs the Host header, plain `localhost` returns 400):
  `docker-compose exec focus-www bash -lc "curl -s -o /tmp/r.html -w 'HTTP %{http_code}\n' -H 'Host: focus.docker' http://localhost/productreviewremindersw6/example"`
- No linter, formatter, static analyser or CI exists at project or plugin level. `php -l` is the only syntax gate.
