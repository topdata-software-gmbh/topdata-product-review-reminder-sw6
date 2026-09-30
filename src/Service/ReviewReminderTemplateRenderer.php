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
        return $this->twig->render(self::TEMPLATE, [
            'languageLocale' => $candidate->languageLocale,
            'labels' => $this->resolveLabels($candidate),
            'orderNumber' => $candidate->orderNumber,
            'products' => $candidate->products,
        ]);
    }

    /**
     * Plain-text alternative for the same mail.
     *
     * Shopware's `MailService::send()` validates its payload and rejects a
     * blank `contentPlain` with a `ConstraintViolationException` — see
     * MailService::getValidationDefinition(). The HTML part alone is not enough,
     * so this builds a real text/plain body rather than stripping tags off the
     * HTML, which would mangle the URLs. Both parts share resolveLabels(), so
     * the two bodies can never disagree about the wording.
     */
    public function renderPlain(ReviewReminderCandidate $candidate, Context $context): string
    {
        $labels = $this->resolveLabels($candidate);

        $lines = [];
        $lines[] = $labels['reviewReminderHeadline'] ?? '';
        $lines[] = '';
        $lines[] = $labels['reviewReminderIntro'] ?? '';
        $lines[] = '';

        foreach ($candidate->products as $product) {
            $lines[] = '- ' . $product->name;

            // The URL stays on one unbroken line. Inserting soft hyphens or
            // zero-width spaces to "wrap" it would leave invisible characters
            // inside the link, which breaks auto-linking and copy-paste. Mail
            // clients soft-wrap long lines themselves.
            $lines[] = '  ' . $product->reviewUrl;
            $lines[] = '';
        }

        $lines[] = $labels['reviewReminderCta'] ?? '';
        $lines[] = '';
        $lines[] = $labels['reviewReminderFooter'] ?? '';

        return trim(implode("\n", $lines));
    }

    /**
     * Copy is resolved in PHP, not via `|trans`: see
     * ReviewReminderTranslationService for why the snippet table cannot be used
     * from a CLI/worker render.
     *
     * @return array<string, string>
     */
    private function resolveLabels(ReviewReminderCandidate $candidate): array
    {
        $firstName = $candidate->customerFirstName;

        return $this->translation->getLabels($candidate->languageLocale, [
            // A guest name or a missing first name must not leave a literal
            // "%firstName%" in the customer's inbox.
            '%firstName%' => $firstName !== '' ? $firstName : '–',

            // orderNumber, not orderId: the customer sees SW10042, never a
            // 32-char hex UUID.
            '%orderNumber%' => $candidate->orderNumber,
        ]);
    }
}
