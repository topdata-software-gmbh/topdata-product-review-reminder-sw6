<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\Context;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

final readonly class ReviewReminderMailer implements ReviewReminderMailerInterface
{
    public const SUBJECT_KEY = 'reviewReminderSubject';

    private const SUBJECT_FALLBACK = 'How was your order?';

    public function __construct(
        private AbstractMailService $mailService,
        private ReviewReminderTemplateRenderer $templateRenderer,
        private ReviewReminderTranslationService $translation
    ) {
    }

    public function send(ReviewReminderCandidate $candidate, Context $context): bool
    {
        $html = $this->templateRenderer->render($candidate, $context);

        if (trim($html) === '') {
            return false;
        }

        // AbstractMailService::send() returns NULL on failure instead of
        // throwing: a null result must count as "not sent" and must not be
        // stamped in the log.
        $mail = $this->mailService->send([
            'recipients' => [$candidate->email => $candidate->customerFirstName],
            'salesChannelId' => $candidate->salesChannelId,
            'subject' => $this->resolveSubject($candidate),
            'contentHtml' => $html,
        ], $context);

        return $mail !== null;
    }

    /**
     * AbstractMailService::send() takes the subject as a plain string, so the
     * copy has to be resolved before the call. The candidate already carries
     * the resolved language locale, so this is a file read and not a query.
     *
     * Never send an empty subject line: fall back to the built-in English one.
     */
    private function resolveSubject(ReviewReminderCandidate $candidate): string
    {
        $subject = trim($this->translation->getSubject($candidate->languageLocale));

        return $subject !== '' ? $subject : self::SUBJECT_FALLBACK;
    }
}
