<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Symfony\Component\Filesystem\Filesystem;

/** Owns native launch evidence independently of any tool consumer. */
final readonly class CodeModeProcessOwner
{
    public function __construct(private ProcessLifecycle $lifecycle, private BackgroundProcessRepository $records, private ProcessStore $store, private Filesystem $filesystem = new Filesystem())
    {
    }

    /** @param array{turn: int, timeout: ?int, callerTimeout?: ?int}|null $standalone */
    public function launch(string $runId, string $invocationId, string $workspace, string $command, int $startedAtNs, ?\Closure $admit = null, ?array $standalone = null): void
    {
        $prefix = $this->prefix($runId).hash('sha256', $invocationId);

        $this->lifecycle->withSidecarLock($prefix.'.status', function () use ($runId, $prefix, $workspace, $command, $startedAtNs, $invocationId, $admit, $standalone): void {
            if (null !== $this->invocation($runId, $invocationId) || file_exists($prefix.'.pid')
                || file_exists($prefix.'.status') || file_exists($prefix.'.log') || !$this->lifecycle->ownsSidecarPaths($prefix.'.log', $prefix.'.status')) {
                throw new ToolCallException('Code-mode invocation already has launch evidence; refusing to start it again.', retryable: false);
            }
            // A committed reservation is discoverable even if the worker dies
            // before ProcessLifecycle has a PID. Hold the native lock through launch.
            $this->store->insertRecord([
                'session_id' => $runId, 'command' => 'code_mode', 'log_path' => $prefix.'.log', 'status_path' => $prefix.'.status',
                'code_mode_id' => $invocationId, 'code_mode_launch' => json_encode(['workspace' => $workspace, 'startedAtNs' => $startedAtNs, 'standalone' => $standalone], \JSON_THROW_ON_ERROR),
            ]);
            $this->filesystem->mkdir($workspace.'/replies', 0700);
            $admit?->__invoke();
            $this->lifecycle->launchProcess($command, $prefix.'.pid', $prefix.'.log', $prefix.'.status');
        });
    }

    /** @return array{workspace: string, startedAtNs: int, fenced: bool, standalone?: array{turn: int, timeout: ?int, callerTimeout?: ?int}}|null */
    public function invocation(string $runId, string $invocationId): ?array
    {
        return $this->records->codeModeLaunch($runId, $invocationId);
    }

    /** @return list<string> */
    public function pendingControllerInvocationIds(string $runId): array
    {
        return $this->records->pendingCodeModeIds($runId);
    }

    /** @return list<string> */
    public function controllerInvocationIds(string $runId): array
    {
        return $this->records->codeModeIds($runId);
    }

    /** The inherited foreground budget is evaluated against each original launch, never snapshotted at a nested call. */
    public function deadline(string $runId, string $id, bool $backgrounded, ?int $rootTimeout, ?int $launcherTimeout): ?int
    {
        $process = $this->invocation($runId, $id);
        if (null === $process) {
            return 0;
        }
        $limit = $rootTimeout;
        if (!$backgrounded) {
            $limit ??= \Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO::DEFAULT_TIMEOUT_SECONDS;
            if (null !== $launcherTimeout && $launcherTimeout > 0) {
                $limit = min($limit, $launcherTimeout);
            }
        }
        $start = $process['startedAtNs'];

        return null === $limit ? null : ($limit > intdiv(\PHP_INT_MAX - $start, 1_000_000_000) ? \PHP_INT_MAX : $start + $limit * 1_000_000_000);
    }

    public function hasExited(string $runId, string $id): bool
    {
        $status = $this->prefix($runId).hash('sha256', $id).'.status';
        $pid = $this->lifecycle->readPidFile($status);

        return null !== $this->lifecycle->readStatusFile($status) || (null !== $pid && !$this->lifecycle->isAlive($pid));
    }

    /** Serialize body creation against retirement, never hold the lock while sending to the broker. */
    public function withReplyWorkspace(string $runId, string $id, callable $publish): mixed
    {
        return $this->lifecycle->withSidecarLock($this->prefix($runId).hash('sha256', $id).'.status', function () use ($runId, $id, $publish): mixed {
            $record = $this->invocation($runId, $id);
            if (null === $record || $record['fenced'] || is_link($record['workspace']) || !is_dir($record['workspace'].'/replies')) {
                return null;
            }

            return $publish($record['workspace']);
        });
    }

    public function retireReplies(string $runId, string $id): void
    {
        $this->withReplyWorkspace($runId, $id, fn (string $workspace) => $this->removeReplyDirectory($workspace));
    }

    /** @return array{ok: bool, result?: mixed, error?: string}|null */
    public function observeTerminal(string $runId, string $id, ?int $deadline, ?string $interruption, callable $report): ?array
    {
        $status = $this->prefix($runId).hash('sha256', $id).'.status';

        return $this->lifecycle->withSidecarLock($status, function () use ($runId, $id, $deadline, $interruption, $status, $report): ?array {
            $invocation = $this->invocation($runId, $id);
            if (null === $invocation || !$this->lifecycle->ownsSidecarPaths(substr($status, 0, -7).'.log', $status)) {
                return null;
            }
            $pid = $this->lifecycle->readPidFile($status);
            $exit = $this->lifecycle->readStatusFile($status);
            $running = null !== $pid && (null === $exit || -1 === $exit) && $this->lifecycle->isAlive($pid);
            $expired = null !== $deadline && hrtime(true) >= $deadline;
            if ($running) {
                if (null === $exit && (null !== $interruption || $expired)) {
                    $this->lifecycle->stopProcess($pid, $pid, $status);
                }

                return null;
            }
            $this->removeReplyDirectory($invocation['workspace']);
            if ($invocation['fenced'] || (null === $exit && null === $pid)) {
                $this->records->fenceCodeModeLaunch($runId, $id);

                return ['ok' => false, 'error' => 'Code-mode launch outcome is unknown; refusing to start it again.'];
            }
            if (null !== $interruption || ($expired && (null === $exit || $exit < 0 || $exit >= 128))) {
                return ['ok' => false, 'error' => null !== $interruption
                    ? 'Code-mode invocation interrupted: '.$interruption.'.' : 'Code-mode invocation timed out.'];
            }

            return $report();
        });
    }

    public function cleanup(string $runId): void
    {
        foreach ($this->controllerInvocationIds($runId) as $id) {
            $prefix = $this->prefix($runId).hash('sha256', $id);
            $this->lifecycle->withSidecarLock($prefix.'.status', function () use ($runId, $id, $prefix): void {
                $record = $this->invocation($runId, $id);
                if (null === $record || !$this->lifecycle->ownsSidecarPaths($prefix.'.log', $prefix.'.status')) {
                    return;
                }
                $this->records->fenceCodeModeLaunch($runId, $id);
                $statusPath = $prefix.'.status';
                $pid = $this->lifecycle->readPidFile($statusPath);
                if (null !== $pid && null === $this->lifecycle->readStatusFile($statusPath) && $this->lifecycle->isAlive($pid)) {
                    $stopped = $this->lifecycle->stopProcess($pid, $pid, $statusPath);
                    if ('KILL' === $stopped->signalSent) {
                        return;
                    }
                }
                $workspace = $record['workspace'];
                $this->removeReplyDirectory($workspace);
                $tempRoot = realpath(sys_get_temp_dir());
                if (false !== $tempRoot && !is_link($workspace)
                    && realpath(\dirname($workspace)) === $tempRoot && 1 === preg_match('/^hcm[A-Za-z0-9]+$/D', basename($workspace))) {
                    $this->filesystem->remove($workspace);
                }
                if ($this->lifecycle->deleteLockedRecordSidecars($prefix.'.log', $statusPath)) {
                    $this->records->removeCodeModeLaunch($runId, $id);
                }
            });
        }
    }

    private function removeReplyDirectory(string $workspace): void
    {
        if (!is_link($workspace)) {
            $this->filesystem->remove($workspace.'/replies');
        }
    }

    private function prefix(string $runId): string
    {
        return $this->lifecycle->ensureStorageDir().'/code-mode-'.hash('sha256', $runId).'-';
    }
}
