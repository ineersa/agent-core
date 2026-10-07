<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Ineersa\CodingAgent\Utility\AtomicFileWriterException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Session-scoped durable tool batch snapshots (transient recovery state).
 *
 * Parent runs:
 *   .hatfield/sessions/<runId>/runtime/tool-batches/<turnNo>_<stepHash>.json
 *
 * Child agent runs (subagent/fork): parent-scoped artifact tree only — never
 * .hatfield/sessions/<childRunId>/; AgentChildRunDirectory resolves their location.
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
    private const SERIALIZER_CONTEXT = [
        AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP],
        'json_encode_options' => \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
    ];

    public function __construct(
        private readonly ToolBatchRunStoragePathsInterface $storagePaths,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly PreparedTransitionEventStoreInterface $transitions,
    ) {
    }

    /** @return array{string, ToolBatchSnapshotEnvelopeDTO}|null */
    public function nextSnapshot(string $runId, string $afterFilename): ?array
    {
        return $this->withRunLock($runId, fn (): ?array => $this->nextSnapshotWithoutLock($runId, $afterFilename));
    }

    public function load(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO
    {
        return $this->withRunLock($runId, function () use ($runId, $turnNo, $stepId): ?ToolBatchStateDTO {
            return $this->withSnapshotLock($runId, $turnNo, $stepId, function () use ($runId, $turnNo, $stepId): ?ToolBatchStateDTO {
                $path = $this->snapshotPath($runId, $turnNo, $stepId);
                if (!is_readable($path)) {
                    $directory = \dirname($path);
                    if (is_dir($directory)) {
                        foreach (new \DirectoryIterator($directory) as $file) {
                            if ($file->isFile() && str_starts_with($file->getFilename(), basename($path).'.tmp.')) {
                                throw new SessionToolBatchStoreException('Tool batch predecessor is unavailable; unpublished evidence is retained.');
                            }
                        }
                    }

                    return null;
                }

                return $this->readSnapshotEnvelope($path, $runId, $turnNo, $stepId)->batchState;
            });
        });
    }

    public function save(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batchState): void
    {
        $this->withRunLock($runId, function () use ($runId, $turnNo, $stepId, $batchState): void {
            $this->withSnapshotLock($runId, $turnNo, $stepId, function () use ($runId, $turnNo, $stepId, $batchState): void {
                $this->writeSnapshot(
                    $runId,
                    $turnNo,
                    $stepId,
                    new ToolBatchSnapshotEnvelopeDTO($runId, $turnNo, $stepId, $batchState),
                );
            });
        });
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        $this->withRunLock($runId, function () use ($runId, $turnNo, $stepId): void {
            $this->withSnapshotLock($runId, $turnNo, $stepId, function () use ($runId, $turnNo, $stepId): void {
                $path = $this->snapshotPath($runId, $turnNo, $stepId);
                if (is_file($path)) {
                    if ($this->retainSchedulingEvidence($this->readSnapshotEnvelope($path, $runId, $turnNo, $stepId))) {
                        return;
                    }
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
                if (str_ends_with($name, '.json')) {
                    $envelope = $this->readSnapshotEnvelope($file->getPathname(), $runId, null, null);
                    if ($name !== $this->filenamePrefix($envelope->turnNo, $envelope->stepId).'.json') {
                        throw new SessionToolBatchStoreException('Tool batch cleanup filename identity mismatch.');
                    }
                    if ($this->retainSchedulingEvidence($envelope)) {
                        continue;
                    }
                    $this->unlinkOrThrow($file->getPathname(), $runId, null, null);
                }
            }
        });
    }

    public function reclaimDisposedPayloads(string $runId, string $afterFilename): string
    {
        return $this->withRunLock($runId, function () use ($runId, $afterFilename): string {
            $snapshot = $this->nextSnapshotWithoutLock($runId, $afterFilename);
            if (null === $snapshot) {
                return '';
            }
            [$filename, $envelope] = $snapshot;

            return $this->withSnapshotLock($runId, $envelope->turnNo, $envelope->stepId, function () use ($runId, $filename, $envelope): string {
                // Disposition alone is insufficient while owner coordination remains unfinished.
                $this->transitions->assertTransitionReady($runId);
                $path = $this->snapshotPath($runId, $envelope->turnNo, $envelope->stepId);
                if (!is_file($path)) {
                    return $filename;
                }
                $current = $this->readSnapshotEnvelope($path, $runId, $envelope->turnNo, $envelope->stepId);
                if ($this->retainSchedulingEvidence($current)) {
                    return $filename;
                }
                // Wholly finished scheduling snapshots can be deleted; scalar
                // invocation fences now live in the SQL ledger.
                $this->unlinkOrThrow($path, $runId, $current->turnNo, $current->stepId);

                return $filename;
            });
        });
    }

    public function hasUnresolvedExecution(string $runId, ?string $toolCallId = null): bool
    {
        return $this->withRunLock($runId, function () use ($runId, $toolCallId): bool {
            $directory = $this->batchesDir($runId);
            if (!is_dir($directory)) {
                return false;
            }
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) {
                    continue;
                }
                $envelope = $this->readSnapshotEnvelope($file->getPathname(), $runId, null, null);
                if ((null === $toolCallId || isset($envelope->batchState->calls[$toolCallId])) && $this->retainSchedulingEvidence($envelope)) {
                    return true;
                }
            }

            return false;
        });
    }

    public function mutate(string $runId, int $turnNo, string $stepId, callable $callback): mixed
    {
        return $this->withRunLock($runId, function () use ($runId, $turnNo, $stepId, $callback): mixed {
            return $this->withSnapshotLock($runId, $turnNo, $stepId, function () use ($runId, $turnNo, $stepId, $callback): mixed {
                $path = $this->snapshotPath($runId, $turnNo, $stepId);
                $envelope = is_readable($path) ? $this->readSnapshotEnvelope($path, $runId, $turnNo, $stepId) : null;
                $current = null !== $envelope ? $envelope->batchState : null;

                $outcome = $callback($current);
                if (!$outcome instanceof ToolBatchStoreMutation) {
                    throw new \LogicException('Tool batch store mutate callback must return ToolBatchStoreMutation.');
                }

                if (null !== $outcome->nextState) {
                    $this->writeSnapshot(
                        $runId,
                        $turnNo,
                        $stepId,
                        new ToolBatchSnapshotEnvelopeDTO($runId, $turnNo, $stepId, $outcome->nextState),
                    );
                }

                return $outcome->returnValue;
            });
        });
    }

    private function readSnapshotEnvelope(string $path, string $expectedRunId, ?int $expectedTurnNo, ?string $expectedStepId): ToolBatchSnapshotEnvelopeDTO
    {
        $json = file_get_contents($path);
        if (false === $json || '' === trim($json)) {
            throw new SessionToolBatchStoreException('Tool batch snapshot is empty or unreadable.');
        }

        try {
            $envelope = $this->serializer->deserialize(
                $json,
                ToolBatchSnapshotEnvelopeDTO::class,
                'json',
                self::SERIALIZER_CONTEXT,
            );
        } catch (SerializerExceptionInterface|\TypeError|\ValueError $exception) {
            throw new SessionToolBatchStoreException('Tool batch snapshot is invalid.', $exception);
        }

        if (!$envelope instanceof ToolBatchSnapshotEnvelopeDTO) {
            throw new SessionToolBatchStoreException(\sprintf('Tool batch snapshot is invalid: expected %s.', ToolBatchSnapshotEnvelopeDTO::class));
        }

        $violations = $this->validator->validate($envelope);
        if ($violations->count() > 0) {
            throw new SessionToolBatchStoreException('Tool batch snapshot is invalid.', new ValidationFailedException($envelope, $violations));
        }

        if ($envelope->runId !== $expectedRunId || (null !== $expectedTurnNo && $envelope->turnNo !== $expectedTurnNo) || (null !== $expectedStepId && $envelope->stepId !== $expectedStepId)) {
            throw new SessionToolBatchStoreException('Tool batch snapshot identity mismatch.');
        }

        return $envelope;
    }

    private function retainSchedulingEvidence(ToolBatchSnapshotEnvelopeDTO $envelope): bool
    {
        $batch = $envelope->batchState;
        if (!$batch->finalized || [] !== $batch->awaitingHumanInput || [] !== $batch->pendingQueue || [] !== $batch->inFlight) {
            $this->logger->info('tool_batch.scheduling_evidence_retained', [
                'component' => 'session_tool_batch_store', 'event_type' => 'scheduling_evidence_retained',
                'run_id' => $envelope->runId, 'turn_no' => $envelope->turnNo, 'step_id' => $envelope->stepId,
                'finalized' => $batch->finalized,
                'awaiting_human_input' => \count($batch->awaitingHumanInput),
                'pending_queue' => \count($batch->pendingQueue),
                'in_flight' => \count($batch->inFlight),
            ]);

            return true;
        }

        return false;
    }

    /** @return array{string, ToolBatchSnapshotEnvelopeDTO}|null */
    private function nextSnapshotWithoutLock(string $runId, string $afterFilename): ?array
    {
        $dir = $this->batchesDir($runId);
        if (!is_dir($dir)) {
            return null;
        }
        // Retain only the next filename, not a directory-sized list or decoded batches.
        $next = null;
        foreach (new \DirectoryIterator($dir) as $file) {
            $name = $file->getFilename();
            if ($file->isFile() && 1 === preg_match('/^[0-9]+_[a-f0-9]{64}\.json$/D', $name)
                && strcmp($name, $afterFilename) > 0 && (null === $next || strcmp($name, $next) < 0)) {
                $next = $name;
            }
        }
        if (null === $next) {
            return null;
        }
        $envelope = $this->readSnapshotEnvelope($dir.'/'.$next, $runId, null, null);
        if ($this->snapshotPath($runId, $envelope->turnNo, $envelope->stepId) !== $dir.'/'.$next) {
            throw new \RuntimeException('Tool batch snapshot filename differs from its invocation identity.');
        }

        return [$next, $envelope];
    }

    private function writeSnapshot(string $runId, int $turnNo, string $stepId, ToolBatchSnapshotEnvelopeDTO $envelope): void
    {
        $this->sanitizeRunId($runId);
        $dir = $this->batchesDir($runId);
        $this->ensureDirectory($dir);

        $path = $this->snapshotPath($runId, $turnNo, $stepId);

        try {
            $json = $this->serializer->serialize($envelope, 'json', self::SERIALIZER_CONTEXT);
        } catch (SerializerExceptionInterface $exception) {
            throw new SessionToolBatchStoreException('Tool batch snapshot write failed.', $exception);
        }

        try {
            AtomicFileWriter::write($path, $json);
        } catch (AtomicFileWriterException $exception) {
            throw new SessionToolBatchStoreException('rename' === $exception->stage ? 'Failed to atomic-rename tool batch snapshot.' : 'Failed to write tool batch snapshot temp file.', $exception);
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
            throw new SessionToolBatchStoreException('Failed to delete tool batch snapshot file.');
        }
    }

    private function sanitizeRunId(string $runId): void
    {
        if ('' === $runId || \strlen($runId) !== strcspn($runId, "/\\\0") || str_contains($runId, '..')) {
            throw new SessionToolBatchStoreException(\sprintf('Invalid tool batch run ID: "%s".', $runId));
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (file_exists($dir)) {
            throw new SessionToolBatchStoreException(\sprintf('Cannot create tool batch directory: non-directory at "%s".', $dir));
        }

        if (!mkdir($dir, recursive: true) && !is_dir($dir)) {
            throw new SessionToolBatchStoreException(\sprintf('Failed to create tool batch directory "%s".', $dir));
        }
    }
}
