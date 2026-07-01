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

    private ?string $lastTreeShaCache = null;

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
        return $this->config->enabled && $this->gitRunner->isGitAvailable();
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

                $treeSha = $this->backend->captureTreeSha($gitDir, $this->projectCwd, $tmpIndex, $scope);
                if ($treeSha === $this->lastTreeShaCache && FileRewindCheckpointKindEnum::RestoreUndo !== $kind) {
                    @unlink($tmpIndex);

                    return;
                }
                $this->lastTreeShaCache = $treeSha;
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

                @unlink($tmpIndex);
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
            $undoTree = $this->backend->captureTreeSha($gitDir, $this->projectCwd, $tmpIndex, $scope);
            $undoCommit = $this->backend->treeShaToCommitSha($gitDir, $this->projectCwd, $undoTree, 'hatfield file rewind undo');
            @unlink($tmpIndex);

            $this->backend->restoreCommitToWorktree($gitDir, $this->projectCwd, $entry->snapshotCommitSha, $scope);
            $this->lastTreeShaCache = null;

            $maxSeq = 0;
            foreach ($events as $e) {
                $maxSeq = max($maxSeq, $e->seq);
            }

            $this->eventStore->append(new RunEvent(
                runId: $runId,
                seq: $maxSeq + 1,
                turnNo: $targetTurnNo,
                type: RunEventTypeEnum::FileRewindRestored->value,
                payload: [
                    'turn_no' => $targetTurnNo,
                    'target_snapshot_commit_sha' => $entry->snapshotCommitSha,
                    'undo_snapshot_commit_sha' => $undoCommit,
                    'project_hash' => $identity->projectHash,
                    'changed_files' => [],
                ],
                createdAt: new \DateTimeImmutable(),
            ));
        });
    }

    public function undoLastRestore(string $runId): void
    {
        $events = $this->eventStore->allFor($runId);
        $undo = $this->ledgerProjector->findUndoCheckpoint($events);
        if (null === $undo) {
            throw new \RuntimeException('No file rewind undo checkpoint available.');
        }

        $identity = RewindProjectIdentity::fromProjectRoot($this->projectCwd);
        $scope = new RewindPathScope($this->projectCwd);
        $gitDir = $this->paths->hiddenGitDir($identity);
        $this->backend->restoreCommitToWorktree($gitDir, $this->projectCwd, $undo->snapshotCommitSha, $scope);
        $this->lastTreeShaCache = null;
    }
}
