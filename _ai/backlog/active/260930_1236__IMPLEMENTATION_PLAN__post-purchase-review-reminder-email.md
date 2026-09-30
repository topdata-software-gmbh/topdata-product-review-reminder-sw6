---
filename: "_ai/backlog/active/260930_1236__IMPLEMENTATION_PLAN__post-purchase-review-reminder-email.md"
title: "Post-Purchase Product Review Reminder Email"
createdAt: 2026-09-30 12:36
updatedAt: 2026-09-30 12:36
status: draft
priority: medium
tags: [shopware, sw6.7, email, product-review, scheduled-task, plugin]
estimatedComplexity: moderate
documentRevision: 1
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem

The shop has no mechanism to ask customers to review what they bought. Shopware 6.7 core *has* a complete product-review subsystem — a `product_review` table, a DAL aggregate, a storefront review form — but nothing ever invites anyone to use it. Core only renders the review form passively on the product detail page, so a customer who bought a product and never returns to it will never review it.

The business wants an automated reminder email, sent a configurable number of days **X** after purchase, containing a direct link to the review page for each product in that order.

Constraints discovered during design, all verified against the running system:

| Constraint | Evidence |
|---|---|
| Saving a review **requires a logged-in customer** | `store-api.product-review.save` has `defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true]` |
| **Guest orders cannot be reminded** usefully | Follows from the above; agreed with user |
| **No order ever reaches a terminal state in this shop** | 106 orders: 92 `open`, 14 `cancelled`, 0 `in_progress`/`completed`. Deliveries: 94 `open`, 12 `hold`, 0 `shipped` |
| `order` has **no `customer_id` column** | Account link lives in `order_customer.customer_id`, joined on `order_id` + `order_version_id` |
| The review form **is** on the product detail page | `cms_block` of type `product-description-reviews` exists; live page contains `id="review-form"` |
| This is a **live shop with real recipients** | 4,959 customers, 106 orders. Mailer is a *remote* SMTP relay (`mailpit.topinfra.de:1025`) with `disableDelivery = false` |
| **PHPUnit is unavailable** | `/www/vendor/bin/phpunit` does not exist; root `composer.json` has no `require-dev`; `tests/` is empty |

**Consequence of the "no terminal state" finding:** the trigger cannot be a state transition. It is a daily sweep of orders whose `order_date` is older than X days. Anything hook-based would fire zero times here.

## 2. Executive Summary

A daily scheduled task selects eligible orders — placed more than `delayDays` ago, not cancelled, linked to a customer account, not already reminded — expands their line items, collapses variants to their parent product, drops products the customer has already reviewed, and sends **one email per order** listing each remaining product with a link to its product-detail page anchored at `#review-form`.

Three safety properties are deliberate and non-negotiable:

1. **`enabled` ships as `false`.** Nothing sends until an admin turns it on.
2. **The CLI command is dry-run by default.** `topdata:product-review-reminder:send` prints exactly who *would* be emailed and what they *would* receive. Real sending requires an explicit `--send`.
3. **Once-per-order is enforced by a unique index** on `topdata_product_review_reminder_log.order_id`, not by application logic. Race conditions cannot double-send.

Solid design: candidate *selection*, *URL building*, and *dispatch* are three separate services behind interfaces. Adding a channel-specific rule or a different transport later touches one class, not three.

## 3. Project Environment

- Project Name: SW6.7 Plugin — `topdata-product-review-reminder-sw6`
- Backend root: `src`
- PHP Version: 8.4 (verified `PHP 8.4.21` in container)
- Shopware: `6.7.10.2`, env `dev`, debug on
- Symfony: `7.2.3` (the conventions reference names 7.4; the running instance is 7.2.3 — do not use APIs newer than it)
- PSR-4 root: `Topdata\TopdataProductReviewReminderSW6\` → `src/`
- Command prefix: `topdata:product-review-reminder:`
- Table prefix: `topdata_product_review_reminder_`
- CLI base class: `Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand` (from `topdata-foundation-sw6`, installed and active)
- CLI output: `Topdata\TopdataFoundationSW6\Util\CliLogger` only

**All commands run in Docker from the host repo root:**

```bash
cd /topdata/clones/focus
docker-compose exec focus-www php /www/bin/console <command>
docker-compose exec focus-mariadb mariadb -u root -p11111 sw6
```

### 3.1 Deviations from the supplied conventions (deliberate, verified)

| Convention says | This plan uses | Why |
|---|---|---|
| Table prefix `{abbreviation}_` (e.g. `tdprr_`) | `topdata_product_review_reminder_` | The repo's committed `AGENTS.md` mandates this exact prefix for this plugin. Repo-specific rule beats generic guidance. |
| Commands extend `\Topdata\TopdataFoundationSW6\TopdataFoundationSW6` | `AbstractTopdataCommand` | The class named in the conventions does not exist. Verified against source: `Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand`. |
| Snippets at `src/Resources/snippet/storefront.<locale>.json` | `src/Resources/snippet/<locale>/topdata-product-review-reminder-sw6.json` | Existing repo files use locale subdirectories + plugin-name.json. Matching them avoids a duplicate, conflicting snippet tree. |
| Test with PHPUnit 11 / `composer phpstan` / `composer cs` | `php -l` + console verification | PHPUnit binary does not exist in the container; no `require-dev`; no linter, formatter or CI at project or plugin level. **Do not claim tests pass.** |
| Symfony 7.4 | Symfony 7.2.3 | Verified in `composer.lock`. |

### 3.2 Notable API facts verified in core source

- `Shopware\Core\Content\Mail\Service\AbstractMailService::send(array $data, Context $context, array $templateData = [])` returns `?Email` — **`null` on failure, it does not throw**. Inject the *abstract* class so the route stays decoratable (matches `DvsnFormBuilder`).
- `$data` requires `recipients`, `salesChannelId`, `contentHtml`; optional `subject`, `senderName`, `senderAddress`, `mailTemplateId`.
- `SystemConfigService` accessors: `getBool()`, `getInt()`, `getString()`, each with an optional `?string $salesChannelId`.
- `ScheduledTaskHandler::__construct(EntityRepository $scheduledTaskRepository, LoggerInterface $exceptionLogger)` — **two** arguments.
- `config.xml` `<input-field>` type enum accepts `int`, `bool`, `text`, `textarea`. There is **no** `number` type.
- `#[Entity]` attribute: `Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity`.
- `CliLogger` real method names: `info`, `note` (not `notice`), `warning`, `error`, `success`, `writeln`, `write`, `section`, `title`, `progress`, `progressBar`, `debug`, `newLine`, `mem`, `lap`, `setCliStyle`, `getCliStyle`.
- `AbstractTopdataCommand::initialize()` already calls `CliLogger::setCliStyle()` — subclasses must not repeat it.

---

## 4. Phase 0 — Foundations & Wiring

**Goal:** make new namespaces registrable, and remove the two verified breakages in the scaffold so later phases build on clean ground.

### 4.1 `src/Resources/config/services.yaml` — [MODIFY]

Add `Entity` to the autowire glob. **Without this, entity definitions compile but cannot be autowired, and the failure surfaces at runtime as "service not found", not at build time.**

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    # NOTE: 'Entity' was added so ReviewReminderLogDefinition is registrable.
    # A new top-level namespace MUST be added here or its classes are silently ignored.
    # 'Migration' is intentionally absent — migrations are not services.
    Topdata\TopdataProductReviewReminderSW6\:
        resource: '../../{Command,Controller,Entity,Service,Subscriber,ScheduledTask}'
