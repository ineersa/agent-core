<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Rebuild the file mention completion index from the project CWD.
 *
 * Sole production refresh owner: Symfony Scheduler runs this every
 * 30s (and operators may invoke it manually). Never call from the
 * TUI input/startup path. Existing indexes are readable immediately;
 * a missing first-ever index leaves `@` completion empty until this
 * command succeeds (ponytail: no ad-hoc background process).
 *
 * Delegates to {@see FileMentionIndexBuilder}: Git-native enumeration
 * in worktrees, bounded Finder fallback otherwise, hard entry cap,
 * atomic temp+rename write.
 *
 * Exit behaviour:
 *   - SUCCESS when built or lock held (another build in progress).
 *   - FAILURE when scan/write/rename fails.
 */
#[AsCommand(
    name: 'completion:file-index:refresh',
    description: 'Rebuild the file mention completion index for the current project.',
)]
#[AsPeriodicTask(frequency: 30, schedule: 'default')]
final class CompletionFileIndexRefreshCommand extends Command
{
    public function __construct(
        private readonly FileMentionIndexBuilder $builder,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $_output): int
    {
        try {
            $count = $this->builder->build();

            $this->logger->info(
                'File mention index refreshed: {entry_count} entries written.',
                [
                    'component' => 'file_mention_index',
                    'event_type' => 'file_mention_index.refresh_completed',
                    'entry_count' => $count,
                ],
            );

            return Command::SUCCESS;
        } catch (FileMentionIndexLockHeldException $e) {
            // Lock held by another instance — no-op, not an error.
            $this->logger->info(
                'File mention index: refresh skipped, lock held by concurrent builder.',
                [
                    'component' => 'file_mention_index',
                    'event_type' => 'file_mention_index.refresh_lock_held',
                ],
            );

            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            // Build failure — surface to caller via non-zero exit.
            $this->logger->error(
                'File mention index refresh failed: {message}',
                [
                    'component' => 'file_mention_index',
                    'event_type' => 'file_mention_index.refresh_failed',
                    'message' => $e->getMessage(),
                ],
            );

            return Command::FAILURE;
        }
    }
}
