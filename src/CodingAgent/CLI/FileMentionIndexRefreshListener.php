<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\Tui\Listener\TuiListenerRegistrar;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Periodic file mention index refresh triggered from the TUI tick loop.
 *
 * On each tick (~every 500ms by Symfony TUI default), checks whether
 * the index file is missing or older than 30 seconds.  If stale and
 * no refresh process is already running, spawns a background
 * {@see CompletionFileIndexRefreshCommand} process.
 *
 * The background process is intentionally detached — the TUI tick
 * does not wait for it.  Completion providers see the updated index
 * on the next {@see \Ineersa\Tui\Completion\FileMentionIndexReader}
 * reload when mtime changes.
 *
 * Degradation: if spawning fails (e.g. missing executable, process
 * error), the failure is logged and the next tick retries.  The TUI
 * continues to operate with the previous index.
 */
final class FileMentionIndexRefreshListener implements TuiListenerRegistrar
{
    private const int STALE_SECONDS = 30;

    private ?Process $runningProcess = null;

    public function __construct(
        private readonly string $indexPath,
        private readonly RuntimeProcessConfig $processConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $context->ticks->add(
            $this->makeTickHandler(),
        );
    }

    /**
     * @return callable(\Symfony\Component\Tui\Event\TickEvent): ?bool
     */
    private function makeTickHandler(): callable
    {
        $indexPath = $this->indexPath;
        $processConfig = $this->processConfig;
        $logger = $this->logger;
        $self = $this;

        return static function () use (
            $indexPath, $processConfig, $logger, $self,
        ): ?bool {
            // Clean up completed processes.
            if (null !== $self->runningProcess) {
                if (!$self->runningProcess->isRunning()) {
                    $exitCode = $self->runningProcess->getExitCode();
                    if (0 !== $exitCode) {
                        $logger->debug(
                            'File mention index refresh process exited with code {code}',
                            ['code' => $exitCode],
                        );
                    }
                    $self->runningProcess = null;
                } else {
                    // Process still running — skip this tick.
                    return null;
                }
            }

            // Check staleness.
            if (is_file($indexPath)) {
                $mtime = filemtime($indexPath);

                if (false !== $mtime && (time() - $mtime) < self::STALE_SECONDS) {
                    // Index is recent enough.
                    return null;
                }
            }

            // Spawn background refresh process.
            try {
                $cmd = $processConfig->executableCommand();
                $cmd[] = 'completion:file-index:refresh';

                $process = new Process(
                    command: $cmd,
                    cwd: $processConfig->runtimeCwd(),
                );
                $process->setTimeout(null);
                $process->start();

                $self->runningProcess = $process;
            } catch (\Throwable $e) {
                // Intentional local degradation: background index
                // refresh is best-effort.  Completion continues with
                // the previous index (or empty).
                $logger->debug(
                    'Failed to spawn file mention index refresh: {message}',
                    ['message' => $e->getMessage()],
                );
            }

            return null;
        };
    }
}