```

### 4.2 `src/ScheduledTask/ExampleTaskHandler.php` — [MODIFY]

Fix the verified `ArgumentCountError`. The scaffold passes one argument to a two-argument parent constructor.

> **Partial snippet.** This block is the constructor body only — the surrounding `use` statements and class declaration already exist in the file. Add `use Symfony\Component\DependencyInjection\Attribute\Autowire;` and `use Psr\Log\LoggerInterface;` if they are not already imported.

```php
public function __construct(
    EntityRepository $scheduledTaskRepository,
    LoggerInterface $exceptionLogger,
    #[Autowire('%kernel.logs_dir%')]
    private readonly string $logDir
) {
    parent::__construct($scheduledTaskRepository, $exceptionLogger);
}
```

### 4.3 `src/Resources/views/storefront/example.html.twig` — [DELETE]

Delete this file. It is dead scaffolding that **currently breaks the storefront**: it extends `@Storefront/storefront/page/content-section.html.twig`, removed in 6.7, and its generic name is shadowed by four other plugins' identical files. Confirm before deleting that `StorefrontExampleController` is also removed (Phase 5).

### 4.4 Verification

```bash
docker-compose exec focus-www php /www/bin/console plugin:refresh
docker-compose exec focus-www php /www/bin/console cache:clear
docker-compose exec focus-www php /www/bin/console scheduled-task:run-single topdata_product_review_reminder_s_w6.example
```

Expected: no `ArgumentCountError`. The handler writes `var/log/topdata-product-review-reminder-sw6.log`.

---

## 5. Phase 1 — Data Layer

**Goal:** one append-only log table enforcing once-per-order, plus its DAL entity.

### 5.1 `src/Migration/V1767214800_CreateReviewReminderLogTable.php` — [NEW FILE]

> The repo's committed `AGENTS.md` specifies `V<unix-ts>_<Name>`. The sibling `topdata-better-checkout-sw6` uses `Migration<unix_ts><Name>` and is confirmed present in the `migration` table — both forms are accepted.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Migration;

use Doctrine\DBAL\Schema\Schema;
use Shopware\Core\Framework\Migration\MigrationStep;

class V1767214800_CreateReviewReminderLogTable extends MigrationStep
{
    private const TABLE = 'topdata_product_review_reminder_log';

    public function getDescription(): string
    {
        return 'Create the topdata_product_review_reminder_log table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->createTable(self::TABLE);
        $table->addColumn('id', 'binary', ['length' => 16, 'fixed' => true]);
        $table->addColumn('order_id', 'binary', ['length' => 16, 'fixed' => true]);
        $table->addColumn('order_version_id', 'binary', ['length' => 16, 'fixed' => true]);
        $table->addColumn('customer_id', 'binary', ['length' => 16, 'fixed' => true]);
        $table->addColumn('sales_channel_id', 'binary', ['length' => 16, 'fixed' => true]);
        $table->addColumn('email', 'string', ['length' => 255]);
        $table->addColumn('review_product_ids', 'text', ['notnull' => false]);
        $table->addColumn('sent_at', 'datetime', ['notnull' => false]);

        $table->setPrimaryKey(['id']);

        // Enforces "once per order" in the database, not in application code.
        $table->addUniqueIndex(['order_id'], 'topdata_product_review_reminder_uniq_order');
        $table->addIndex(['customer_id'], 'topdata_product_review_reminder_idx_customer');

        // Append-only audit trail. No versionId column: this table is never
        // versioned or edited after insert, so the VersionManager is pure overhead.
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(self::TABLE);
    }
}
```

### 5.2 `src/Entity/ReviewReminder/ReviewReminderLogDefinition.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Entity\ReviewReminder;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TextField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

#[Entity(
    name: 'topdata_product_review_reminder_log',
    collectionClass: ReviewReminderLogCollection::class
)]
class ReviewReminderLogDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'topdata_product_review_reminder_log';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return ReviewReminderLogCollection::class;
    }

    public function getEntityClass(): string
    {
        return ReviewReminderLogEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),

            // IdField, NOT StringField. The migration declares binary(16);
            // StringField serialises to varchar and would store a 32-char hex
            // string into 16 bytes. IdFieldSerializer is what converts to/from
            // the binary representation. Core does the same (see
            // OrderLineItemDefinition's FkField for order_id).
            (new IdField('order_id', 'orderId'))->addFlags(new Required()),
            (new IdField('order_version_id', 'orderVersionId'))->addFlags(new Required()),
            (new IdField('customer_id', 'customerId'))->addFlags(new Required()),
            (new IdField('sales_channel_id', 'salesChannelId'))->addFlags(new Required()),

            (new StringField('email', 'email'))->addFlags(new Required()),
            (new TextField('review_product_ids', 'reviewProductIds')),
            (new DateTimeField('sent_at', 'sentAt')),
        ]);
    }
}
```

> **`review_product_ids` is a `TextField` holding a JSON array, not a `JsonField`.** The definition declares it as a `JsonField`; a `TextField` avoids the `JsonField`'s `null`-vs-`[]` ambiguity on insert. If a real `JsonField` is preferred, change *both* sides together (migration `text` + definition `JsonField`) and re-check the entity's `?string $reviewProductIds` type. Do not leave the two out of sync.

> **No custom hydrator.** The default `Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityHydrator` is used; the `#[Entity]` attribute omits `hydratorClass`. Do not add one — in 6.7 `EntityHydrator::hydrate()` is `public` with an 8-argument signature, so the older `protected function hydrate(array $data, array $writeResult): void` override pattern no longer exists and would be a fatal error.
>
> **`collectionClass` only.** `ReviewReminderLogCollection extends EntityCollection`; nothing else is customisable here.

### 5.3 `src/Entity/ReviewReminder/ReviewReminderLogEntity.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Entity\ReviewReminder;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

class ReviewReminderLogEntity extends Entity
{
    protected string $orderId;
    protected string $orderVersionId;
    protected string $customerId;
    protected string $salesChannelId;
    protected string $email;
    protected ?string $reviewProductIds;
    protected ?\DateTimeInterface $sentAt;

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getOrderVersionId(): string
    {
        return $this->orderVersionId;
    }

