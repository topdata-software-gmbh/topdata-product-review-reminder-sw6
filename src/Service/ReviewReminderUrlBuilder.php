<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaDefinition;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderProduct;

/**
 * Builds the absolute product URL a customer clicks in the mail.
 *
 * Deliberately explicit about its four repositories: `sales_channel.product`
 * exposes neither `seoUrls` nor `coverMedia`, so SEO paths and thumbnails have
 * to come from `seo_url` / `product_media` directly.
 */
final readonly class ReviewReminderUrlBuilder
{
    /** The anchor rendered by core's review component on the product page. */
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

        $baseUrl = $this->loadBaseUrl($salesChannelId, $context);
        $seoPath = $this->loadSeoPath($productId, $salesChannelId, $languageId, $context);

        // Always absolute: a mail client has no storefront context to resolve a
        // relative path against. A missing domain or SEO row yields null and
        // the order is skipped by the caller rather than mailed with a dead
        // link.
        $url = ($baseUrl === '' || $seoPath === null)
            ? null
            : $baseUrl . '/' . ltrim($seoPath, '/') . self::REVIEW_ANCHOR;

        return new ReviewReminderProduct(
            productId: $productId,
            parentId: $parentId,
            name: $this->loadProductName($productId, $context),
            reviewUrl: $url,
            coverMediaUrl: $this->loadCoverMediaUrl($productId, $context)
        );
    }

    private function loadProductName(string $productId, Context $context): string
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('id', $productId));
        $criteria->addAssociation('translated');
        $criteria->setLimit(1);

        $product = $this->productRepository->search($criteria, $context)->first();

        // getTranslation() needs a field key in 6.7; 'name' is the translated
        // product name, and addAssociation('translated') above is what fills it.
        return (string) ($product?->getTranslation('name') ?? $productId);
    }

    private function loadSeoPath(
        string $productId,
        string $salesChannelId,
        string $languageId,
        Context $context
    ): ?string {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(SeoUrlDefinition::ENTITY_NAME . '.foreignKey', $productId),
            new EqualsFilter(SeoUrlDefinition::ENTITY_NAME . '.routeName', self::DETAIL_ROUTE),
            new EqualsFilter(SeoUrlDefinition::ENTITY_NAME . '.salesChannelId', $salesChannelId),
            new EqualsFilter(SeoUrlDefinition::ENTITY_NAME . '.languageId', $languageId),
            new EqualsFilter(SeoUrlDefinition::ENTITY_NAME . '.isDeleted', false),
        );
        $criteria->setLimit(1);

        return $this->seoUrlRepository->search($criteria, $context)->first()?->getSeoPathInfo();
    }

    private function loadBaseUrl(string $salesChannelId, Context $context): string
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter('salesChannelId', $salesChannelId),
        );
        $criteria->setLimit(1);

        $domain = $this->salesChannelDomainRepository->search($criteria, $context)->first();

        return rtrim((string) $domain?->getUrl(), '/');
    }

    private function loadCoverMediaUrl(string $productId, Context $context): ?string
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(ProductMediaDefinition::ENTITY_NAME . '.productId', $productId),
        );
        $criteria->addAssociation('media');
        $criteria->addSorting(new FieldSorting('position', FieldSorting::ASCENDING));
        $criteria->setLimit(1);

        $productMedia = $this->productMediaRepository->search($criteria, $context)->first();

        return $productMedia?->getMedia()?->getUrl();
    }
}