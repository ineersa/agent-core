<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Config\FileRewindConfig;
use Psr\Log\LoggerInterface;

/**
 * Coordinates hidden-git capture, restore, undo metadata, and ledger projection inputs.
 */
class FileRewindCheckpointService
{
    private const string BACKEND_VERSION = 'hidden-git-v1';

    /** @var array<string, string> runId => last tree sha */
    private array $lastTreeShaByRunProject = [];

    private ?bool $gitOperationalCache = null;

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly RunLockManager $lockManager,
        private readonly HiddenGitSnapshotBackend $backend,
        private readonly GitProcessRunner $gitRunner,
        private readonly RewindStoragePaths $paths,
        private readonly FileRewindConfig $config,
        private readonly FileRewindLedgerProjector $ledgerProjector,
        private readonly LoggerInterface $logger,
        private readonly string $projectCwd,
    ) {
    }

    public function isOperational(): bool
    {
        if (!$this->config->enabled) {
            return false;
        }

        if (null === $this->gitOperationalCache) {
            $this->gitOperationalCache = $this->gitRunner->isGitAvailable();
        }

        return $this->gitOperationalCache;
    }

    public function recordCheckpoint(
        string $runId,
        int $turnNo,
        FileRewindCheckpointKindEnum $kind,
        int $anchorSeq,
    ): void {
        if (!$this->isOperational()) {
            return;
        }

        try {
            $this->lockManager->synchronized($runId, function () use ($runId, $turnNo, $kind, $anchorSeq): void {
                $identity = RewindProjectIdentity::fromProjectRoot($this->projectCwd);
                $scope = new RewindPathScope($this->projectCwd);
                $gitDir = $this->paths->hiddenGitDir($identity);
                $tmpIndex = $this->paths->tmpDir($identity).'/capture-'.bin2hex(random_bytes(4)).'.index';

                try {
                    $treeSha = $this->backend->captureTreeSha($gitDir, $this->projectCwd, $tmpIndex, $scope);
                    $cacheKey = $runId.'|'.$identity->projectHash;
                    if ($treeSha === ($this->lastTreeShaByRunProject[$cacheKey] ?? null) && FileRewindCheckpointKindEnum::RestoreUndo !== $kind) {
                        return;
                    }
                    $this->lastTreeShaByRunProject[$cacheKey] = $treeSha;
                    $commitSha = $this->backend->treeShaToCommitSha($gitDir, $this->projectCwd, $treeSha, 'hatfield file rewind');

                    $events = $this->eventStore->allFor($runId);
                    $maxSeq = 0;
                    foreach ($events as $e) {
                        $maxSeq = max($maxSeq, $e->seq);
                    }

                    $payload = [
                        'turn_no' => $turnNo,
                        'anchor_seq' => $anchorSeq,
                        'kind' => $kind->value,
                        'project_hash' => $identity->projectHash,
                        'backend_version' => self::BACKEND_VERSION,
                        'snapshot_commit_sha' => $commitSha,
                        'tree_sha' => $treeSha,
                    ];

                    $this->eventStore->append(new RunEvent(
                        runId: $runId,
                        seq: $maxSeq + 1,
                        turnNo: $turnNo,
                        type: RunEventTypeEnum::FileRewindCheckpointRecorded->value,
                        payload: $payload,
                        createdAt: new \DateTimeImmutable(),
                    ));

                    $this->pruneRetainedRefs($this->eventStore->allFor($runId));
                } finally {
                    if (is_file($tmpIndex)) {
                        @unlink($tmpIndex);
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->logger->warning('file_rewind.checkpoint_failed', [
                'run_id' => $runId,
                'turn_no' => $turnNo,
                'kind' => $kind->value,
                'component' => 'file_rewind',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @throws \RuntimeException on restore failure
     */
    public function restoreForTurn(string $runId, int $targetTurnNo): void
    {
        if (!$this->isOperational()) {
            throw new \RuntimeException('File rewind is unavailable (disabled or git missing).');
        }

        $this->lockManager->synchronized($runId, function () use ($runId, $targetTurnNo): void {
            $events = $this->eventStore->allFor($runId);
            $byTurn = $this->ledgerProjector->checkpointsByTurn($events, $this->config->maxRetainedTurns);
            if (!isset($byTurn[$targetTurnNo])) {
                throw new \RuntimeException('No file checkpoint for turn '.$targetTurnNo.'.');
            }
            $entry = $byTurn[$targetTurnNo];
            if ($entry->pruned) {
                throw new \RuntimeException($entry->unavailableReason ?? 'Checkpoint unavailable.');
            }

            $identity = RewindProjectIdentity::fromProjectRoot($this->projectCwd);
            $scope = new RewindPathScope($this->projectCwd);
            $gitDir = $this->paths->hiddenGitDir($identity);
            $tmpIndex = $this->paths->tmpDir($identity).'/undo-'.bin2hex(random_bytes(4)).'.index';

            try {
                $undoTree = $this->backend->captureTreeSha($gitDir, $this->projectCwd, $tmpIndex, $scope);
                $undoCommit = $this->backend->treeShaToCommitSha($gitDir, $this->projectCwd, $undoTree, 'hatfield file rewind undo');

                $maxSeq = 0;
                foreach ($events as $e) {
                    $maxSeq = max($maxSeq, $e->seq);
                }

                try {
                    $this->backend->restoreCommitToWorktree($gitDir, $this->projectCwd, $entry->snapshotCommitSha, $scope);
                } catch (\Throwable $e) {
                    $this->appendRestoreEvent($runId, $targetTurnNo, $maxSeq + 1, $entry->snapshotCommitSha, $undoCommit, $identity->projectHash, 'failed', $e->getMessage());
                    $this->pruneRetainedRefs($this->eventStore->allFor($runId));
                    $this->logger->warning('file_rewind.restore_failed_partial', [
                        'run_id' => $runId,
                        'turn_no' => $targetTurnNo,
                        'component' => 'file_rewind',
                        'error' => $e->getMessage(),
                    ]);
                    unset($this->lastTreeShaByRunProject[$runId.'|'.$identity->projectHash]);
                    throw $e;
                }

                $this->appendRestoreEvent($runId, $targetTurnNo, $maxSeq + 1, $entry->snapshotCommitSha, $undoCommit, $identity->projectHash, 'succeeded', null);
                $this->pruneRetainedRefs($this->eventStore->allFor($runId));
                unset($this->lastTreeShaByRunProject[$runId.'|'.$identity->projectHash]);
            } finally {
                if (is_file($tmpIndex)) {
                    @unlink($tmpIndex);
                }
            }
        });
    }

    public function undoLastRestore(string $runId): void
    {
        $this->lockManager->synchronized($runId, function () use ($runId): void {
            $events = $this->eventStore->allFor($runId);
            $undo = $this->ledgerProjector->findUndoCheckpoint($events);
            if (null === $undo) {
                throw new \RuntimeException('No file rewind undo checkpoint available.');
            }

            $identity = RewindProjectIdentity::fromProjectRoot($this->projectCwd);
            $scope = new RewindPathScope($this->projectCwd);
            $gitDir = $this->paths->hiddenGitDir($identity);
            $this->backend->restoreCommitToWorktree($gitDir, $this->projectCwd, $undo->snapshotCommitSha, $scope);
            unset($this->lastTreeShaByRunProject[$runId.'|'.$identity->projectHash]);
        });
    }

    private function appendRestoreEvent(
        string $runId,
        int $targetTurnNo,
        int $seq,
        string $targetSha,
        string $undoSha,
        string $projectHash,
        string $status,
        ?string $errorMessage,
    ): void {
        $payload = [
            'turn_no' => $targetTurnNo,
            'target_snapshot_commit_sha' => $targetSha,
            'undo_snapshot_commit_sha' => $undoSha,
            'project_hash' => $projectHash,
            'status' => $status,
        ];
        if (null !== $errorMessage && '' !== $errorMessage) {
            $payload['error'] = $errorMessage;
        }

        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: $targetTurnNo,
            type: RunEventTypeEnum::FileRewindRestored->value,
            payload: $payload,
            createdAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * @param list<RunEvent> $events
     */
    private function pruneRetainedRefs(array $events): void
    {
        $byTurn = $this->ledgerProjector->checkpointsByTurn($events, $this->config->maxRetainedTurns);
        $keep = [];
        foreach ($byTurn as $entry) {
            if (!$entry->pruned && '' !== $entry->snapshotCommitSha) {
                $keep[] = $entry->snapshotCommitSha;
            }
        }
        $undo = $this->ledgerProjector->findUndoCheckpoint($events);
        if (null !== $undo && '' !== $undo->snapshotCommitSha) {
            $keep[] = $undo->snapshotCommitSha;
        }

        $identity = RewindProjectIdentity::fromProjectRoot($this->projectCwd);
        $gitDir = $this->paths->hiddenGitDir($identity);
        $this->backend->pruneCommitRefs($gitDir, $this->projectCwd, $keep);
    }
}
