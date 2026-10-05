<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Carries the due time in a delay stamp rather than in its own field, so the
 * queue itself holds it: `messenger_messages.available_at` is when the worker
 * may pick the message up. A due time stored on an entity would need someone
 * or something to poll it — which is the scheduled task this design replaces.
 *
 * `AsyncMessageInterface` is load-bearing. Shopware routes by interface, not
 * by class: only messages implementing `AsyncMessageInterface` are assigned
 * the `async` sender. A plain message class matches no routing rule, reaches
 * the default bus synchronously and is handled inside the dispatching
 * process — so a `DelayStamp` on it is ignored and the mail goes out
 * immediately regardless of the configured delay.
 */
class SendReviewReminderForOrder implements AsyncMessageInterface
{
    public function __construct(private readonly string $orderId)
    {
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }
}
