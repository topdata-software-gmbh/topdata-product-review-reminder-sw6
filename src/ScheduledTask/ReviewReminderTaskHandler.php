<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderConfigService;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderDispatcher;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderServiceInterface;

/**
 * Logs through PSR-3, not CliLogger: this runs inside the messenger worker,
 * where there is no OutputInterface and CliLogger has nothing to write to.
 *
 * $logger and the inherited $exceptionLogger are two different roles — one is
 * application logging, the other is the base class's scheduled-task error sink.
 */
#[AsMessageHandler(handles: ReviewReminderTask::class)]
class ReviewReminderTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ReviewReminderServiceInterface $reminderService,
        private readonly ReviewReminderDispatcher $dispatcher,
        private readonly ReviewReminderConfigService $configService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        if (!$this->configService->isEnabled()) {
            $this->logger->info('Product review reminders are disabled — skipping run.');

            return;
        }

        $context = Context::createCLIContext();
        $candidates = $this->reminderService->collectCandidates($context);

        if ($candidates === []) {
            $this->logger->info('No eligible orders for product review reminders.');

            return;
        }

        $result = $this->dispatcher->dispatch($candidates, $context);

        $this->logger->info(sprintf(
            'Product review reminders — sent: %d, failed: %d, skipped: %d',
            $result['sent'],
            $result['failed'],
            $result['skipped']
        ));
    }
}