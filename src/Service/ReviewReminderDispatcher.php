<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;

/**
 * Orchestrator shared by the console command and the scheduled task.
 */
final readonly class ReviewReminderDispatcher
{
    public function __construct(
        private ReviewReminderMailerInterface $mailer,
        private ReviewReminderLogService $logService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param list<ReviewReminderCandidate> $candidates
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function dispatch(array $candidates, Context $context): array
    {
        $sent = $failed = $skipped = 0;

        foreach ($candidates as $candidate) {
            // Claim first. The unique index is the concurrency authority and
            // has to be consulted BEFORE the side effect, otherwise a lost race
            // still produces a second email.
            if (!$this->logService->claim($candidate, $context)) {
                ++$skipped;

                continue;
            }

            try {
                $delivered = $this->mailer->send($candidate, $context);
            } catch (\Throwable $e) {
                $delivered = false;

                $this->logger->error(
                    sprintf('Review reminder mail failed for order %s: %s', $candidate->orderNumber, $e->getMessage()),
                    ['exception' => $e]
                );
            }

            if (!$delivered) {
                // Release so the next run retries. A permanently held claim
                // would silently drop this customer forever.
                $this->logService->release($candidate->orderId, $context);
                ++$failed;

                continue;
            }

            $this->logService->markSent($candidate->orderId, $context);
            ++$sent;
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }
}