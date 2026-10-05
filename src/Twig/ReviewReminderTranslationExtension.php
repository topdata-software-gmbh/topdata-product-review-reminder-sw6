<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Twig;

use Shopware\Core\Framework\Log\Package;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderTranslationService;

/**
 * Exposes ReviewReminderTranslationService to the storefront templates.
 *
 * Needed because `|trans` cannot resolve this plugin's copy: in 6.7 the
 * translator reads only the `snippet` table, and plugin snippet files are
 * never written there (verified: 0 rows for TopdataProductReviewReminderSW6).
 * `|trans` would render the raw key. The service reads the shipped JSON
 * instead. Same reason the mail template does not use `|trans`.
 *
 * Locale comes from the caller — in a template that is
 * `context.languageInfo.localeCode`.
 */
#[Package('after-sales')]
final class ReviewReminderTranslationExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly ReviewReminderTranslationService $translationService,
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'topdataReviewReminder' => $this->translationService,
        ];
    }
}