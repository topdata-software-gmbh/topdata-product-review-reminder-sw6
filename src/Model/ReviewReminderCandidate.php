<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Model;

final readonly class ReviewReminderCandidate
{
    /**
     * @param list<ReviewReminderProduct> $products
     */
    public function __construct(
        public string $orderId,
        public string $orderVersionId,
        public string $customerId,
        public string $salesChannelId,
        public string $languageId,

        /**
         * Resolved locale code of $languageId, e.g. "de-CH". Carried on the
         * candidate so the send path never has to re-query the language, and so
         * the mail can be rendered from a worker with no sales channel context.
         */
        public string $languageLocale,
        public string $email,
        public string $customerFirstName,

        /** Customer-facing order number, e.g. "SW10042". Shown in the email. */
        public string $orderNumber,
        public \DateTimeInterface $orderDate,
        public array $products
    ) {
    }

    /**
     * @return list<string>
     */
    public function getReviewTargetIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (ReviewReminderProduct $p): string => $p->getReviewTargetId(),
            $this->products
        )));
    }
}