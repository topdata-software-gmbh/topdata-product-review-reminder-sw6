<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
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
        $cutoff = (new \DateTimeImmutable())->modify(
            sprintf('-%d days', $this->configService->getDelayDays())
        );

        $filters = [
            // RangeFilter only accepts scalars, and `order_date` is stored as
            // DATETIME(3), so the cutoff has to be pre-formatted.
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
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('orderCustomer.customer');
        $criteria->addAssociation('lineItems');

        // No addAssociation('salesChannel'): the candidate only needs the
        // scalar FKs already on the `order` row.
        $criteria->setLimit(self::MAX_SEARCH_RESULTS);

        $orders = $this->orderRepository->search($criteria, $context);

        // The DAL cannot express "the associated customer is absent" with an
        // EqualsFilter, and a guest order has to be dropped here. Saving a
        // review requires a logged-in customer, so a guest is unreachable.
        $orders = $orders->filter(
            static fn (OrderEntity $order): bool => $order->getOrderCustomer()?->getCustomerId() !== null
        );

        return $this->excludeAlreadyReminded($orders, $context);
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