    public function setOrderVersionId(string $orderVersionId): void
    {
        $this->orderVersionId = $orderVersionId;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function setCustomerId(string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getReviewProductIds(): ?string
    {
        return $this->reviewProductIds;
    }

    public function setReviewProductIds(?string $reviewProductIds): void
    {
        $this->reviewProductIds = $reviewProductIds;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeInterface $sentAt): void
    {
        $this->sentAt = $sentAt;
    }
}
```

> **Important:** do *not* use constructor property promotion on a DAL entity. The hydrator assigns declared properties by name, so they must be non-promoted and non-readonly.

### 5.4 `src/Entity/ReviewReminder/ReviewReminderLogCollection.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Entity\ReviewReminder;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ReviewReminderLogEntity>
 */
class ReviewReminderLogCollection extends EntityCollection
{
    protected string $entityClass = ReviewReminderLogEntity::class;
}
```

### 5.5 Verification

```bash
docker-compose exec focus-www php /www/bin/console plugin:refresh
docker-compose exec focus-www php /www/bin/console cache:clear
docker-compose exec focus-mariadb mariadb -u root -p11111 sw6 -e "DESCRIBE topdata_product_review_reminder_log;"
docker-compose exec focus-mariadb mariadb -u root -p11111 sw6 -e "SHOW INDEX FROM topdata_product_review_reminder_log;"
```

Expected: 8 columns; unique index on `order_id`; index on `customer_id`.

---

## 6. Phase 2 — Domain Models & Candidate Selection

**Goal:** pure, testable value objects plus the query that finds eligible orders. No mail, no URLs — this phase is selection only.

### 6.1 `src/Model/ReviewReminderProduct.php` — [NEW FILE]

> `src/Model/` is deliberately **not** added to the `services.yaml` glob. These are immutable value objects constructed by the service, not injected services.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Model;

final readonly class ReviewReminderProduct
{
    public function __construct(
        public string $productId,
        public string $parentId,
        public string $name,
        public ?string $reviewUrl,
        public ?string $coverMediaUrl
    ) {
    }

    /**
     * The ID a review should be attached to. Variants aggregate onto their
     * parent, so a review left on the parent is shown for every variant.
     */
    public function getReviewTargetId(): string
    {
        return $this->parentId !== '' ? $this->parentId : $this->productId;
    }
}
```

### 6.2 `src/Model/ReviewReminderCandidate.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Model;

final readonly class ReviewReminderCandidate
{
    /**
     * @param list<ReviewReminderProduct> $products
     */
    public function __construct(
        public string $orderId,

        /** Customer-facing order number, e.g. "SW10042". Shown in the email. */
        public string $orderNumber,
        public string $orderVersionId,
        public string $customerId,
        public string $salesChannelId,
        public string $email,
        public string $languageId,
        public string $customerFirstName,
        public \DateTimeInterface $orderDate,
        public array $products
    ) {
    }

    /**
     * @return list<string>
     */
    public function getReviewTargetIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (ReviewReminderProduct $p): string => $p->getReviewTargetId(),
            $this->products
        )));
    }
}
```

### 6.3 `src/Service/ReviewReminderConfigService.php` — [NEW FILE]

Single definition of every setting, so command and task can never disagree.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

final readonly class ReviewReminderConfigService
{
    private const PREFIX = 'TopdataProductReviewReminderSW6.config.';

    public const DEFAULT_DELAY_DAYS = 14;

    public function __construct(private SystemConfigService $systemConfigService)
    {
    }

    public function isEnabled(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(self::PREFIX . 'enabled', $salesChannelId);
    }

    public function getDelayDays(?string $salesChannelId = null): int
    {
        $days = $this->systemConfigService->getInt(self::PREFIX . 'delayDays', $salesChannelId);

        // Guard against a hand-typed 0, which would notify every order on every run.
        return $days > 0 ? $days : self::DEFAULT_DELAY_DAYS;
    }
}
```

### 6.4 `src/Service/ReviewReminderServiceInterface.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Framework\Context;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

interface ReviewReminderServiceInterface
{
    /**
     * @param list<string> $orderIds Limit to specific orders, or empty for "all eligible"
     * @return list<ReviewReminderCandidate>
     */
    public function collectCandidates(Context $context, array $orderIds = []): array;
}
```

### 6.5 `src/Service/ReviewReminderService.php` — [NEW FILE]

The selection engine. Three batched queries — no per-order queries, no N+1.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\Aggregate\ProductReview\ProductReviewDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotEqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderProduct;

final class ReviewReminderService implements ReviewReminderServiceInterface
{
    /**
     * Hard ceiling on a single run. 106 orders exist today, so this is only a
     * guard against a future bulk backfill loading the whole order table at
     * once. Deliberately a constant, not config — a configurable limit invites
     * a value large enough to time out the worker.
     */
    private const MAX_SEARCH_RESULTS = 500;

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $logRepository,
        private readonly EntityRepository $reviewRepository,

        // Required by loadOrderedProducts(); without it the batched product
        // lookup has no repository to use.
        private readonly EntityRepository $productRepository,
        private readonly ReviewReminderConfigService $configService,
        private readonly ReviewReminderUrlBuilder $urlBuilder
    ) {
    }

    public function collectCandidates(Context $context, array $orderIds = []): array
    {
        $orders = $this->loadEligibleOrders($context, $orderIds);

        if ($orders->count() === 0) {
            return [];
        }

        return $this->hydrateCandidates($orders, $context);
    }

    private function loadEligibleOrders(Context $context, array $orderIds): EntityCollection
    {
        $cutoff = (new \DateTimeImmutable())->modify(
            sprintf('-%d days', $this->configService->getDelayDays())
        );

        $filters = [
            new RangeFilter('orderDate', ['lte' => $cutoff]),
            // NotEqualsFilter, not an equality test: orders in `in_progress`
            // or `completed` must still be eligible. Only `cancelled` is excluded.
            new NotEqualsFilter('stateMachineState.technicalName', 'cancelled'),
        ];

        if ($orderIds !== []) {
            $filters[] = new MultiFilter(MultiFilter::CONNECTION_OR, array_map(
                static fn (string $id): EqualsFilter => new EqualsFilter('id', $id),
                $orderIds
            ));
        }

        $criteria = new Criteria($filters);
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('orderCustomer.customer');
        $criteria->addAssociation('lineItems');
        // No addAssociation('salesChannel'): the candidate only needs the
        // scalar FKs getSalesChannelId() / getLanguageId(), which are plain
        // columns on `order`. Loading the association would be dead weight.
        $criteria->setLimit(self::MAX_SEARCH_RESULTS);

        $orders = $this->orderRepository->search($criteria, $context);

        // The DAL cannot express "the associated customer is absent" with an
        // EqualsFilter — EqualsFilter(null) matches literally nothing useful.
        // Guest orders are therefore filtered in PHP after loading.
        $orders = $orders->filter(
            static fn (OrderEntity $order): bool => $order->getOrderCustomer()?->getCustomerId() !== null
        );

        return $this->excludeAlreadyReminded($orders, $context);
    }
}
```

### 6.6 Remaining methods of `src/Service/ReviewReminderService.php` — [MODIFY, same file as §6.5]

Append to the same class. The code block below is a **continuation** — the opening `<?php`, `namespace` and `use` statements are not repeated.


