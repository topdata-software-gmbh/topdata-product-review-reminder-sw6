<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Single definition of every setting, so the console command and the scheduled
 * task can never disagree about what is enabled.
 */
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

        // 0 is a legitimate value and means "invite on the next run": every
        // order whose order_date has passed is eligible immediately. It was
        // previously clamped to the default, on the assumption that a
        // hand-typed 0 would notify every order on every run. That assumption
        // is wrong — the unique index on order_id means an order is invited at
        // most once, whatever delayDays is set to.
        //
        // Note that a *missing* config row also reads as 0, because
        // getInt() casts null. That is not a storm risk: isEnabled() reads
        // false in the same situation, so a missing config means the plugin
        // sends nothing until someone deliberately enables it. `enabled` is the
        // master switch, not delayDays.
        //
        // Negative values never reach here — Shopware's config store coerces
        // them to 0 — but the fallback keeps the signature total.
        return $days >= 0 ? $days : self::DEFAULT_DELAY_DAYS;
    }
}