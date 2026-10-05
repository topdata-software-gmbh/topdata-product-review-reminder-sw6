<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Entity\ReviewReminder;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * One row per customer: the customer's standing decision about being reminded
 * about reviews.
 *
 * revoked_at = NULL means "consented". A row is never deleted, so the moment of
 * consent (granted_at) always stays provable, including after a revocation.
 *
 * Properties are declared, not promoted: the DAL hydrator assigns them by
 * name, so they must be non-promoted and non-readonly.
 */
class ReviewReminderConsentEntity extends Entity
{
    /**
     * Declared because the DAL base Entity does not declare it. Without this the
     * hydrator assigns `$id` as a dynamic property, which is deprecated in PHP
     * 8.4 — and it did, once per consent write, straight into the log.
     */
    protected string $id;

    protected string $customerId;

    protected \DateTimeInterface $grantedAt;

    protected ?\DateTimeInterface $revokedAt;

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function setCustomerId(string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getGrantedAt(): \DateTimeInterface
    {
        return $this->grantedAt;
    }

    public function setGrantedAt(\DateTimeInterface $grantedAt): void
    {
        $this->grantedAt = $grantedAt;
    }

    public function getRevokedAt(): ?\DateTimeInterface
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeInterface $revokedAt): void
    {
        $this->revokedAt = $revokedAt;
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }
}