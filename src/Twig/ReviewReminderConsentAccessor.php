<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Twig;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderConsentService;

/**
 * Answers "does this customer consent?" from inside a template.
 *
 * The account overview page is rendered by core's AccountPage, not by this
 * plugin, so no controller ever injects the flag into the template context. The
 * SalesChannelContext is already a Twig global as `context`, so the template
 * passes it in and this class stays free of any context-resolver dependency —
 * there is no resolver interface for this in 6.7 anyway.
 *
 * Returns false when nobody is logged in, which is the safe direction: the
 * consent card is only reachable behind login, but the accessor must never
 * guess.
 */
final readonly class ReviewReminderConsentAccessor
{
    public function __construct(private ReviewReminderConsentService $consentService)
    {
    }

    public function isActive(SalesChannelContext $salesChannelContext): bool
    {
        $customer = $salesChannelContext->getCustomer();

        if ($customer === null) {
            return false;
        }

        $active = $this->consentService->isActive($customer->getId(), $salesChannelContext->getContext());

        return $active;
    }
}