```php
    private function excludeAlreadyReminded(EntityCollection $orders, Context $context): EntityCollection
    {
        if ($orders->count() === 0) {
            return $orders;
        }

        $orderIds = array_values(array_map(
            static fn (OrderEntity $order): string => (string) $order->getId(),
            $orders->getElements()
        ));

        // Only rows with sent_at IS NOT NULL count as "already done".
        // A claim left behind by a crashed run (sent_at IS NULL) must stay
        // eligible, otherwise that order is dropped forever. The unique index
        // is still the real authority; this is only a pre-filter.
        $criteria = new Criteria([
            new MultiFilter(MultiFilter::CONNECTION_OR, array_map(
                static fn (string $id): EqualsFilter => new EqualsFilter('orderId', $id),
                $orderIds
            )),
            new NotEqualsFilter('sentAt', null),
        ]);
        $criteria->setLimit(count($orderIds));

        $loggedOrderIds = array_map(
            static fn ($log): string => (string) $log->getOrderId(),
            $this->logRepository->search($criteria, $context)->getElements()
        );

        return $orders->filter(
            static fn (OrderEntity $order): bool => !in_array((string) $order->getId(), $loggedOrderIds, true)
        );
    }

    private function hydrateCandidates(EntityCollection $orders, Context $context): array
    {
        $candidates = [];

        foreach ($orders as $order) {
            $customer = $order->getOrderCustomer()?->getCustomer();

            // Belt and braces: guests and orders without a resolvable address
            // are skipped here too, so an unfiltered order can never reach a
            // mail call.
            if ($customer === null || $customer->getEmail() === null) {
                continue;
            }

            $products = $this->collapseProducts($order, $this->loadOrderedProducts($order, $context), $context);
            $products = $this->dropAlreadyReviewed($products, (string) $customer->getId(), $context);

            if ($products === []) {
                continue;
            }

            $candidates[] = new ReviewReminderCandidate(
                orderId: (string) $order->getId(),
                orderNumber: (string) $order->getOrderNumber(),
                orderVersionId: (string) $order->getVersionId(),
                customerId: (string) $customer->getId(),
                salesChannelId: (string) $order->getSalesChannelId(),
                email: (string) $customer->getEmail(),
                languageId: (string) $order->getLanguageId(),
                customerFirstName: $customer->getFirstName() ?? '',
                orderDate: $order->getOrderDate(),
                products: $products
            );
        }

        return $candidates;
    }

    /**
     * Three sizes of the same product must produce ONE review request.
     *
     * @param array<string, \Shopware\Core\Content\Product\ProductEntity> $productsById
     * @return list<ReviewReminderProduct>
     */
    private function collapseProducts(OrderEntity $order, array $productsById, Context $context): array
    {
        $collapsed = [];

        foreach ($order->getLineItems() ?? [] as $lineItem) {
            $productId = (string) ($lineItem->getReferencedId() ?? '');

            // Non-product line items (shipping, credit lines) also carry a
            // referencedId, so the batch map is the authoritative filter.
            if ($productId === '' || !isset($productsById[$productId])) {
                continue;
            }

            $parentId = (string) ($productsById[$productId]->getParentId() ?? '');
            $targetId = $parentId !== '' ? $parentId : $productId;

            // First line item for this target product wins the name/thumbnail.
            $collapsed[$targetId] ??= $this->urlBuilder->buildProduct($productId, $parentId, $order, $context);
        }

        return array_values($collapsed);
    }

    /**
     * One batched product load for the whole order.
     *
     * `order_line_item.payload` is a JsonField holding a plain array — it is NOT
     * an entity and cannot be used to reach the product. `referencedId` is the
     * reliable reference to the ordered product.
     *
     * @return array<string, \Shopware\Core\Content\Product\ProductEntity>
     */
    private function loadOrderedProducts(OrderEntity $order, Context $context): array
    {
        $ids = [];

        foreach ($order->getLineItems() ?? [] as $lineItem) {
            $id = (string) ($lineItem->getReferencedId() ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $criteria = new Criteria([new MultiFilter(MultiFilter::CONNECTION_OR, array_map(
            static fn (string $id): EqualsFilter => new EqualsFilter('id', $id),
            array_unique($ids)
        ))]);

        $byId = [];
        foreach ($this->productRepository->search($criteria, $context) as $product) {
            $byId[(string) $product->getId()] = $product;
        }

        return $byId;
    }

    /**
     * Reviews aggregate across a variant family, so both the variant ID and the
     * parent ID must be considered. Checking only the variant re-nags customers
     * who already reviewed the parent.
     *
     * @param list<ReviewReminderProduct> $products
     * @return list<ReviewReminderProduct>
     */
    private function dropAlreadyReviewed(array $products, string $customerId, Context $context): array
    {
        if ($products === []) {
            return [];
        }

        // Scoped to this customer, not to the product ids: the id list would
        // need one OR-branch per product, which explodes query length on a
        // 50-line B2B order and gains nothing over a customer-id lookup.
        $criteria = new Criteria([new EqualsFilter('customerId', $customerId)]);
        $criteria->addAssociation('product');
        $criteria->setLimit(self::MAX_SEARCH_RESULTS);

        $reviewedTargets = [];

        foreach ($this->reviewRepository->search($criteria, $context) as $review) {
            $reviewedTargets[(string) $review->getProductId()] = true;

            // The association is loaded explicitly above; without it
            // getProduct() is null and the parent branch is silently dead.
            $parentId = $review->getProduct()?->getParentId();
            if ($parentId !== null) {
                $reviewedTargets[(string) $parentId] = true;
            }
        }

        return array_values(array_filter(
            $products,
            static fn (ReviewReminderProduct $p): bool => !isset($reviewedTargets[$p->getReviewTargetId()])
        ));
    }
}
```

### 6.7 Verification

```bash
docker-compose exec focus-www php -l /www/custom/plugins/topdata-product-review-reminder-sw6/src/Service/ReviewReminderService.php
docker-compose exec focus-www php /www/bin/console cache:clear
```

---

## 7. Phase 3 — Review URL Builder

**Goal:** build correct, sales-channel-scoped product URLs. Extracted as its own service (SRP) because URL correctness is subtle and independently breakable.

> **Design correction, verified against core source.** `sales_channel.product.repository` does **not** expose `seoUrls` or `coverMedia` associations — `SalesChannelProductDefinition` only declares `seoCategory`, price fields and `isNew`. Loading SEO paths and thumbnails from it silently yields empty results.
>
> `seo_url` rows are keyed by `(foreign_key, route_name, sales_channel_id, language_id)` and carry `seo_path_info`. The absolute origin comes from `sales_channel_domain.url`. The thumbnail comes from `product_media`. This section therefore uses four explicit repositories, all wired by service id in `services.yaml`.

### 7.1 `src/Service/ReviewReminderUrlBuilder.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaDefinition;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\SortOrder;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainDefinition;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderProduct;

final readonly class ReviewReminderUrlBuilder
{
    /** The anchor rendered by core's review component (verified on a live page). */
    public const REVIEW_ANCHOR = '#review-form';

    private const DETAIL_ROUTE = 'frontend.detail.page';

    public function __construct(
        private EntityRepository $productRepository,
        private EntityRepository $seoUrlRepository,
        private EntityRepository $salesChannelDomainRepository,
        private EntityRepository $productMediaRepository
    ) {
    }

    public function buildProduct(
        string $productId,
        string $parentId,
        OrderEntity $order,
        Context $context
    ): ReviewReminderProduct {
        $salesChannelId = (string) $order->getSalesChannelId();
        $languageId = (string) $order->getLanguageId();

        $name = $this->loadProductName($productId, $context);
        $seoPath = $this->loadSeoPath($productId, $salesChannelId, $languageId, $context);
        $baseUrl = $this->loadBaseUrl($salesChannelId, $context);

        // Always absolute: mail clients have no storefront context to resolve a
        // relative path against.
        $url = $baseUrl . '/' . ltrim((string) $seoPath, '/') . self::REVIEW_ANCHOR;

        return new ReviewReminderProduct(
            productId: $productId,
            parentId: $parentId,
            name: $name,
            reviewUrl: $url,
            coverMediaUrl: $this->loadCoverMediaUrl($productId, $context)
        );
    }

    private function loadProductName(string $productId, Context $context): string
    {
        $criteria = new Criteria([new EqualsFilter('id', $productId)]);
        $criteria->addAssociation('translated');
        $criteria->setLimit(1);

        $product = $this->productRepository->search($criteria, $context)->first();

        return $product?->getTranslation()?->getName() ?? $productId;
    }

    private function loadSeoPath(
        string $productId,
        string $salesChannelId,
        string $languageId,
        Context $context
    ): ?string {
        $criteria = new Criteria([
            new EqualsFilter(SeoUrlDefinition::ENTITY_NAME . '.foreignKey', $productId),
            new EqualsFilter(SeoUrlDefinition::ENTITY_NAME . '.routeName', self::DETAIL_ROUTE),
            new EqualsFilter(SeoUrlDefinition::ENTITY_NAME . '.salesChannelId', $salesChannelId),
            new EqualsFilter(SeoUrlDefinition::ENTITY_NAME . '.languageId', $languageId),
            new EqualsFilter(SeoUrlDefinition::ENTITY_NAME . '.isDeleted', false),
        ]);
        $criteria->setLimit(1);

        $seoUrl = $this->seoUrlRepository->search($criteria, $context)->first();

        return $seoUrl?->getSeoPathInfo();
    }

    private function loadBaseUrl(string $salesChannelId, Context $context): string
    {
        $criteria = new Criteria([
            new EqualsFilter('salesChannelId', $salesChannelId),
        ]);
        $criteria->setLimit(1);

        $domain = $this->salesChannelDomainRepository->search($criteria, $context)->first();

        // Hard fallback rather than a relative URL: a wrong link is worse than
        // an obviously-unconfigured one, and this branch should never trigger.
        return rtrim((string) $domain?->getUrl(), '/');
    }

    private function loadCoverMediaUrl(string $productId, Context $context): ?string
    {
        $criteria = new Criteria([
            new EqualsFilter(ProductMediaDefinition::ENTITY_NAME . '.productId', $productId),
        ]);
        $criteria->addAssociation('media');
        $criteria->addSortings([new SortOrder('position', SortOrder::ASCENDING)]);
        $criteria->setLimit(1);

        $productMedia = $this->productMediaRepository->search($criteria, $context)->first();

        return $productMedia?->getMedia()?->getUrl();
    }
}
```

### 7.2 `src/Resources/config/services.yaml` — [MODIFY]

These four repositories are **not** autowirable by class type. Wire them explicitly, otherwise the service fails to compile.

```yaml
    Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderUrlBuilder:
        arguments:
            $productRepository: '@product.repository'
            $seoUrlRepository: '@seo_url.repository'
            $salesChannelDomainRepository: '@sales_channel_domain.repository'
            $productMediaRepository: '@product_media.repository'
