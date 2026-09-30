<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

final readonly class ReviewReminderMailer implements ReviewReminderMailerInterface
{
    public const SUBJECT_KEY = 'reviewReminderSubject';

    private const SUBJECT_FALLBACK = 'How was your order?';

    public function __construct(
        private AbstractMailService $mailService,
        private ReviewReminderTemplateRenderer $templateRenderer,
        private ReviewReminderTranslationService $translation,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'sales_channel.repository')]
        private EntityRepository $salesChannelRepository
    ) {
    }

    public function send(ReviewReminderCandidate $candidate, Context $context): bool
    {
        $html = $this->templateRenderer->render($candidate, $context);

        if (trim($html) === '') {
            return false;
        }

        $plain = $this->templateRenderer->renderPlain($candidate, $context);

        if (trim($plain) === '') {
            return false;
        }

        // `contentPlain` is mandatory, not optional: MailService validates its
        // payload with NotBlank and throws a ConstraintViolationException
        // without it, which fails the send for every order. See
        // MailService::getValidationDefinition().
        //
        // AbstractMailService::send() returns NULL on failure instead of
        // throwing: a null result must count as "not sent" and must not be
        // stamped in the log.
        $mail = $this->mailService->send([
            'recipients' => [$candidate->email => $candidate->customerFirstName],
            'salesChannelId' => $candidate->salesChannelId,
            'subject' => $this->resolveSubject($candidate),
            'senderName' => $this->resolveSenderName($candidate, $context),
            'contentHtml' => $html,
            'contentPlain' => $plain,
        ], $context);

        return $mail !== null;
    }

    /**
     * `senderName` is mandatory: MailService::createMail() reads $data['senderName']
     * unguarded, so a missing key is an ErrorException and fails the send.
     *
     * It is also run through the Twig renderer, so this must be plain text —
     * a sales channel name containing `{{` would be interpreted as a template.
     * The sales channel name is the closest thing to a sender identity that is
     * already per-channel correct; "Shop" only matters if that row is missing.
     */
    private function resolveSenderName(ReviewReminderCandidate $candidate, Context $context): string
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter('id', $candidate->salesChannelId)
        );
        $criteria->setLimit(1);

        $name = (string) ($this->salesChannelRepository->search($criteria, $context)->first()?->getName() ?? '');

        return trim($name) !== '' ? trim($name) : 'Shop';
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
