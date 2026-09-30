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

        // A hand-typed 0 would notify every order on every run. Anything below 1
        // falls back to the documented default.
        return $days > 0 ? $days : self::DEFAULT_DELAY_DAYS;
    }
}