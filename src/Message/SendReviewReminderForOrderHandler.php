<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Message;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderConfigService;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderDispatcher;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderServiceInterface;

/**
 * Delivers one order's reminder. The delay lives in the queue, so this runs
 * when the customer's waiting period has actually elapsed rather than at the
 * next batch boundary.
 *
 * `handles:` is explicit on purpose. Several plugins in this installation
 * register `#[AsMessageHandler]` with no argument, which makes them receive
 * every message on the transport — including this one.
 */
#[AsMessageHandler(handles: SendReviewReminderForOrder::class)]
final readonly class SendReviewReminderForOrderHandler
{
    public function __construct(
        private ReviewReminderServiceInterface $reminderService,
        private ReviewReminderDispatcher $dispatcher,
        private ReviewReminderConfigService $configService,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(SendReviewReminderForOrder $message): void
    {
        $context = Context::createCLIContext();

        // Re-checked here, not just at scheduling time. The order may sit in
        // the queue for weeks, and the plugin may have been switched off in
        // between.
        if (!$this->configService->isEnabled()) {
            $this->logger->info('Product review reminders are disabled — dropping scheduled reminder.');

            return;
        }

        $candidates = $this->reminderService->collectCandidates($context, [$message->getOrderId()]);

        if ($candidates === []) {
            // Order cancelled, guest, every product unlinkable, or already
            // reminded by an earlier run. All normal.
            $this->logger->info(sprintf('Scheduled review reminder for order %s has no candidates.', $message->getOrderId()));

            return;
        }

        $result = $this->dispatcher->dispatch($candidates, $context);

        $this->logger->info(sprintf(
            'Scheduled review reminder — order %s, sent: %d, failed: %d, skipped: %d',
            $message->getOrderId(),
            $result['sent'],
            $result['failed'],
            $result['skipped']
        ));
    }
}
