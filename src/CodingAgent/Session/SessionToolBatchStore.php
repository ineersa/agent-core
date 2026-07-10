<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Session-scoped durable tool batch snapshots (transient recovery state).
 *
 * Parent runs:
 *   .hatfield/sessions/<runId>/runtime/tool-batches/<turnNo>_<stepHash>.json
 *
 * Child agent runs (subagent/fork): parent-scoped artifact tree only — never
 * .hatfield/sessions/<childRunId>/ (see AgentChildRunStore).
 *
 * Lock ordering (must hold):
 *   1. RunLockManager per-run lock (RunMessageProcessor)
 *   2. SessionToolBatchStore run-scoped tool-batch lock (this class)
 *   3. Per-batch snapshot lock inside mutate/save/delete
 *
 * Never acquire the run lock from this store.
 */
final class SessionToolBatchStore implements ToolBatchStoreInterface
{
    public function __construct(
        private readonly ToolBatchRunStoragePathsInterface $storagePaths,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $runId, int $turnNo, string $stepId): ?array
    {
        return $this->withRunLock($runId, function () use ($runId, $turnNo, $stepId): ?array {
            return $this->withSnapshotLock($runId, $turnNo, $stepId, function () use ($runId, $turnNo, $stepId): ?array {
                $path = $this->snapshotPath($runId, $turnNo, $stepId);
                if (!is_readable($path)) {
                    $this->reconcileOrphanTempFiles($runId, $turnNo, $stepId);

                    return null;
                }

                return $this->readSnapshotEnvelope($path, $runId, $turnNo, $stepId)['batch_state'];
            });
        });
    }

    /**
     * @param array<string, mixed> $batchState
     */
    public function save(string $runId, int $turnNo, string $stepId, array $batchState): void
    {
        $this->withRunLock($runId, function () use ($runId, $turnNo, $stepId, $batchState): void {
            $this->withSnapshotLock($runId, $turnNo, $stepId, function () use ($runId, $turnNo, $stepId, $batchState): void {
                $this->writeSnapshot($runId, $turnNo, $stepId, $this->envelope($runId, $turnNo, $stepId, $batchState));
            });
        });
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        $this->withRunLock($runId, function () use ($runId, $turnNo, $stepId): void {
            $this->withSnapshotLock($runId, $turnNo, $stepId, function () use ($runId, $turnNo, $stepId): void {
                $path = $this->snapshotPath($runId, $turnNo, $stepId);
                if (is_file($path)) {
                    $this->unlinkOrThrow($path, $runId, $turnNo, $stepId);
                }

                $dir = \dirname($path);
                $prefix = $this->filenamePrefix($turnNo, $stepId);
                $tempFiles = glob($dir.'/'.$prefix.'*.json.tmp.*');
                if (false === $tempFiles) {
                    $tempFiles = [];
                }
                foreach ($tempFiles as $tempFile) {
                    if (is_file($tempFile)) {
                        $this->unlinkOrThrow($tempFile, $runId, $turnNo, $stepId);
                    }
                }
            });
        });
    }

