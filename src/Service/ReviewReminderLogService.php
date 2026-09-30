<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

/**
 * Owns the write path, including duplicate-key tolerance.
 *
 * Claim-then-send, never send-then-log: the unique index on order_id prevents
 * a duplicate *row*, not a duplicate *email*. Writing the row after the send
 * leaves a window in which two workers both pass the "not already logged"
 * pre-filter and both send. The claim is therefore the first side effect.
 */
final readonly class ReviewReminderLogService
{
    public function __construct(private EntityRepository $logRepository)
    {
    }

    /**
     * Reserve the order for this run. False means another run already holds the
     * claim and the caller must not send.
     */
    public function claim(ReviewReminderCandidate $candidate, Context $context): bool
    {
        try {
            $this->logRepository->create([[
                'orderId' => $candidate->orderId,
                'orderVersionId' => $candidate->orderVersionId,
                'customerId' => $candidate->customerId,
                'salesChannelId' => $candidate->salesChannelId,
                'email' => $candidate->email,
                'reviewProductIds' => json_encode(
                    $candidate->getReviewTargetIds(),
                    \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES
                ),
                'sentAt' => null,
            ]], $context);

            return true;
        } catch (UniqueConstraintViolationException) {
            // Losing the race is a normal outcome, not an error. Same pattern as
            // core's MessageQueue/ScheduledTask/Registry/TaskRegistry.php.
            return false;
        }
    }

    /**
     * Stamp the claim as actually sent. Called only after a successful mail
     * call; a claim left with sent_at = NULL is a crashed run, not a sent
     * reminder, and stays eligible for the next run.
     */
    public function markSent(string $orderId, Context $context): void
    {
        // EntityRepository::update() takes a list of id maps in 6.7, not a
        // Criteria. Getting this wrong is not a no-op: without the stamp the
        // order stays eligible forever and the customer is re-invited daily.
        foreach ($this->findOpenClaimIds($orderId, $context) as $id) {
            $this->logRepository->update([
                ['id' => $id, 'sentAt' => new \DateTimeImmutable()],
            ], $context);
        }
    }

    /**
     * Drop the claim so a failed send is retried. Without this a transient SMTP
     * outage would permanently swallow the customer.
     *
     * Only unstamped rows are touched. A row that already carries a sent_at
     * means the mail went out, and deleting it would re-invite the customer on
     * the next run.
     */
    public function release(string $orderId, Context $context): void
    {
        $ids = $this->findOpenClaimIds($orderId, $context);

        if ($ids === []) {
            return;
        }

        $this->logRepository->delete(array_map(
            static fn (string $id): array => ['id' => $id],
            $ids
        ), $context);
    }

    /**
     * Ids of this order's log rows that are not yet stamped as sent, i.e. the
     * claim of an in-flight or crashed run. At most one, because order_id is
     * unique.
     *
     * array_values() is required: getElements() is keyed by primary key and the
     * DAL writer rejects input that is not a list.
     *
     * @return list<string>
     */
    private function findOpenClaimIds(string $orderId, Context $context): array
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter('orderId', $orderId),
            new EqualsFilter('sentAt', null),
        );

        return array_values(array_map(
            static fn ($log): string => (string) $log->getUniqueIdentifier(),
            $this->logRepository->search($criteria, $context)->getElements()
        ));
    }
}