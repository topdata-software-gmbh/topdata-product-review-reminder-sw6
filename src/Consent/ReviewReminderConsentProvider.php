<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Consent;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Topdata\TopdataConsentSW6\Consent\ConsentProviderInterface;
use Topdata\TopdataConsentSW6\Consent\ConsentSource;
use Topdata\TopdataConsentSW6\Consent\ConsentStatus;
use Topdata\TopdataConsentSW6\Consent\ConsentSurface;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderConsentService;

#[AutoconfigureTag('topdata_consent.provider')]
final readonly class ReviewReminderConsentProvider implements ConsentProviderInterface
{
    private const KEY = 'topdata_product_review_reminder';

    public function __construct(
        private ReviewReminderConsentService $consentService,
        private SystemConfigService $systemConfigService,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getLabel(): string
    {
        return 'TopdataProductReviewReminderSW6.consentLabel';
    }

    public function getHint(): ?string
    {
        return 'TopdataProductReviewReminderSW6.consentHint';
    }

    public function getPosition(): int
    {
        return 20;
    }

    public function isVisible(ConsentSurface $surface, SalesChannelContext $context): bool
    {
        $salesChannelId = $context->getSalesChannelId();

        if (!(bool) $this->systemConfigService->get('TopdataProductReviewReminderSW6.config.enabled', $salesChannelId)) {
            return false;
        }

        if ($surface === ConsentSurface::Registration) {
            // `?? true`: the config row only exists once the settings form has been saved (or a
            // migration seeded it). The config.xml default alone is not visible to
            // SystemConfigService at runtime, so without the fallback the registration checkbox
            // would be silently hidden by default.
            return (bool) ($this->systemConfigService->get(
                'TopdataProductReviewReminderSW6.config.consentCheckboxOnRegistration',
                $salesChannelId
            ) ?? true);
        }

        return true;
    }

    public function getStatus(string $customerId, Context $context): ConsentStatus
    {
        return match ($this->consentService->getDecision($customerId, $context)) {
            true => ConsentStatus::Granted,
            false => ConsentStatus::Revoked,
            null => ConsentStatus::Unknown,
        };
    }

    public function setGranted(string $customerId, bool $granted, Context $context, ConsentSource $source): void
    {
        if ($granted) {
            $this->consentService->grant($customerId, $context);

            return;
        }

        $this->consentService->revoke($customerId, $context);
    }
}
