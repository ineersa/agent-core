<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Ineersa\Tui\Listener\TuiListenerRegistrar;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;

/**
 * Synchronous initial index build at TUI startup.
 *
 * The Symfony Scheduler periodic task fires its first run one period
 * after the scheduler consumer starts (now + 30 s).  Without a startup
 * build, the index is absent when the user first types `@`, and file
 * mention completion silently returns no suggestions.
 *
 * This listener fills the window between TUI startup and the first
 * scheduler cycle by building the index synchronously during
 * {@see register()}.  Subsequent periodic refreshes are handled by the
 * Scheduler recurring task declared on
 * {@see CompletionFileIndexRefreshCommand}.
 *
 * This is a direct service call — no subprocess spawning, no tick
 * polling, no ad-hoc infrastructure.
 *
 * Degradation: if the build fails (lock held, scan error, I/O failure)
 * the listener swallows the exception after structured diagnostic
 * logging.  File mention completion shows no suggestions until the
 * scheduler refresh succeeds, preserving TUI startup reliability.
 */
final class FileMentionIndexStartupListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly FileMentionIndexBuilder $builder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        try {
            $this->builder->build();
        } catch (FileMentionIndexLockHeldException) {
            // Another process is already building the index — fine.
            $this->logger->info(
                'File mention index: startup build skipped, lock held by concurrent builder.',
                [
                    'component' => 'file_mention_index',
                    'event_type' => 'file_mention_index.startup_lock_held',
                ],
            );
        } catch (\RuntimeException $e) {
            // Build failure at startup — completion will show no
            // suggestions until the scheduler succeeds.
            $this->logger->error(
                'File mention index: startup build failed: {message}',
                [
                    'component' => 'file_mention_index',
                    'event_type' => 'file_mention_index.startup_build_failed',
                    'message' => $e->getMessage(),
                ],
            );
        }
    }
}