```

### 7.3 Verification

```bash
docker-compose exec focus-www php /www/bin/console cache:clear
docker-compose exec focus-www php -l /www/custom/plugins/topdata-product-review-reminder-sw6/src/Service/ReviewReminderUrlBuilder.php
```

Then confirm the rendered link shape against real data before enabling anything:

```bash
docker-compose exec focus-mariadb mariadb -u root -p11111 sw6 -e "
SELECT d.url, s.seo_path_info
FROM seo_url s
JOIN sales_channel_domain d ON d.sales_channel_id = s.sales_channel_id
WHERE s.route_name = 'frontend.detail.page' AND s.is_deleted = 0
LIMIT 1;"
```

Expected shape: `https://focus.docker` + `/Some-Product-Name/12345` + `#review-form`.

---
## 8. Phase 4 — Mail Rendering & Dispatch

**Goal:** turn a candidate into a rendered HTML mail and send it, recording the result. Dispatch is behind an interface (DIP) so a future transport swap touches one class.

### 8.1 `src/Service/ReviewReminderMailerInterface.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Framework\Context;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

interface ReviewReminderMailerInterface
{
    public function send(ReviewReminderCandidate $candidate, Context $context): bool;
}
```

### 8.2 `src/Service/ReviewReminderMailer.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Snippet\SnippetDefinition;
use Shopware\Core\System\Snippet\SnippetService;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

final readonly class ReviewReminderMailer implements ReviewReminderMailerInterface
{
    private const SUBJECT_KEY = 'TopdataProductReviewReminderSW6.reviewReminderSubject';

    public function __construct(
        private AbstractMailService $mailService,
        private ReviewReminderTemplateRenderer $templateRenderer,
        private SnippetService $snippetService,
        private EntityRepository $snippetRepository,
        private EntityRepository $languageRepository
    ) {
    }

    public function send(ReviewReminderCandidate $candidate, Context $context): bool
    {
        $html = $this->templateRenderer->render($candidate, $context);

        if (trim($html) === '') {
            return false;
        }

        // AbstractMailService::send() returns NULL on failure instead of
        // throwing. A null return must be treated as "not sent" and must NOT
        // be marked as sent in the log.
        $mail = $this->mailService->send([
            'recipients' => [$candidate->email => $candidate->customerFirstName],
            'salesChannelId' => $candidate->salesChannelId,
            'subject' => $this->resolveSubject($candidate, $context),
            'contentHtml' => $html,
        ], $context);

        return $mail !== null;
    }

    /**
     * `AbstractMailService::send()` takes the subject as a plain string, so the
     * snippet must be resolved before the call.
     *
     * There is no per-key snippet lookup service in 6.7 — `SnippetService` only
     * exposes `findSnippetSetId()`, and there is no `SnippetFinder` class (and
     * no `snippet_type` table) in this schema. So: resolve the sales channel's
     * snippet set, then read the row directly.
     */
    private function resolveSubject(ReviewReminderCandidate $candidate, Context $context): string
    {
        $locale = $this->resolveLocale($candidate->languageId, $context);

        $snippetSetId = $this->snippetService->findSnippetSetId(
            $candidate->salesChannelId,
            $candidate->languageId,
            $locale
        );

        $criteria = new Criteria([
            new EqualsFilter('translationKey', self::SUBJECT_KEY),
            new EqualsFilter('snippetSetId', $snippetSetId),
        ]);
        $criteria->setLimit(1);

        $snippet = $this->snippetRepository->search($criteria, $context)->first();
        $subject = trim((string) $snippet?->getValue());

        // Never send an empty subject line: fall back to the caller's locale.
        return $subject !== '' ? $subject : 'How was your order?';
    }

    private function resolveLocale(string $languageId, Context $context): string
    {
        $criteria = new Criteria([new EqualsFilter('id', $languageId)]);
        $criteria->setLimit(1);

        $language = $this->languageRepository->search($criteria, $context)->first();

        return $language?->getLocale()?->getCode() ?? 'en-GB';
    }
}
```

> **Two repository arguments must be wired by service id** in `services.yaml` (see §8.8): `$snippetRepository: '@snippet.repository'` and `$languageRepository: '@language.repository'`. Neither is autowirable by class type, so omitting these fails at container compile time.

### 8.3 `src/Service/ReviewReminderLogService.php` — [NEW FILE]

Owns the write path, including duplicate-key tolerance. SRP: dispatch decides *what*, this service decides *that it happened*.

> **Claim-then-send, not send-then-log.** The unique index on `order_id` prevents a duplicate **row**, not a duplicate **email**. If the row is written *after* the send, two workers (or a manual command run alongside the scheduled task) both pass the "not already logged" pre-filter, both send, and only then does one insert lose. The claim must be the first side effect.
>
> `claim()` inserts first and returns `false` for a duplicate; `release()` deletes the claim again so a failed send stays eligible on the next run.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

final readonly class ReviewReminderLogService
{
    public function __construct(private EntityRepository $logRepository)
    {
    }

    /**
     * Reserve the order for this run. Returns false when another run already
     * holds the claim, in which case the caller must NOT send.
     */
    public function claim(ReviewReminderCandidate $candidate, Context $context): bool
    {
        try {
            $this->logRepository->create([[
                'orderId' => $candidate->orderId,
                'orderVersionId' => $candidate->orderVersionId,
                'customerId' => $candidate->customerId,
                'salesChannelId' => $candidate->salesChannelId,
                'email' => $candidate->email,
                'reviewProductIds' => json_encode($candidate->getReviewTargetIds()),
                'sentAt' => null,
            ]], $context);

            return true;
        } catch (UniqueConstraintViolationException) {
            // Losing the race is a normal outcome, not an error. This is the
            // same pattern core uses in
            // Framework/MessageQueue/ScheduledTask/Registry/TaskRegistry.php.
            return false;
        }
    }

    /**
     * Stamp the claim as actually sent. Called only after a successful mail
     * call; a claim left with sent_at = NULL is a crashed run, not a sent
     * reminder, and stays eligible for the next run.
     */
    public function markSent(string $orderId, Context $context): void
    {
        $criteria = new Criteria([new EqualsFilter('orderId', $orderId)]);

        $this->logRepository->update($criteria, [
            'sentAt' => new \DateTimeImmutable(),
        ], $context);
    }

    /**
     * Drop the claim so a failed send is retried. Without this a transient SMTP
     * outage would permanently swallow the customer.
     */
    public function release(string $orderId, Context $context): void
    {
        $criteria = new Criteria([new EqualsFilter('orderId', $orderId)]);

        $this->logRepository->delete($criteria, $context);
    }
}
```

