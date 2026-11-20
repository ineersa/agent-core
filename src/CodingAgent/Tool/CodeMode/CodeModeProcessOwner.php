<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Symfony\Component\Filesystem\Filesystem;

/** Owns native launch evidence independently of any tool consumer. */
final readonly class CodeModeProcessOwner
{
    public function __construct(private ProcessLifecycle $lifecycle, private Filesystem $filesystem = new Filesystem())
    {
    }

    /** @param array{invocationId: string, callId: string, rootId: string, limit: int}|null $parent */
    public function launch(string $runId, string $invocationId, string $workspace, string $command, int $startedAtNs, bool $controllerSettles = false, ?array $parent = null): CodeModeOwnedProcess
    {
        $prefix = $this->prefix($runId).hash('sha256', $invocationId);

        return $this->lifecycle->withSidecarLock($prefix.'.status', function () use ($prefix, $workspace, $command, $startedAtNs, $invocationId, $controllerSettles, $parent): CodeModeOwnedProcess {
            if (file_exists($prefix.'.workspace') || is_link($prefix.'.workspace') || file_exists($prefix.'.pid')
                || file_exists($prefix.'.status') || file_exists($prefix.'.log') || !$this->lifecycle->ownsSidecarPaths($prefix.'.log', $prefix.'.status')) {
                throw new ToolCallException('Code-mode invocation already has launch evidence; refusing to start it again.', retryable: false);
            }
            // Publish ownership before starting the native wrapper. If the consumer
            // dies before recording readiness, session cleanup can still find it.
            $this->filesystem->dumpFile($prefix.'.workspace', json_encode(['workspace' => $workspace, 'startedAtNs' => $startedAtNs, 'invocationId' => $invocationId, 'controllerSettles' => $controllerSettles, 'parent' => $parent], \JSON_THROW_ON_ERROR));
            $launch = $this->lifecycle->launchProcess($command, $prefix.'.pid', $prefix.'.log', $prefix.'.status');

            return new CodeModeOwnedProcess($this->lifecycle, $prefix.'.status', $launch['pid']);
        });
    }

    /** @return array{workspace: string, startedAtNs: int, controllerSettles: bool, parent: ?array{invocationId: string, callId: string, rootId: string, limit: int}}|null */
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

    /** @return array{ok: bool, result?: mixed, error?: string}|null */
    public function observeTerminal(string $runId, string $id, ?int $wallSeconds, ?string $interruption): ?array
    {
        $status = $this->prefix($runId).hash('sha256', $id).'.status';

        return $this->lifecycle->withSidecarLock($status, function () use ($runId, $id, $wallSeconds, $interruption, $status): ?array {
            $invocation = $this->invocation($runId, $id);
            if (null === $invocation || !$invocation['controllerSettles'] || !$this->lifecycle->ownsSidecarPaths(substr($status, 0, -7).'.log', $status)) {
                return null;
            }
            $terminal = $invocation['workspace'].'/terminal.json';
            if (is_file($terminal) && !is_link($terminal)) {
                $frame = json_decode($this->filesystem->readFile($terminal), true, flags: \JSON_THROW_ON_ERROR);
                if (!\is_array($frame) || !\is_bool($frame['ok'] ?? null)) {
                    throw new \RuntimeException('Invalid retained code-mode terminal result.');
                }

                return true === $frame['ok'] ? ['ok' => true, 'result' => CodeModeValueCodec::assertEncodable($frame['result'] ?? null, 'Code-mode terminal result')]
                    : ['ok' => false, 'error' => \is_string($frame['error'] ?? null) ? $frame['error'] : 'Code-mode failed.'];
            }
            $pid = $this->lifecycle->readPidFile($status);
            $exit = $this->lifecycle->readStatusFile($status);
            $running = null !== $pid && null === $exit && $this->lifecycle->isAlive($pid);
            $expired = null !== $wallSeconds && intdiv(hrtime(true) - $invocation['startedAtNs'], 1_000_000_000) >= $wallSeconds;
            if (null === $interruption && !$expired && (null === $exit && (null === $pid || $running))) {
                return null;
            }
            $error = null !== $interruption ? 'Code-mode invocation interrupted: '.$interruption.'.'
                : ($expired ? 'Code-mode invocation timed out.' : 'Code-mode subprocess exited without returning a value.');
            $frame = ['ok' => false, 'error' => $error];
            if ($running) {
                $this->lifecycle->stopProcess($pid, $pid, $status);
            }
            // Materialize the decision before publication, including synthetic failures.
            $this->filesystem->dumpFile($terminal, json_encode($frame, \JSON_THROW_ON_ERROR));

            return $frame;
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
