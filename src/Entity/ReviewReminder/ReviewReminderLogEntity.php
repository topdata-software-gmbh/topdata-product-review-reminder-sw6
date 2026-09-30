<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Entity\ReviewReminder;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * Append-only audit row. One row per order that a reminder was claimed for.
 *
 * Properties are declared, not promoted: the DAL hydrator assigns them by
 * name, so they must be non-promoted and non-readonly.
 */
class ReviewReminderLogEntity extends Entity
{
    protected string $orderId;

    protected string $orderVersionId;

    protected string $customerId;

    protected string $salesChannelId;

    protected string $email;

    protected ?string $reviewProductIds;

    protected ?\DateTimeInterface $sentAt;

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getOrderVersionId(): string
    {
        return $this->orderVersionId;
    }

    public function setOrderVersionId(string $orderVersionId): void
    {
        $this->orderVersionId = $orderVersionId;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function setCustomerId(string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getReviewProductIds(): ?string
    {
        return $this->reviewProductIds;
    }

    public function setReviewProductIds(?string $reviewProductIds): void
    {
        $this->reviewProductIds = $reviewProductIds;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeInterface $sentAt): void
    {
        $this->sentAt = $sentAt;
    }
}