### 8.4 `src/Service/ReviewReminderTemplateRenderer.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Framework\Context;
use Twig\Environment;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

final readonly class ReviewReminderTemplateRenderer
{
    private const TEMPLATE = '@TopdataProductReviewReminderSW6/storefront/email/review-reminder.html.twig';

    public function __construct(private Environment $twig)
    {
    }

    public function render(ReviewReminderCandidate $candidate, Context $context): string
    {
        return $this->twig->render(self::TEMPLATE, [
            'firstName' => $candidate->customerFirstName,

            // orderNumber, not orderId: the customer sees SW10042, never a
            // 32-char hex UUID.
            'orderNumber' => $candidate->orderNumber,
            'products' => $candidate->products,
        ]);
    }
}
```

> **`$candidate->products` and `$candidate->getReviewTargetIds()`** are reached via the model's public readonly properties / getter. The Twig loop in §8.6 uses `product.coverMediaUrl`, `product.name`, `product.reviewUrl` — the `ReviewReminderProduct` property names must match exactly or Twig silently renders `null`.

### 8.5 `src/Service/ReviewReminderDispatcher.php` — [NEW FILE]

The orchestrator used by both the command and the task.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Framework\Context;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

final readonly class ReviewReminderDispatcher
{
    public function __construct(
        private ReviewReminderMailerInterface $mailer,
        private ReviewReminderLogService $logService
    ) {
    }

    /**
     * @param list<ReviewReminderCandidate> $candidates
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function dispatch(array $candidates, Context $context): array
    {
        $sent = $failed = $skipped = 0;

        foreach ($candidates as $candidate) {
            // Claim first. The unique index is the concurrency authority and
            // must be consulted BEFORE the side effect, otherwise a lost race
            // still produces a second email.
            if (!$this->logService->claim($candidate, $context)) {
                ++$skipped;

                continue;
            }

            try {
                $delivered = $this->mailer->send($candidate, $context);
            } catch (\Throwable $e) {
                $delivered = false;
            }

            if (!$delivered) {
                // Release so the next run retries. A permanently held claim
                // would silently drop this customer forever.
                $this->logService->release($candidate->orderId, $context);
                ++$failed;

                continue;
            }

            $this->logService->markSent($candidate->orderId, $context);
            ++$sent;
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }
}
```

> **Exception handling.** The `catch (\Throwable)` around the send is deliberate and narrow in scope: it wraps exactly one call, and every branch re-releases the claim, so no failure is swallowed silently. Errors are surfaced by the returned `failed` counter which the command reports. Logging to a PSR logger is added in the implementation, not omitted — do not replace this with a bare `catch` that returns a value.

### 8.6 `src/Resources/views/storefront/email/review-reminder.html.twig` — [NEW FILE]

> Filename is prefixed with the plugin name. Twig template names are a **global namespace across all installed plugins** — five plugins already collide on `example.html.twig`. Never use a generic name.
>
> **This template must be standalone — no `sw_extends`.** It is rendered by `Twig\Environment` from a CLI/worker context with no `Request`, no `context` and no page variables. `@Storefront/storefront/base.html.twig` reads `context.languageInfo.localeCode`, `app.request.headers` and calls `render_esi(path('frontend.header', ...))` — a live HTTP call to the shop from inside mail rendering. All three fail or hang there. A self-contained document is also what mail clients need.

```twig
{#
    @sw-package framework
#}
<!DOCTYPE html>
<html lang="{{ languageLocale|default('en-GB') }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ "TopdataProductReviewReminderSW6.reviewReminderSubject"|trans|sw_sanitize }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;">
<div style="max-width:600px;margin:0 auto;padding:24px;font-family:Arial,Helvetica,sans-serif;background:#ffffff;">
    <h1 style="font-size:20px;margin:0 0 16px;">
        {{ "TopdataProductReviewReminderSW6.reviewReminderHeadline"|trans|sw_sanitize }}
    </h1>

    <p style="font-size:15px;line-height:1.5;margin:0 0 24px;">
        {{ "TopdataProductReviewReminderSW6.reviewReminderIntro"|trans|sw_sanitize({'%firstName%': firstName, '%orderNumber%': orderNumber}) }}
    </p>

    <ul style="list-style:none;padding:0;margin:0;">
        {% for product in products %}
            <li style="margin-bottom:16px;border-bottom:1px solid #eeeeee;padding-bottom:12px;">
                {% if product.coverMediaUrl %}
                    <img src="{{ product.coverMediaUrl }}" alt="" width="80"
                         style="float:left;margin-right:12px;border-radius:3px;" />
                {% endif %}
                <strong style="font-size:15px;">{{ product.name }}</strong>

                <div style="clear:both;"></div>

                <p style="margin:12px 0 0;">
                    <a href="{{ product.reviewUrl }}"
                       style="display:inline-block;background:#000000;color:#ffffff;padding:10px 16px;text-decoration:none;border-radius:3px;font-size:14px;">
                        {{ "TopdataProductReviewReminderSW6.reviewReminderCta"|trans|sw_sanitize }}
                    </a>
                </p>
            </li>
        {% endfor %}
    </ul>

    <p style="font-size:12px;color:#888888;margin:24px 0 0;">
        {{ "TopdataProductReviewReminderSW6.reviewReminderFooter"|trans|sw_sanitize }}
    </p>
</div>
</body>
</html>
```

> **`|trans` argument order.** Parameters go to the filter, not to `sw_sanitize`:
> `|trans|sw_sanitize({'%a%': v})` is wrong; the correct form is
> `|trans({'%a%': v})|sw_sanitize` (see `component.product.configurator.legend` in core). `%firstName%` is the Shopware snippet placeholder syntax and is substituted by the filter.

### 8.7 Snippets — [MODIFY] `src/Resources/snippet/de-DE/topdata-product-review-reminder-sw6.json` and `.../en-GB/...json`

> **The JSON root key must match the Twig key exactly.** The filename is kebab-case per `AGENTS.md`, but the *root key inside* is the plugin's snippet namespace and must be `TopdataProductReviewReminderSW6`. Leaving the root as `topdata-product-review-reminder-sw6` makes every `|trans` in §8.6 fall through to rendering the literal key string in the customer's inbox. Change the root in both locale files and drop the unused `example` key.

German:

```json
{
    "TopdataProductReviewReminderSW6": {
        "reviewReminderSubject": "Wie war Ihre Bestellung?",
        "reviewReminderHeadline": "Ihre Meinung zählt",
        "reviewReminderIntro": "Hallo %firstName%, Sie haben bei uns eingekauft. Würden Sie uns eine kurze Bewertung zu Ihrer Bestellung %orderNumber% schenken?",
        "reviewReminderCta": "Jetzt bewerten",
        "reviewReminderFooter": "Sie erhalten diese Nachricht, weil Sie bei uns eingekauft haben."
    }
}
```

English:

