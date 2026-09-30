<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Framework\Context;
use Twig\Environment;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

final readonly class ReviewReminderTemplateRenderer
{
    private const TEMPLATE = '@TopdataProductReviewReminderSW6/storefront/email/review-reminder.html.twig';

    public function __construct(
        private Environment $twig,
        private ReviewReminderTranslationService $translation
    ) {
    }

    public function render(ReviewReminderCandidate $candidate, Context $context): string
    {
        $firstName = $candidate->customerFirstName;

        return $this->twig->render(self::TEMPLATE, [
            'languageLocale' => $candidate->languageLocale,

            // Copy is resolved in PHP, not via `|trans`: see
            // ReviewReminderTranslationService for why the snippet table cannot
            // be used from a CLI/worker render.
            'labels' => $this->translation->getLabels($candidate->languageLocale, [
                // A guest name or a missing first name must not leave a
                // literal "%firstName%" in the customer's inbox.
                '%firstName%' => $firstName !== '' ? $firstName : '–',

                // orderNumber, not orderId: the customer sees SW10042, never a
                // 32-char hex UUID.
                '%orderNumber%' => $candidate->orderNumber,
            ]),

            'orderNumber' => $candidate->orderNumber,
            'products' => $candidate->products,
        ]);
    }
}
