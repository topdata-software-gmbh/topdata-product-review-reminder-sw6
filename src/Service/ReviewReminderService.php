<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotEqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderProduct;

/**
 * Selection engine. Three batched passes, no per-order queries and no N+1.
 */
final class ReviewReminderService implements ReviewReminderServiceInterface
{
    /**
     * Hard ceiling for a single run. 106 orders exist today, so this is a
     * guard against a future bulk backfill loading the whole order table at
     * once. Deliberately a constant and not config: a configurable limit
     * invites a value large enough to time out the worker.
     */
    private const MAX_SEARCH_RESULTS = 500;

    /**
     * Rows fetched per page. Offset paging without a stable sort would skip
     * and duplicate rows, and `order_date` is day-granular, so ties are the
     * normal case rather than the exception.
     *
     * Equal to MAX_SEARCH_RESULTS so a normal run is a single query. The
     * paging only matters for shops whose aged backlog exceeds one page.
     */
    private const PAGE_SIZE = 500;

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $logRepository,
        private readonly EntityRepository $reviewRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $languageRepository,
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

        $localesByLanguageId = $this->loadLanguageLocales($orders, $context);

        return $this->hydrateCandidates($orders, $localesByLanguageId, $context);
    }

    private function loadEligibleOrders(Context $context, array $orderIds): EntityCollection
    {
        $criteria = $this->buildEligibleOrderCriteria($context, $orderIds);

        $eligibleIds = $this->collectEligibleOrderIds($criteria, $context);

        if ($eligibleIds === []) {
            return new EntityCollection();
        }

        return $this->hydrateEligibleOrders($eligibleIds, $context);
    }

    private function buildEligibleOrderCriteria(Context $context, array $orderIds): Criteria
    {
        $cutoff = (new \DateTimeImmutable())->modify(
            sprintf('-%d days', $this->configService->getDelayDays())
        );

        // No lower bound. An order stays eligible until it has actually been
        // reminded, however old it is — a window here would be a silent
        // policy cutoff, and it would apply retroactively to the existing
        // order book, not just to future orders. (This shop's oldest order is
        // months old, so a "last 30 days" window would have disabled the
        // feature for almost everything already in the database.)
        //
        // `order_date` is a generated DATE column and RangeFilter only accepts
        // scalars, hence the pre-formatted cutoff.
        $filters = [
            new RangeFilter('orderDate', [
                RangeFilter::LTE => $cutoff->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]),

            // NotEqualsFilter, not equality: `in_progress` and `completed`
            // orders must stay eligible. Only `cancelled` is excluded.
            new NotEqualsFilter('stateMachineState.technicalName', 'cancelled'),
        ];

        if ($orderIds !== []) {
            $filters[] = new MultiFilter(MultiFilter::CONNECTION_OR, array_map(
                static fn (string $id): EqualsFilter => new EqualsFilter('id', $id),
                $orderIds
            ));
        }

        $criteria = (new Criteria())->addFilter(...$filters);

        // Offset paging is only stable with a total ordering. `order_date` is
        // day-granular, so ties are the normal case on any day with more than
        // one order, and an unstable sort would skip and duplicate rows.
        // Longest-overdue first, `id` as the tiebreaker.
        $criteria->addSorting(new FieldSorting('orderDate', FieldSorting::ASCENDING));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));

        // The DAL cannot express "the associated customer is absent" with an
        // EqualsFilter, and a guest order has to be dropped here. Saving a
        // review requires a logged-in customer, so a guest is unreachable.
        // `orderCustomer` is a small 1:1 join; the heavy associations are only
        // loaded for orders that survive the second phase.
        $criteria->addAssociation('orderCustomer');

        return $criteria;
    }

    /**
     * Pages through the window, excluding already-reminded orders per page,
     * so MAX_SEARCH_RESULTS caps real candidates rather than raw rows.
     *
     * Excluding after a single limited query — the previous behaviour — made
     * the limit permanently swallow every new candidate once a shop had more
     * aged orders than the limit: the query kept returning the same oldest,
     * already-sent rows, and the run returned nothing forever.
     */
    private function collectEligibleOrderIds(Criteria $criteria, Context $context): array
    {
        $collected = [];
        $offset = 0;

        while (count($collected) < self::MAX_SEARCH_RESULTS) {
            $criteria->setLimit(self::PAGE_SIZE);
            $criteria->setOffset($offset);

            $page = $this->orderRepository->search($criteria, $context);
            $fetched = $page->count();

            if ($fetched === 0) {
                break;
            }

            $page = $page->filter(
                static fn (OrderEntity $order): bool => $order->getOrderCustomer()?->getCustomerId() !== null
            );

            $page = $this->excludeAlreadyReminded($page, $context);

            foreach ($page as $order) {
                $collected[] = (string) $order->getId();
            }

            // The fetched count, not the surviving count, decides whether more
            // rows exist: a page that was filtered away entirely is not the
            // end of the result set.
            if ($fetched < self::PAGE_SIZE) {
                break;
            }

            $offset += self::PAGE_SIZE;
        }

        if (count($collected) > self::MAX_SEARCH_RESULTS) {
            $collected = array_slice($collected, 0, self::MAX_SEARCH_RESULTS);
        }

        return $collected;
    }

    private function hydrateEligibleOrders(array $orderIds, Context $context): EntityCollection
    {
        $criteria = (new Criteria())->addFilter(new EqualsAnyFilter('id', $orderIds));
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('orderCustomer.customer');
        $criteria->addAssociation('lineItems');
        $criteria->setLimit(count($orderIds));

        // No addAssociation('salesChannel'): the candidate only needs the
        // scalar FKs already on the `order` row.
        return $this->orderRepository->search($criteria, $context);
    }

    private function excludeAlreadyReminded(EntityCollection $orders, Context $context): EntityCollection
    {
        if ($orders->count() === 0) {
            return $orders;
        }

        $orderIds = array_values(array_map(
            static fn (OrderEntity $order): string => (string) $order->getId(),
            $orders->getElements()
        ));

        // Only rows with sent_at IS NOT NULL count as "already done". A claim
        // left behind by a crashed run (sent_at IS NULL) must stay eligible,
        // otherwise that order is dropped forever. The unique index remains
        // the real authority; this is only a pre-filter.
        $criteria = (new Criteria())->addFilter(
            new MultiFilter(MultiFilter::CONNECTION_OR, array_map(
                static fn (string $id): EqualsFilter => new EqualsFilter('orderId', $id),
                $orderIds
            )),
            new NotEqualsFilter('sentAt', null)
        );
        $criteria->setLimit(count($orderIds));

        $loggedOrderIds = array_map(
            static fn ($log): string => (string) $log->getOrderId(),
            $this->logRepository->search($criteria, $context)->getElements()
        );

        if ($loggedOrderIds === []) {
            return $orders;
        }

        return $orders->filter(
            static fn (OrderEntity $order): bool => !in_array((string) $order->getId(), $loggedOrderIds, true)
        );
    }

    /**
     * One batched language lookup for the whole run.
     *
     * A shop has a handful of languages, so this is normally a single query and
     * a single-row result. Resolving it here rather than in the mailer keeps the
     * candidate self-describing and the send path free of lookups.
     *
     * @return array<string, string> languageId => localeCode
     */
    private function loadLanguageLocales(EntityCollection $orders, Context $context): array
    {
        $languageIds = [];

        foreach ($orders as $order) {
            $languageId = (string) $order->getLanguageId();

            if ($languageId !== '') {
                $languageIds[$languageId] = true;
            }
        }

        $languageIds = array_keys($languageIds);

        if ($languageIds === []) {
            return [];
        }

        $criteria = (new Criteria())->addFilter(
            new MultiFilter(MultiFilter::CONNECTION_OR, array_map(
                static fn (string $id): EqualsFilter => new EqualsFilter('id', $id),
                $languageIds
            ))
        );
        $criteria->addAssociation('locale');

        $locales = [];

        foreach ($this->languageRepository->search($criteria, $context) as $language) {
            $locale = $language->getLocale()?->getCode();

            if ($locale !== null) {
                $locales[(string) $language->getId()] = $locale;
            }
        }

        return $locales;
    }

    /**
     * @return list<ReviewReminderCandidate>
     */
    private function hydrateCandidates(EntityCollection $orders, array $localesByLanguageId, Context $context): array
    {
        $candidates = [];

        foreach ($orders as $order) {
            $customer = $order->getOrderCustomer()?->getCustomer();
            $orderDate = $order->getOrderDate();

            // Belt and braces: guests and orders without a resolvable address
            // are skipped again here, so no unfiltered order can reach mail.
            if ($customer === null || $customer->getEmail() === null || $orderDate === null) {
                continue;
            }

            $products = $this->collapseProducts($order, $this->loadOrderedProducts($order, $context), $context);
            $products = $this->dropAlreadyReviewed($products, (string) $customer->getId(), $context);

            if ($products === []) {
                continue;
            }

            $candidates[] = new ReviewReminderCandidate(
                orderId: (string) $order->getId(),
                orderVersionId: (string) $order->getVersionId(),
                customerId: (string) $customer->getId(),
                salesChannelId: (string) $order->getSalesChannelId(),
                languageId: (string) $order->getLanguageId(),
                languageLocale: $localesByLanguageId[(string) $order->getLanguageId()] ?? 'en-GB',
                email: (string) $customer->getEmail(),
                customerFirstName: (string) ($customer->getFirstName() ?? ''),
                orderNumber: (string) ($order->getOrderNumber() ?? ''),
                orderDate: $orderDate,
                products: $products
            );
        }

        return $candidates;
    }

    /**
     * One batched product load for the whole order.
     *
     * `order_line_item.payload` is a JsonField holding a plain array — it is not
     * an entity and cannot reach the product. `referencedId` is the reference.
     *
     * @return array<string, ProductEntity>
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

        $criteria = (new Criteria())->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, array_map(
            static fn (string $id): EqualsFilter => new EqualsFilter('id', $id),
            array_values(array_unique($ids))
        )));

        $byId = [];
        foreach ($this->productRepository->search($criteria, $context) as $product) {
            $byId[(string) $product->getId()] = $product;
        }

        return $byId;
    }

    /**
     * Three sizes of the same product must produce one review request.
     *
     * Products without a reachable detail URL are dropped: an invitation with
     * no link is worse than no invitation, and the order stays eligible for the
     * next run once the sales channel domain or SEO url exists.
     *
     * @param array<string, ProductEntity> $productsById
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

            // The first line item for a target product wins the name/thumbnail.
            if (isset($collapsed[$targetId])) {
                continue;
            }

            $product = $this->urlBuilder->buildProduct($productId, $parentId, $order, $context);

            if ($product->reviewUrl === null) {
                continue;
            }

            $collapsed[$targetId] = $product;
        }

        return array_values($collapsed);
    }

    /**
     * Reviews aggregate across a variant family, so both the variant id and the
     * parent id must be considered. Checking only the variant re-nags customers
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

        // Scoped to this customer rather than to the product ids: an id list
        // needs one OR branch per product, which explodes query length on a
        // 50-line B2B order and gains nothing over a customer-id lookup.
        $criteria = (new Criteria())->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addAssociation('product');
        $criteria->setLimit(self::MAX_SEARCH_RESULTS);

        $reviewedTargets = [];

        foreach ($this->reviewRepository->search($criteria, $context) as $review) {
            $reviewedTargets[(string) $review->getProductId()] = true;

            // The association is loaded above; without it getProduct() is null
            // and the parent branch is silently dead.
            $parentId = $review->getProduct()?->getParentId();
            if ($parentId !== null) {
                $reviewedTargets[(string) $parentId] = true;
            }
        }

        if ($reviewedTargets === []) {
            return $products;
        }

        return array_values(array_filter(
            $products,
            static fn (ReviewReminderProduct $p): bool => !isset($reviewedTargets[$p->getReviewTargetId()])
        ));
    }
}