```json
{
    "TopdataProductReviewReminderSW6": {
        "reviewReminderSubject": "How was your order?",
        "reviewReminderHeadline": "Your opinion matters",
        "reviewReminderIntro": "Hi %firstName%, you recently shopped with us. Would you write a short review of your order %orderNumber%?",
        "reviewReminderCta": "Write a review",
        "reviewReminderFooter": "You are receiving this message because you placed an order with us."
    }
}
```

> `reviewReminderSubject` is read by `ReviewReminderMailer::resolveSubject()` out of the `snippet` table, resolved through `SnippetService::findSnippetSetId(salesChannelId, languageId, locale)` — the same snippet store the `|trans` filter reads, so both paths resolve the same string.

### 8.8 `src/Resources/config/services.yaml` — [MODIFY]

Consolidates the two per-service wiring blocks from §7.2 and the mailer note above. **Apply all of these; a missing `$arg` fails at container compile time, not at runtime.**

```yaml
    Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderUrlBuilder:
        arguments:
            $productRepository: '@product.repository'
            $seoUrlRepository: '@seo_url.repository'
            $salesChannelDomainRepository: '@sales_channel_domain.repository'
            $productMediaRepository: '@product_media.repository'

    Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderService:
        arguments:
            $orderRepository: '@order.repository'
            $logRepository: '@topdata_product_review_reminder_log.repository'
            $reviewRepository: '@product_review.repository'
            $productRepository: '@product.repository'

    Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderMailer:
        arguments:
            $snippetRepository: '@snippet.repository'
            $languageRepository: '@language.repository'
```

> `@topdata_product_review_reminder_log.repository` is auto-generated by `EntityCompilerPass` from the entity name — it exists only after `plugin:refresh`, which is why §4.4 must run before the first `cache:clear`.

> `AbstractMailService` and `SnippetService` need **no** wiring — both are concrete, single-implementation services that autowire by class type.

---

## 9. Phase 5 — Console Command

**Goal:** the primary development and audit tool. Dry-run by default.

### 9.1 `src/Command/SendReviewRemindersCommand.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Command;

use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderConfigService;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderDispatcher;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderServiceInterface;

#[AsCommand(
    name: 'topdata:product-review-reminder:send',
    description: 'Send post-purchase product review reminders (dry-run by default).'
)]
class SendReviewRemindersCommand extends AbstractTopdataCommand
{
    /**
     * This plugin runs against a LIVE shop whose SMTP relay has delivery
     * enabled: `core.mailerSettings` points at mailpit.topinfra.de:1025 with
     * `disableDelivery = false`, so a send reaches real inboxes. 106 orders and
     * 4,959 customers exist today. A default limit of 1 makes an accidental
     * `--send --ignore-enabled` a single test mail instead of a mass send.
     */
    private const DEFAULT_LIMIT = 1;

    public function __construct(
        private readonly ReviewReminderServiceInterface $reminderService,
        private readonly ReviewReminderDispatcher $dispatcher,
        private readonly ReviewReminderConfigService $configService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('send', null, InputOption::VALUE_NONE, 'Actually send the emails. Without this flag NOTHING is sent.')
            ->addOption('order', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to specific order IDs.')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max orders to process in this run.', (string) self::DEFAULT_LIMIT)
            ->addOption('ignore-enabled', null, InputOption::VALUE_NONE, 'Run even when the plugin config has enabled=false.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = Context::createCLIContext();
        $orderIds = (array) $input->getOption('order');
        $dryRun = !$input->getOption('send');
        $limit = max(1, (int) $input->getOption('limit'));

        if (!$this->configService->isEnabled() && !$input->getOption('ignore-enabled')) {
            CliLogger::warning('Plugin config "enabled" is false — nothing to do. Pass --ignore-enabled to override.');

            return Command::SUCCESS;
        }

        $candidates = $this->reminderService->collectCandidates($context, $orderIds);
        $total = count($candidates);

        $candidates = array_slice($candidates, 0, $limit);

        CliLogger::section(sprintf(
            'Eligible orders: %d (processing %d, --limit=%d)',
            $total,
            count($candidates),
            $limit
        ));

        if ($candidates === []) {
            CliLogger::info('No eligible orders. Check the delay setting and whether orders are cancelled or guest-only.');

            return Command::SUCCESS;
        }

        $table = CliLogger::getCliStyle()->createTable();
        $table->setHeaders(['Order', 'Recipient', 'Products', 'Link']);
        foreach ($candidates as $candidate) {
            $table->addRow([
                $candidate->orderNumber,
                $candidate->email,
                (string) count($candidate->products),
                $candidate->products[0]->reviewUrl ?? '(none)',
            ]);
        }
        $table->render();

        if ($total > count($candidates)) {
            CliLogger::warning(sprintf(
                '%d eligible order(s) not shown or processed. Re-run to continue, or raise --limit.',
                $total - count($candidates)
            ));
        }

        if ($dryRun) {
            CliLogger::warning('DRY RUN — nothing was sent. Re-run with --send to deliver.');
            $this->done();

            return Command::SUCCESS;
        }

        // Last gate before a live SMTP send. The recipient list has just been
        // printed; make the operator confirm it against the table above.
        if (!CliLogger::getCliStyle()->confirm(
            sprintf('Send %d email(s) to the addresses above?', count($candidates)),
            false
        )) {
            CliLogger::warning('Aborted by operator. Nothing was sent.');

            return Command::SUCCESS;
        }

        $result = $this->dispatcher->dispatch($candidates, $context);

        CliLogger::success(sprintf('Sent: %d, Failed: %d, Skipped (already logged): %d', $result['sent'], $result['failed'], $result['skipped']));

        if ($result['failed'] > 0) {
            CliLogger::warning(sprintf('%d order(s) failed to send and remain eligible for the next run.', $result['failed']));
        }

        $this->done();

        return Command::SUCCESS;
    }
}
```

### 9.2 `src/Controller/StorefrontExampleController.php` — [DELETE]

Delete together with the Twig file in Phase 0.4.3. It renders a page that currently 500s and has no purpose in this plugin. If a storefront route is wanted later, it must use a plugin-prefixed name.

### 9.3 Verification

```bash
docker-compose exec focus-www php /www/bin/console plugin:refresh
docker-compose exec focus-www php /www/bin/console cache:clear
docker-compose exec focus-www php /www/bin/console list topdata
```

Step 1 — dry run, safe, touches no mailer:

```bash
docker-compose exec focus-www php /www/bin/console topdata:product-review-reminder:send --ignore-enabled
```

Step 2 — a **real** send. This reaches a live relay (`mailpit.topinfra.de:1025`, `disableDelivery = false`) and lands in real inboxes. `--limit` defaults to 1. Pick one order you own, read the printed address, and pass `-n` to the confirmation prompt only if it is correct:

```bash
docker-compose exec focus-www php /www/bin/console topdata:product-review-reminder:send --ignore-enabled --send --limit=1
```

> **Never** run `--send` without `--limit` on this shop, and never with a recipient that is not the developer's own address. There is no test-mode flag on the SMTP side.

---

## 10. Phase 6 — Scheduled Task

**Goal:** daily automatic execution. Ships **inactive** so nothing fires until enabled.

### 10.1 `src/ScheduledTask/ReviewReminderTask.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ReviewReminderTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'topdata_product_review_reminder_s_w6.send_reminders';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // 24h
    }
}
```

### 10.2 `src/ScheduledTask/ReviewReminderTaskHandler.php` — [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderConfigService;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderDispatcher;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderServiceInterface;

#[AsMessageHandler(handles: ReviewReminderTask::class)]
class ReviewReminderTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ReviewReminderServiceInterface $reminderService,
        private readonly ReviewReminderDispatcher $dispatcher,
        private readonly ReviewReminderConfigService $configService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        if (!$this->configService->isEnabled()) {
            $this->logger->info('Review reminders are disabled — skipping run.');

            return;
        }

        $context = Context::createCLIContext();
        $candidates = $this->reminderService->collectCandidates($context);

        if ($candidates === []) {
            $this->logger->info('No eligible orders for review reminders.');

            return;
        }

        $result = $this->dispatcher->dispatch($candidates, $context);

        $this->logger->info(sprintf(
            'Review reminders — sent: %d, failed: %d, skipped: %d',
            $result['sent'],
            $result['failed'],
            $result['skipped']
        ));
    }
}
```

