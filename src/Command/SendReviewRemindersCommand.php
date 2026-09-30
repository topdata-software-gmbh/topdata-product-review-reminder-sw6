<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Command;

use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataProductReviewReminderSW6\Model\ReviewReminderCandidate;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderConfigService;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderDispatcher;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderServiceInterface;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderTemplateRenderer;

#[AsCommand(
    name: 'topdata:product-review-reminder:send',
    description: 'Send post-purchase product review reminders (dry-run by default).'
)]
class SendReviewRemindersCommand extends AbstractTopdataCommand
{
    /**
     * This plugin runs against a LIVE shop whose SMTP relay has delivery
     * enabled (`core.mailerSettings` -> mailpit.topinfra.de:1025 with
     * disableDelivery = false), so a send reaches real inboxes. A default limit
     * of 1 makes an accidental `--send --ignore-enabled` a single test mail
     * instead of a mass send.
     */
    private const DEFAULT_LIMIT = 1;

    private const PREVIEW_FILE = 'topdata-product-review-reminder-preview.html';

    public function __construct(
        private readonly ReviewReminderServiceInterface $reminderService,
        private readonly ReviewReminderDispatcher $dispatcher,
        private readonly ReviewReminderTemplateRenderer $templateRenderer,
        private readonly ReviewReminderConfigService $configService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('send', null, InputOption::VALUE_NONE, 'Actually send the emails. Without this flag NOTHING is sent.')
            ->addOption('order', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to specific order IDs.')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max orders to process in this run.', (string) self::DEFAULT_LIMIT)
            ->addOption('ignore-enabled', null, InputOption::VALUE_NONE, 'Run even when the plugin config has enabled=false.')
            ->addOption('preview', null, InputOption::VALUE_NONE, 'Render the mail HTML of the first candidate to ' . self::PREVIEW_FILE . ' and print the path. Never sends.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = Context::createCLIContext();
        $orderIds = (array) $input->getOption('order');
        $dryRun = !$input->getOption('send');
        $limit = max(1, (int) $input->getOption('limit'));

        if (!$this->configService->isEnabled() && !$input->getOption('ignore-enabled')) {
            CliLogger::warning('Plugin config "enabled" is false — nothing to do. Pass --ignore-enabled to override.');

            return Command::SUCCESS;
        }

        $candidates = $this->reminderService->collectCandidates($context, $orderIds);
        $total = count($candidates);

        $candidates = array_slice($candidates, 0, $limit);

        CliLogger::section(sprintf(
            'Eligible orders: %d (processing %d, --limit=%d)',
            $total,
            count($candidates),
            $limit
        ));

        if ($candidates === []) {
            CliLogger::info('No eligible orders. Check the delay setting and whether orders are cancelled, guest-only or already reviewed.');

            return Command::SUCCESS;
        }

        $this->renderTable($candidates);

        if ($total > count($candidates)) {
            CliLogger::warning(sprintf(
                '%d eligible order(s) not shown or processed. Re-run to continue, or raise --limit.',
                $total - count($candidates)
            ));
        }

        if ($input->getOption('preview')) {
            $this->writePreview($candidates[0], $context);
        }

        if ($dryRun) {
            CliLogger::warning('DRY RUN — nothing was sent. Re-run with --send to deliver.');
            $this->done();

            return Command::SUCCESS;
        }

        // Last gate before a live SMTP send. The recipient list has just been
        // printed; make the operator confirm it against the table above.
        if (!CliLogger::getCliStyle()->confirm(
            sprintf('Send %d email(s) to the addresses above?', count($candidates)),
            false
        )) {
            CliLogger::warning('Aborted by operator. Nothing was sent.');

            return Command::SUCCESS;
        }

        $result = $this->dispatcher->dispatch($candidates, $context);

        CliLogger::success(sprintf(
            'Sent: %d, Failed: %d, Skipped (already logged): %d',
            $result['sent'],
            $result['failed'],
            $result['skipped']
        ));

        if ($result['failed'] > 0) {
            CliLogger::warning(sprintf('%d order(s) failed to send and remain eligible for the next run.', $result['failed']));
        }

        $this->done();

        return Command::SUCCESS;
    }

    /**
     * @param list<ReviewReminderCandidate> $candidates
     */
    private function renderTable(array $candidates): void
    {
        $table = CliLogger::getCliStyle()->createTable();
        $table->setHeaders(['Order', 'Recipient', 'Products', 'Link']);

        foreach ($candidates as $candidate) {
            $table->addRow([
                $candidate->orderNumber,
                $candidate->email,
                (string) count($candidate->products),
                $candidate->products[0]->reviewUrl ?? '(none)',
            ]);
        }

        $table->render();
    }

    /**
     * Renders the real mail HTML to disk so the template, the snippets and the
     * links can be inspected without touching the mailer.
     */
    private function writePreview(ReviewReminderCandidate $candidate, Context $context): void
    {
        $path = rtrim($this->projectDir, '/') . '/var/log/' . self::PREVIEW_FILE;

        try {
            $html = $this->templateRenderer->render($candidate, $context);
        } catch (\Throwable $e) {
            CliLogger::error('Preview render failed: ' . $e->getMessage());

            return;
        }

        file_put_contents($path, $html);
        CliLogger::success(sprintf('Preview for order %s written to %s', $candidate->orderNumber, $path));
    }
}