<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Topdata\TopdataProductReviewReminderSW6\Entity\ReviewReminder\ReviewReminderConsentEntity;

/**
 * Read and write path for the customer's opt-in.
 *
 * Absence of a row means "never consented" and is treated as inactive
 * everywhere. There is no default-on path: an existing customer who never opens
 * the account page must not start receiving reminders.
 */
final readonly class ReviewReminderConsentService
{
    public function __construct(private EntityRepository $consentRepository)
    {
    }

    public function isActive(string $customerId, Context $context): bool
    {
        $consent = $this->find($customerId, $context);

        return $consent !== null && $consent->isActive();
    }

    /**
     * Start of the current consent period, or null if consent does not stand.
     *
     * Callers use this as the lower bound for order_date_time: a customer who
     * consents today must not be reminded about an order placed last month.
     */
    public function activeSince(string $customerId, Context $context): ?\DateTimeImmutable
    {
        $consent = $this->find($customerId, $context);

        if ($consent === null || !$consent->isActive()) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($consent->getGrantedAt());
    }

    /**
     * customer_id => granted_at for every customer of the batch that currently
     * consents. Customers without a standing consent are absent from the map.
     *
     * Batched on purpose: the candidate filter and the send-time guard both
     * need this per order, and one query per order would turn the daily sweep
     * into hundreds of round trips.
     *
     * @param list<string> $customerIds
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function activeSinceForCustomers(array $customerIds, Context $context): array
    {
        if ($customerIds === []) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new OrFilter(
            array_map(
                static fn (string $customerId): EqualsFilter => new EqualsFilter('customerId', $customerId),
                $customerIds
            )
        ));
        $criteria->addFilter(new EqualsFilter('revokedAt', null));
        $criteria->setLimit(\count($customerIds));

        $grants = [];
        foreach ($this->consentRepository->search($criteria, $context)->getElements() as $consent) {
            /** @var ReviewReminderConsentEntity $consent */
            $grants[$consent->getCustomerId()] = \DateTimeImmutable::createFromInterface($consent->getGrantedAt());
        }

        return $grants;
    }

    /**
     * Record the opt-in. Re-granting after a revocation re-anchors granted_at
     * instead of reviving the old timestamp, so the "orders after consent" rule
     * cannot be satisfied retroactively through a toggle.
     */
    public function grant(string $customerId, Context $context): void
    {
        $now = new \DateTimeImmutable();
        $existingId = $this->findId($customerId, $context);

        if ($existingId !== null) {
            $this->consentRepository->update([
                ['id' => $existingId, 'grantedAt' => $now, 'revokedAt' => null],
            ], $context);

            return;
        }

        try {
            $this->consentRepository->create([[
                'customerId' => $customerId,
                'grantedAt' => $now,
                'revokedAt' => null,
            ]], $context);
        } catch (UniqueConstraintViolationException) {
            // Two concurrent submits raced; the other one inserted first. Fall
            // back to the update path so the caller's intent still lands.
            $existingId = $this->findId($customerId, $context);

            if ($existingId === null) {
                // Unreachable in practice: the violation proves a row exists.
                // Failing loudly beats silently reporting a consent that was
                // never stored.
                throw new \RuntimeException(\sprintf(
                    'Review reminder consent insert for customer %s hit a unique violation but no row could be read back.',
                    $customerId
                ));
            }

            $this->consentRepository->update([
                ['id' => $existingId, 'grantedAt' => $now, 'revokedAt' => null],
            ], $context);
        }
    }

    /**
     * Record the opt-out. The row is kept with revoked_at set rather than
     * deleted, so the withdrawal itself remains on record.
     *
     * Deliberately does NOT throw when no row exists: revoking something that
     * was never granted is the desired end state, not an error.
     */
    public function revoke(string $customerId, Context $context): void
    {
        $existingId = $this->findId($customerId, $context);

        if ($existingId === null) {
            return;
        }

        $this->consentRepository->update([
            ['id' => $existingId, 'revokedAt' => new \DateTimeImmutable()],
        ], $context);
    }

    private function find(string $customerId, Context $context): ?ReviewReminderConsentEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('customerId', $customerId))
            ->setLimit(1);

        $consent = $this->consentRepository->search($criteria, $context)->first();

        return $consent instanceof ReviewReminderConsentEntity ? $consent : null;
    }

    private function findId(string $customerId, Context $context): ?string
    {
        $consent = $this->find($customerId, $context);

        return $consent === null ? null : (string) $consent->getUniqueIdentifier();
    }
}