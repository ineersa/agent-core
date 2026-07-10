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
 * Persists JSON under:
 *   .hatfield/sessions/<runId>/runtime/tool-batches/<turnNo>_<stepHash>.json
 *
 * INVARIANT (lock ordering): mutate() must run while the per-run pipeline lock is
 * held (RunLockManager via RunMessageProcessor). This store uses a per-snapshot
 * Symfony lock only to serialize file read-modify-write; it must not acquire the
 * run lock itself.
 */
final class SessionToolBatchStore implements ToolBatchStoreInterface
{
    private const string RUNTIME_SUBDIR = 'runtime/tool-batches';

    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $runId, int $turnNo, string $stepId): ?array
    {
        $path = $this->snapshotPath($runId, $turnNo, $stepId);
        if (!is_readable($path)) {
            $this->reconcileOrphanTempFiles($runId, $turnNo, $stepId);

            return null;
        }

        return $this->readSnapshotFile($path);
    }

    /**
     * @param array<string, mixed> $batchState
     */
    public function save(string $runId, int $turnNo, string $stepId, array $batchState): void
    {
        $this->writeSnapshot($runId, $turnNo, $stepId, $this->envelope($runId, $turnNo, $stepId, $batchState));
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        $path = $this->snapshotPath($runId, $turnNo, $stepId);
        if (is_file($path)) {
            @unlink($path);
        }

        $dir = \dirname($path);
        $prefix = $this->filenamePrefix($turnNo, $stepId);
        $tempFiles = glob($dir.'/'.$prefix.'*.json.tmp.*');
        if (false === $tempFiles) {
            $tempFiles = [];
        }
        foreach ($tempFiles as $tempFile) {
            @unlink($tempFile);
        }
    }

    /**
     * Remove all tool-batch snapshot files for a run (post-terminal cleanup).
     */
    public function deleteAllForRun(string $runId): void
    {
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
                @unlink($file->getPathname());
            }
        }
    }

    public function mutate(string $runId, int $turnNo, string $stepId, callable $callback): mixed
    {
        $lock = $this->lockFactory->createLock($this->lockKey($runId, $turnNo, $stepId), ttl: 30.0, autoRelease: true);
        $lock->acquire(true);

        try {
            $path = $this->snapshotPath($runId, $turnNo, $stepId);
            $current = is_readable($path) ? $this->readSnapshotFile($path) : null;

            $outcome = $callback($current);
            if (!$outcome instanceof ToolBatchStoreMutation) {
                throw new \LogicException('Tool batch store mutate callback must return ToolBatchStoreMutation.');
            }

            if (null !== $outcome->nextSerializedState) {
                $this->writeSnapshot($runId, $turnNo, $stepId, $this->envelope($runId, $turnNo, $stepId, $outcome->nextSerializedState));
            }

            return $outcome->returnValue;
        } finally {
            if ($lock->isAcquired()) {
                $lock->release();
            }
        }
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
            @unlink($tempFile);
        }
    }

    /**
     * @param array<string, mixed> $batchState
     *
     * @return array<string, mixed>
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
     * @return array<string, mixed>|null
     */
    private function readSnapshotFile(string $path): ?array
    {
        $json = file_get_contents($path);
        if (false === $json || '' === trim($json)) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->warning('tool_batch.snapshot_corrupt', [
                'path' => $path,
                'component' => 'session_tool_batch_store',
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (!\is_array($decoded) || !\is_array($decoded['batch_state'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $batch */
        $batch = $decoded['batch_state'];

        return $batch;
    }

    /**
     * @param array<string, mixed> $envelope
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
            file_put_contents($tempPath, $json, \LOCK_EX);

            if (!rename($tempPath, $path)) {
                @unlink($tempPath);
                throw new \RuntimeException(\sprintf('Failed to atomic-rename tool batch snapshot for run "%s".', $runId));
            }
        } catch (\Throwable $throwable) {
            @unlink($tempPath);
            throw $throwable;
        }
    }

    private function batchesDir(string $runId): string
    {
        $this->sanitizeRunId($runId);

        return $this->hatfieldSessionStore->resolveSessionsBasePath().'/'.$runId.'/'.self::RUNTIME_SUBDIR;
    }

    private function snapshotPath(string $runId, int $turnNo, string $stepId): string
    {
        return $this->batchesDir($runId).'/'.$this->filenamePrefix($turnNo, $stepId).'.json';
    }

    private function filenamePrefix(int $turnNo, string $stepId): string
    {
        return \sprintf('%d_%s', $turnNo, hash('sha256', $stepId));
    }

    private function lockKey(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('hatfield.session.%s.tool-batch.%d.%s', $runId, $turnNo, hash('sha256', $stepId));
    }

    private function sanitizeRunId(string $runId): void
    {
        if ('' === $runId || \strlen($runId) !== strcspn($runId, "/\\\0") || str_contains($runId, '..')) {
            throw new \RuntimeException(\sprintf('Invalid tool batch run ID: "%s".', $runId));
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (file_exists($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create tool batch directory: non-directory at "%s".', $dir));
        }

        mkdir($dir, 0o777, true);
    }
}
