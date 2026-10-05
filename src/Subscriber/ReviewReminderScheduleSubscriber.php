<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Topdata\TopdataProductReviewReminderSW6\Message\SendReviewReminderForOrder;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderConfigService;

/**
 * Schedules one reminder per placed order, due exactly `delayDays` after the
 * order date. With `delayDays` at 0 the delay is zero and the message goes out
 * on the next worker poll, which is what "immediately after the order" means
 * in practice.
 *
 * `CheckoutOrderPlacedEvent` rather than the generic DAL write event, because
 * the DAL has no reliable insert/update discriminator in 6.7:
 * `EntityWrittenEvent::getExistences()` is deprecated and throws, and there is
 * no `isFirstRun()`. Every order update — payment state, tracking number —
 * fires a write event, so subscribing to that would reschedule the reminder
 * and push the due date further out on each change.
 */
final readonly class ReviewReminderScheduleSubscriber implements EventSubscriberInterface
{
    /**
     * Grace period before a reminder becomes due.
     *
     * `CheckoutOrderPlacedEvent` fires inside the checkout request, before the
     * cart is converted into line items in every case, and before the order
     * has been fully committed in some. Dispatching without a floor would let
     * the handler find an order with no products and skip it forever, since
     * nothing would ever come back to retry. Two minutes is short enough to
     * read as "immediately" and long enough for the order to be complete.
     */
    private const MIN_DELAY_SECONDS = 120;
    public function __construct(
        private MessageBusInterface $messageBus,
        private ReviewReminderConfigService $configService,
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    /**
     * Runs inside Shopware's order placement, which is the customer's
     * checkout. Nothing here is allowed to escape: the order is already
     * persisted by the time this event fires, so a thrown error surfaces as a
     * 500 on the confirmation page for a purchase that actually went through.
     * A review reminder is not worth breaking a sale over, so a failure here
     * is logged and the daily reconciler sweep picks the order up instead.
     */
    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        try {
            $this->schedule($event);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Could not schedule product review reminder for order %s: %s', $event->getOrderId(), $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    private function schedule(CheckoutOrderPlacedEvent $event): void
    {
        if (!$this->configService->isEnabled()) {
            return;
        }

        $order = $event->getOrder();
        $delayDays = $this->configService->getDelayDays();

        // order_date_time, not order_date: the latter is a generated DATE and
        // would put every order of a day on the same due time.
        $orderedAt = $order->getOrderDateTime();
        $dueAt = $orderedAt?->modify(sprintf('+%d days', $delayDays)) ?? new \DateTimeImmutable();
        $seconds = max(self::MIN_DELAY_SECONDS, $dueAt->getTimestamp() - time());

        // DelayStamp counts MILLISECONDS in Symfony 7.4, not seconds. Passing
        // seconds here is silently wrong by a factor of 1000: a 14-day wait
        // becomes 20 minutes and the queue shows no error.
        $this->messageBus->dispatch(
            new SendReviewReminderForOrder($event->getOrderId()),
            [new DelayStamp($seconds * 1000)]
        );

        // getOrderNumber(), not getOrder(): OrderEntity has no __toString(),
        // so interpolating the entity into sprintf() throws.
        $this->logger->info(sprintf(
            'Scheduled review reminder for order %s (%s), delay %d day(s), due %s.',
            $order->getOrderNumber(),
            $event->getOrderId(),
            $delayDays,
            $dueAt->format('Y-m-d H:i:s')
        ));
    }
}
