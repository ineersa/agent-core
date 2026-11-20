<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Entity\CodeModeCallRepository;
use Ineersa\CodingAgent\Entity\CodeModeReportedReturnRepository;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Symfony\Component\Filesystem\Filesystem;

/** Owns native launch evidence independently of any tool consumer. */
final readonly class CodeModeProcessOwner
{
    public function __construct(private ProcessLifecycle $lifecycle, private CodeModeReportedReturnRepository $returns, private CodeModeCallRepository $calls, private ToolPayloadStoreInterface $payloads, private Filesystem $filesystem = new Filesystem())
    {
    }

    /** @param array{invocationId: string, callId: string, rootId: string, timeout: ?int}|null $parent */
    public function launch(string $runId, string $invocationId, string $workspace, string $command, int $startedAtNs, bool $controllerSettles = false, ?array $parent = null, ?\Closure $admit = null): CodeModeOwnedProcess
    {
        $prefix = $this->prefix($runId).hash('sha256', $invocationId);

        return $this->lifecycle->withSidecarLock($prefix.'.status', function () use ($prefix, $workspace, $command, $startedAtNs, $invocationId, $controllerSettles, $parent, $admit): CodeModeOwnedProcess {
            if (file_exists($prefix.'.workspace') || is_link($prefix.'.workspace') || file_exists($prefix.'.pid')
                || file_exists($prefix.'.status') || file_exists($prefix.'.log') || !$this->lifecycle->ownsSidecarPaths($prefix.'.log', $prefix.'.status')) {
                throw new ToolCallException('Code-mode invocation already has launch evidence; refusing to start it again.', retryable: false);
            }
            // Publish ownership before starting the native wrapper. If the consumer
            // dies before recording readiness, session cleanup can still find it.
            $this->filesystem->dumpFile($prefix.'.workspace', json_encode(['workspace' => $workspace, 'startedAtNs' => $startedAtNs, 'invocationId' => $invocationId, 'controllerSettles' => $controllerSettles, 'parent' => $parent], \JSON_THROW_ON_ERROR));
            $admit?->__invoke();
            $launch = $this->lifecycle->launchProcess($command, $prefix.'.pid', $prefix.'.log', $prefix.'.status');

            return new CodeModeOwnedProcess($this->lifecycle, $prefix.'.status', $launch['pid']);
        });
    }

    /** @return array{workspace: string, startedAtNs: int, controllerSettles: bool, parent: ?array{invocationId: string, callId: string, rootId: string, timeout: ?int}}|null */
    public function invocation(string $runId, string $invocationId): ?array
    {
        $marker = $this->prefix($runId).hash('sha256', $invocationId).'.workspace';
        if (!is_file($marker) || is_link($marker)) {
            return null;
        }
        $record = json_decode($this->filesystem->readFile($marker), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($record) || !\is_string($record['workspace'] ?? null) || !\is_int($record['startedAtNs'] ?? null)
            || !is_dir($record['workspace']) || is_link($record['workspace'])) {
            return null;
        }

        return ['workspace' => $record['workspace'], 'startedAtNs' => $record['startedAtNs'], 'controllerSettles' => true === ($record['controllerSettles'] ?? null), 'parent' => $record['parent'] ?? null];
    }

    /** @return iterable<string> */
    public function controllerInvocationIds(string $runId): iterable
    {
        $prefix = $this->prefix($runId);
        $markers = glob($prefix.'*.workspace', \GLOB_NOSORT);
        foreach (false === $markers ? [] : $markers as $marker) {
            if (is_link($marker) || !is_file($marker)) {
                continue;
            }
            $record = json_decode($this->filesystem->readFile($marker), true);
            $id = $record['invocationId'] ?? null;
            if (true === ($record['controllerSettles'] ?? null) && \is_string($id) && $marker === $prefix.hash('sha256', $id).'.workspace') {
                yield $id;
            }
        }
    }

    /** The inherited foreground budget is evaluated against each original launch, never snapshotted at a nested call. */
    public function deadline(string $runId, string $id, bool $backgrounded, ?int $rootTimeout, ?int $launcherTimeout): ?int
    {
        $deadline = null;
        $visited = [];
        do {
            $process = $this->invocation($runId, $id);
            if (null === $process || isset($visited[$id])) {
                return 0;
            }
            $visited[$id] = true;
            $parent = $process['parent'];
            $limit = null === $parent ? $rootTimeout : $parent['timeout'];
            if (!$backgrounded) {
                $limit ??= \Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO::DEFAULT_TIMEOUT_SECONDS;
                if (null === $parent && null !== $launcherTimeout && $launcherTimeout > 0) {
                    $limit = min($limit, $launcherTimeout);
                }
            }
            if (null !== $limit) {
                $start = $process['startedAtNs'];
                $candidate = $limit > intdiv(\PHP_INT_MAX - $start, 1_000_000_000) ? \PHP_INT_MAX : $start + $limit * 1_000_000_000;
                $deadline = min($deadline ?? \PHP_INT_MAX, $candidate);
            }
            $id = $parent['invocationId'] ?? null;
        } while (null !== $id);

        return $deadline;
    }

    public function hasExited(string $runId, string $id): bool
    {
        $status = $this->prefix($runId).hash('sha256', $id).'.status';
        $pid = $this->lifecycle->readPidFile($status);

        return null !== $this->lifecycle->readStatusFile($status) || (null !== $pid && !$this->lifecycle->isAlive($pid));
    }

    /** @return array{ok: bool, result?: mixed, error?: string}|null */
    public function observeTerminal(string $runId, string $id, ?int $deadline, ?string $interruption): ?array
    {
        $status = $this->prefix($runId).hash('sha256', $id).'.status';

        return $this->lifecycle->withSidecarLock($status, function () use ($runId, $id, $deadline, $interruption, $status): ?array {
            $invocation = $this->invocation($runId, $id);
            if (null === $invocation || !$invocation['controllerSettles'] || !$this->lifecycle->ownsSidecarPaths(substr($status, 0, -7).'.log', $status)) {
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
            if (null === $exit && null === $pid) {
                return null;
            }
            if (null !== $interruption || ($expired && (null === $exit || $exit < 0 || $exit >= 128))) {
                return ['ok' => false, 'error' => null !== $interruption
                    ? 'Code-mode invocation interrupted: '.$interruption.'.' : 'Code-mode invocation timed out.'];
            }

            $reference = $this->returns->reply($id);
            if (null === $reference) {
                return ['ok' => false, 'error' => 'Code-mode subprocess exited without returning a value.'];
            }
            $result = $this->payloads->read($reference);

            return $result instanceof ToolCallResult && \is_array($result->result)
                ? $result->result : ['ok' => false, 'error' => 'Invalid code-mode return payload.'];
        });
    }

    public function cleanup(string $runId): void
    {
        $markers = glob($this->prefix($runId).'*.workspace', \GLOB_NOSORT);
        foreach (false === $markers ? [] : $markers as $marker) {
            $prefix = substr($marker, 0, -10);
            $this->lifecycle->withSidecarLock($prefix.'.status', function () use ($prefix, $marker): void {
                if (!is_file($marker) || is_link($marker) || !$this->lifecycle->ownsSidecarPaths($prefix.'.log', $prefix.'.status')) {
                    return;
                }
                $statusPath = $prefix.'.status';
                $pid = $this->lifecycle->readPidFile($statusPath);
                $process = null === $pid ? null : new CodeModeOwnedProcess($this->lifecycle, $statusPath, $pid);
                if ($process?->isRunning()) {
                    $process->stopLocked();
                    // Do not erase the only native evidence before exit is observable.
                    if ($this->lifecycle->isAlive($pid) && null === $this->lifecycle->readStatusFile($statusPath)) {
                        return;
                    }
                }
                $record = json_decode($this->filesystem->readFile($marker), true);
                $workspace = \is_string($record) ? $record : ($record['workspace'] ?? null);
                // An interrupted older socket launch may have left a plain-path marker.
                $workspace ??= $this->filesystem->readFile($marker);
                $tempRoot = realpath(sys_get_temp_dir());
                if (false !== $workspace && false !== $tempRoot && !is_link($workspace)
                    && realpath(\dirname($workspace)) === $tempRoot && 1 === preg_match('/^hcm[A-Za-z0-9]+$/D', basename($workspace))) {
                    $this->filesystem->remove($workspace);
                }
                if ($this->lifecycle->deleteLockedRecordSidecars($prefix.'.log', $statusPath)) {
                    if (\is_array($record) && \is_string($record['invocationId'] ?? null)) {
                        $this->returns->remove($record['invocationId']);
                        $this->calls->remove($record['invocationId']);
                    }
                    $this->filesystem->remove($marker);
                }
            });
        }
    }

    private function prefix(string $runId): string
    {
        return $this->lifecycle->ensureStorageDir().'/code-mode-'.hash('sha256', $runId).'-';
    }
}
