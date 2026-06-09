<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Rebuild the file mention completion index from the project CWD.
 *
 * The index path and project CWD are injected as constructor
 * arguments (resolved from container parameters) so callers do not
 * need to derive or duplicate the path.
 *
 * Intended to be called offline (via Scheduler, a periodic task,
 * or manually), never from the TUI input handler.  Uses Symfony
 * Finder with explicit excludes and a hard entry cap.
 *
 * Recurrently invoked by the Scheduler consumer every 30 seconds.
 * The from: '-30 seconds' ensures the first run fires near-immediately
 * when the scheduler consumer starts (barring a short Messsenger
 * warm-up window), so the index is available promptly for @ completion.
 *
 * Atomic: writes to a temp file first, then renames into place.
 *
 * Exit behaviour:
 *   - SUCCESS when lock is held (another build in progress).
 *   - FAILURE when scan/write/rename fails — the caller (listener)
 *     sees a non-zero exit code and can log a diagnostic.
 */
#[AsCommand(
    name: 'completion:file-index:refresh',
    description: 'Rebuild the file mention completion index for the current project.',
)]
#[AsPeriodicTask(frequency: 30, from: '-30 seconds', schedule: 'default')]
final class CompletionFileIndexRefreshCommand extends Command
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $cwd,
        private readonly string $indexPath,
        private readonly ?LockFactory $lockFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
        $this->logger = $logger ?? new NullLogger();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $builder = new FileMentionIndexBuilder(
            cwd: $this->cwd,
            indexPath: $this->indexPath,
            logger: $this->logger,
            lockFactory: $this->lockFactory,
        );

        try {
            $count = $builder->build();
            $output->writeln("File mention index refreshed: {$count} entries written to {$this->indexPath}");

            return Command::SUCCESS;
        } catch (FileMentionIndexLockHeldException $e) {
            // Lock held by another instance — no-op, not an error.
            $output->writeln("File mention index refresh skipped: {$e->getMessage()}");

            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            // Build failure — surface to caller via non-zero exit.
            $this->logger->error(
                'File mention index refresh failed: {message}',
                [
                    'component' => 'file_mention_index',
                    'event_type' => 'file_mention_index.refresh_failed',
                    'message' => $e->getMessage(),
                    'cwd' => $this->cwd,
                    'index_path' => $this->indexPath,
                ],
            );

            $output->writeln("Error: file mention index refresh failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