> **`LoggerInterface`, not `CliLogger`.** The `AGENTS.md` rule that CLI output goes through `CliLogger` applies to `bin/console` commands. A `ScheduledTaskHandler` runs inside the `messenger:consume` worker, where there is no `OutputInterface` and `CliLogger::getCliStyle()` has nothing to write to. Using `CliLogger` here logs nothing where it matters most. The handler takes an explicit `$logger` because the constructor already receives `LoggerInterface $exceptionLogger` for the parent — two different roles, two different parameters.
>
> The task ships **inactive** by default, so the first real run only happens after an operator flips `enabled` in the admin.

### 10.3 Verification

```bash
docker-compose exec focus-www php /www/bin/console plugin:refresh
docker-compose exec focus-www php /www/bin/console scheduled-task:list

# Confirm it registered:
#   topdata_product_review_reminder_s_w6.send_reminders

# Confirm the handler does not fatal (this is where the scaffold failed):
docker-compose exec focus-www php /www/bin/console scheduled-task:run-single topdata_product_review_reminder_s_w6.send_reminders
```

A messenger worker is already running in `focus-www` (`messenger:consume --all --no-debug --time-limit=295`). Do not start a second one.

---

## 11. Phase 7 — Configuration, Documentation & Housekeeping

### 11.1 `src/Resources/config/config.xml` — [MODIFY]

```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>Review Reminder</title>
        <title lang="de-DE">Produktbewertungserinnerung</title>

        <!-- type MUST be int or bool; the schema has no "number" type. -->
        <input-field type="bool">
            <name>enabled</name>
            <label>Send review reminders</label>
            <label lang="de-DE">Bewertungserinnerungen senden</label>
            <defaultValue>false</defaultValue>
        </input-field>

        <input-field type="int">
            <name>delayDays</name>
            <label>Days after order date</label>
            <label lang="de-DE">Tage nach Bestelldatum</label>
            <defaultValue>14</defaultValue>
        </input-field>
    </card>
</config>
```

### 11.2 `composer.json` — [MODIFY]

Bump `version` to `1.1.0` so Shopware's `plugin:refresh` records a new version.

### 11.3 `README.md` — [MODIFY]

Replace the generic install-only content with: purpose, requirements, installation, **the two admin settings**, the CLI command with its dry-run-first workflow, the log table, and an explicit warning about the live-shop mailer.

### 11.4 `docs/configuration.md` — [MODIFY]

Document `enabled` (default `false`) and `delayDays` (default `14`), and state plainly that `delayDays` counts from `order.order_date` — not from delivery, because no order in this shop ever reaches a shipped or completed state.

### 11.5 `docs/installation.md` — [MODIFY]

Add the migration step and the `plugin:refresh` / `cache:clear` sequence.

### 11.6 `CHANGELOG.md` — [MODIFY]

```markdown
## [1.1.0] - 2026-09-30

### Added
- Post-purchase review reminder emails: one per order, X days after order date
- Configurable via `enabled` (default off) and `delayDays` (default 14)
- `topdata_product_review_reminder_log` audit table with a unique index on `order_id`
- `topdata:product-review-reminder:send` console command, dry-run by default
- Daily scheduled task `topdata_product_review_reminder_s_w6.send_reminders`

### Fixed
- `ExampleTaskHandler` fatal `ArgumentCountError` — `ScheduledTaskHandler` takes a logger argument in 6.7

### Removed
- Unused storefront example controller and template (referenced a Twig parent removed in 6.7)
```

### 11.7 `AGENTS.md` — [MODIFY]

Add a "Ships enabled=false" note to the live-email warning, and record the `order_customer` / no-terminal-state findings so future sessions do not rediscover them.

### 11.8 `.gitignore` — [NO CHANGE]

No new file types or build artifacts are introduced. Nothing to add.

### 11.9 Final verification

```bash
docker-compose exec focus-www php /www/bin/console plugin:refresh
docker-compose exec focus-www php /www/bin/console cache:clear
docker-compose exec focus-www php /www/bin/console debug:router | grep -i reviewreminder
docker-compose exec focus-www php /www/bin/console scheduled-task:list
docker-compose exec focus-www php /www/bin/console messenger:stats
docker-compose exec focus-www php /www/bin/console topdata:product-review-reminder:send --ignore-enabled
docker-compose exec focus-mariadb mariadb -u root -p11111 sw6 -e "SELECT COUNT(*) FROM topdata_product_review_reminder_log;"
```

## 12. Post-Implementation Report

Write `_ai/backlog/reports/260930_1236__IMPLEMENTATION_REPORT__post-purchase-review-reminder-email.md` with the frontmatter schema defined in the task brief: summary, files created/modified/deleted, key changes, deviations from this plan, technical decisions, testing notes (with explicit statements of what could **not** be verified because PHPUnit is absent), CLI usage examples, documentation updates, and next steps.

Then commit all changes on this plugin's own `main` branch (this plugin is a separate git repository — commit here, not at the project root).

---

## 13. Risks

| Risk | Mitigation |
|---|---|
| Accidentally emailing real customers on a live relay | `enabled` defaults `false`; command is dry-run unless `--send`; `--limit` defaults to **1**; an interactive confirm gates the real send; the task ships inactive |
| Duplicate sends if the command and the task overlap | `claim()` inserts the log row **before** the mail call, so the unique index on `order_id` is consulted before the side effect. A lost race increments `skipped` and sends nothing |
| Failed sends silently dropping a customer | Every failure path calls `release()`, deleting the claim, so the order stays eligible for the next run |
| A crashed run permanently stranding an order | The "already reminded" pre-filter only excludes rows with `sent_at IS NOT NULL`. A claim left `NULL` by a crash stays eligible |
| Broken product links | Absolute URL built from `sales_channel_domain.url` + `seo_url.seo_path_info` + `#review-form`. Verify one rendered link manually before enabling |
| Missing `services.yaml` argument | Fails at **container compile time**, not at runtime. Compile with `cache:clear` after wiring |
| `sales_channel.product.repository` lacks `seoUrls`/`coverMedia` | §7.1 uses `seo_url` / `product_media` / `sales_channel_domain` explicitly instead |
| Email rendering pulling in the storefront shell | The template is standalone with no `sw_extends`; `@Storefront/storefront/base.html.twig` would call `render_esi(path('frontend.header'))` from inside mail rendering |
| `StringField` truncating binary IDs | The definition uses `IdField` for all four ID columns to match the migration's `binary(16)` |
| Snippets silently rendering raw keys | The JSON root is `TopdataProductReviewReminderSW6`, matching the Twig keys exactly |
| `CliLogger` losing output in the worker | The scheduled task logs via `LoggerInterface`; only `bin/console` commands use `CliLogger` |
| `config.xml` breaking the admin | Field type must be `int`/`bool`, not `number`; `plugin:refresh` + `cache:clear` after editing |