    public function deleteAllForRun(string $runId): void
    {
        $this->withRunLock($runId, function () use ($runId): void {
            $dir = $this->batchesDir($runId);
            if (!is_dir($dir)) {
                return;
            }

            foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $name = $file->getFilename();
                if (str_ends_with($name, '.json') || str_contains($name, '.json.tmp.')) {
                    $this->unlinkOrThrow($file->getPathname(), $runId, null, null);
                }
            }
        });
    }

    public function mutate(string $runId, int $turnNo, string $stepId, callable $callback): mixed
    {
        return $this->withRunLock($runId, function () use ($runId, $turnNo, $stepId, $callback): mixed {
            return $this->withSnapshotLock($runId, $turnNo, $stepId, function () use ($runId, $turnNo, $stepId, $callback): mixed {
                $path = $this->snapshotPath($runId, $turnNo, $stepId);
                $envelope = is_readable($path) ? $this->readSnapshotEnvelope($path, $runId, $turnNo, $stepId) : null;
                $current = null !== $envelope ? $envelope['batch_state'] : null;

                $outcome = $callback($current);
                if (!$outcome instanceof ToolBatchStoreMutation) {
                    throw new \LogicException('Tool batch store mutate callback must return ToolBatchStoreMutation.');
                }

                if (null !== $outcome->nextSerializedState) {
                    $this->writeSnapshot(
                        $runId,
                        $turnNo,
                        $stepId,
                        $this->envelope($runId, $turnNo, $stepId, $outcome->nextSerializedState),
                    );
                }

                return $outcome->returnValue;
            });
        });
    }

    private function reconcileOrphanTempFiles(string $runId, int $turnNo, string $stepId): void
    {
        $dir = $this->batchesDir($runId);
        if (!is_dir($dir)) {
            return;
        }

        $prefix = $this->filenamePrefix($turnNo, $stepId);
        $tempFiles = glob($dir.'/'.$prefix.'*.json.tmp.*');
        if (false === $tempFiles) {
            $tempFiles = [];
        }
        foreach ($tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                try {
                    $this->unlinkOrThrow($tempFile, $runId, $turnNo, $stepId);
                } catch (SessionToolBatchStoreException $exception) {
                    $this->logger->warning('tool_batch.snapshot_orphan_temp_cleanup_failed', [
                        'run_id' => $runId,
                        'turn_no' => $turnNo,
                        'step_id' => $stepId,
                        'component' => 'session_tool_batch_store',
                        'event_type' => 'orphan_temp_cleanup',
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $batchState
     *
     * @return array{run_id: string, turn_no: int, step_id: string, batch_state: array<string, mixed>}
     */
    private function envelope(string $runId, int $turnNo, string $stepId, array $batchState): array
    {
        return [
            'run_id' => $runId,
            'turn_no' => $turnNo,
            'step_id' => $stepId,
            'batch_state' => $batchState,
        ];
    }

    /**
     * @return array{run_id: string, turn_no: int, step_id: string, batch_state: array<string, mixed>}
     */
    private function readSnapshotEnvelope(string $path, string $expectedRunId, int $expectedTurnNo, string $expectedStepId): array
    {
        $json = file_get_contents($path);
        if (false === $json || '' === trim($json)) {
            throw new SessionToolBatchStoreException('Tool batch snapshot is empty or unreadable.', ['path' => $path, 'component' => 'session_tool_batch_store']);
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SessionToolBatchStoreException('Tool batch snapshot is not valid JSON.', ['path' => $path, 'component' => 'session_tool_batch_store'], $exception);
        }

        if (!\is_array($decoded)) {
            throw new SessionToolBatchStoreException('Tool batch snapshot root must be an object.', ['path' => $path, 'component' => 'session_tool_batch_store']);
        }

        $embeddedRunId = $decoded['run_id'] ?? null;
        $turnNo = $decoded['turn_no'] ?? null;
        $stepId = $decoded['step_id'] ?? null;
        $batchState = $decoded['batch_state'] ?? null;

        if (!\is_string($embeddedRunId) || '' === $embeddedRunId) {
            throw new SessionToolBatchStoreException('Tool batch snapshot missing run_id.', ['path' => $path, 'component' => 'session_tool_batch_store']);
        }

        if (!\is_int($turnNo)) {
            throw new SessionToolBatchStoreException('Tool batch snapshot missing turn_no.', ['path' => $path, 'component' => 'session_tool_batch_store', 'run_id' => $embeddedRunId]);
        }

        if (!\is_string($stepId) || '' === $stepId) {
            throw new SessionToolBatchStoreException('Tool batch snapshot missing step_id.', ['path' => $path, 'component' => 'session_tool_batch_store', 'run_id' => $embeddedRunId]);
        }

        if (!\is_array($batchState)) {
            throw new SessionToolBatchStoreException('Tool batch snapshot missing batch_state.', ['path' => $path, 'component' => 'session_tool_batch_store', 'run_id' => $embeddedRunId]);
        }

        if ($embeddedRunId !== $expectedRunId || $turnNo !== $expectedTurnNo || $stepId !== $expectedStepId) {
            throw new SessionToolBatchStoreException('Tool batch snapshot identity mismatch.', ['path' => $path, 'component' => 'session_tool_batch_store', 'run_id' => $expectedRunId, 'turn_no' => $expectedTurnNo, 'step_id' => $expectedStepId, 'embedded_run_id' => $embeddedRunId, 'embedded_turn_no' => $turnNo, 'embedded_step_id' => $stepId]);
        }

        /* @var array<string, mixed> $batchState */

        return [
            'run_id' => $embeddedRunId,
            'turn_no' => $turnNo,
            'step_id' => $stepId,
            'batch_state' => $batchState,
        ];
    }

    /**
     * @param array{run_id: string, turn_no: int, step_id: string, batch_state: array<string, mixed>} $envelope
     */
    private function writeSnapshot(string $runId, int $turnNo, string $stepId, array $envelope): void
    {
        $this->sanitizeRunId($runId);
        $dir = $this->batchesDir($runId);
        $this->ensureDirectory($dir);

        $path = $this->snapshotPath($runId, $turnNo, $stepId);
        $tempPath = $path.'.tmp.'.bin2hex(random_bytes(8));

        try {
            $json = json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $written = file_put_contents($tempPath, $json, \LOCK_EX);
            if (false === $written || $written !== \strlen($json)) {
                throw new SessionToolBatchStoreException('Failed to write tool batch snapshot temp file.', ['run_id' => $runId, 'turn_no' => $turnNo, 'step_id' => $stepId, 'path' => $tempPath, 'component' => 'session_tool_batch_store']);
            }

            if (!rename($tempPath, $path)) {
                $this->unlinkOrThrow($tempPath, $runId, $turnNo, $stepId);
                throw new SessionToolBatchStoreException('Failed to atomic-rename tool batch snapshot.', ['run_id' => $runId, 'turn_no' => $turnNo, 'step_id' => $stepId, 'path' => $path, 'component' => 'session_tool_batch_store']);
            }
        } catch (SessionToolBatchStoreException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            if (is_file($tempPath)) {
                try {
                    $this->unlinkOrThrow($tempPath, $runId, $turnNo, $stepId);
                } catch (SessionToolBatchStoreException $cleanupException) {
                    $this->logger->warning('tool_batch.snapshot_write_temp_cleanup_failed', [
                        'run_id' => $runId,
                        'turn_no' => $turnNo,
                        'step_id' => $stepId,
                        'component' => 'session_tool_batch_store',
                        'event_type' => 'write_temp_cleanup',
                        'error' => $cleanupException->getMessage(),
                    ]);
                }
            }

            throw new SessionToolBatchStoreException('Tool batch snapshot write failed.', ['run_id' => $runId, 'turn_no' => $turnNo, 'step_id' => $stepId, 'component' => 'session_tool_batch_store'], $throwable);
        }
    }

    private function batchesDir(string $runId): string
    {
        $this->sanitizeRunId($runId);

        return $this->storagePaths->resolveToolBatchesDirectory($runId);
    }

    private function snapshotPath(string $runId, int $turnNo, string $stepId): string
    {
        return $this->batchesDir($runId).'/'.$this->filenamePrefix($turnNo, $stepId).'.json';
    }

    private function filenamePrefix(int $turnNo, string $stepId): string
    {
        return \sprintf('%d_%s', $turnNo, hash('sha256', $stepId));
    }

    private function runLockKey(string $runId): string
    {
        return \sprintf('hatfield.session.%s.tool-batches', $runId);
    }

    private function snapshotLockKey(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('hatfield.session.%s.tool-batch.%d.%s', $runId, $turnNo, hash('sha256', $stepId));
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withRunLock(string $runId, callable $callback): mixed
    {
        $lock = $this->lockFactory->createLock($this->runLockKey($runId), ttl: 30.0, autoRelease: true);
        $lock->acquire(true);

        try {
            return $callback();
        } finally {
            if ($lock->isAcquired()) {
                $lock->release();
            }
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withSnapshotLock(string $runId, int $turnNo, string $stepId, callable $callback): mixed
    {
        $lock = $this->lockFactory->createLock($this->snapshotLockKey($runId, $turnNo, $stepId), ttl: 30.0, autoRelease: true);
        $lock->acquire(true);

        try {
            return $callback();
        } finally {
            if ($lock->isAcquired()) {
                $lock->release();
            }
        }
    }

    private function unlinkOrThrow(string $path, string $runId, ?int $turnNo, ?string $stepId): void
    {
        if (!unlink($path)) {
            throw new SessionToolBatchStoreException('Failed to delete tool batch snapshot file.', ['run_id' => $runId, 'turn_no' => $turnNo, 'step_id' => $stepId, 'path' => $path, 'component' => 'session_tool_batch_store']);
        }
    }

    private function sanitizeRunId(string $runId): void
    {
        if ('' === $runId || \strlen($runId) !== strcspn($runId, "/\\\0") || str_contains($runId, '..')) {
            throw new SessionToolBatchStoreException(\sprintf('Invalid tool batch run ID: "%s".', $runId), ['run_id' => $runId, 'component' => 'session_tool_batch_store']);
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (file_exists($dir)) {
            throw new SessionToolBatchStoreException(\sprintf('Cannot create tool batch directory: non-directory at "%s".', $dir), ['path' => $dir, 'component' => 'session_tool_batch_store']);
        }

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new SessionToolBatchStoreException(\sprintf('Failed to create tool batch directory "%s".', $dir), ['path' => $dir, 'component' => 'session_tool_batch_store']);
        }
    }
}
