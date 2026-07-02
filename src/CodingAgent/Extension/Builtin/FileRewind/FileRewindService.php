<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

use Ineersa\Hatfield\ExtensionApi\Command\FileRewindActionEnum;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindPreviewEntryDTO;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindPreviewProviderInterface;
use Psr\Log\LoggerInterface;

final class FileRewindService implements FileRewindPreviewProviderInterface
{
    private const string BACKEND_VERSION = 'hidden-git-v1';

    /** @var array<string, string> */
    private array $lastTreeShaByRunProject = [];

    private ?bool $gitOperationalCache = null;

    public function __construct(
        private readonly HiddenGitSnapshotBackend $backend,
        private readonly GitProcessRunner $gitRunner,
        private readonly RewindStoragePaths $paths,
        private readonly FileRewindLedgerStore $ledgerStore,
        private readonly FileRewindLedgerProjector $ledgerProjector,
        private readonly FileRewindConfig $config,
        private readonly LoggerInterface $logger,
        private readonly string $projectCwd,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->enabled;
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

    public function recordTurnCheckpoint(string $runId, int $turnNo, int $anchorSeq): void
    {
        if (!$this->isOperational()) {
            return;
        }
        try {
            $identity = RewindProjectIdentity::fromProjectRoot($this->projectCwd);
            $scope = new RewindPathScope($this->projectCwd);
            $gitDir = $this->paths->hiddenGitDir($identity);
            $tmpIndex = $this->paths->tmpDir($identity).'/capture-'.bin2hex(random_bytes(4)).'.index';
            try {
                $treeSha = $this->backend->captureTreeSha($gitDir, $this->projectCwd, $tmpIndex, $scope);
                $cacheKey = $runId.'|'.$identity->projectHash;
                if ($treeSha === ($this->lastTreeShaByRunProject[$cacheKey] ?? null)) {
                    return;
                }
                $this->lastTreeShaByRunProject[$cacheKey] = $treeSha;
                $commitSha = $this->backend->treeShaToCommitSha($gitDir, $this->projectCwd, $treeSha, 'hatfield file rewind');
                $this->ledgerStore->appendCheckpoint($identity, [
                    'run_id' => $runId,
                    'turn_no' => $turnNo,
                    'anchor_seq' => $anchorSeq,
                    'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
                    'project_hash' => $identity->projectHash,
                    'backend_version' => self::BACKEND_VERSION,
                    'snapshot_commit_sha' => $commitSha,
                    'tree_sha' => $treeSha,
                    'recorded_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                ]);
                $this->pruneRetainedRefs($identity);
            } finally {
                if (is_file($tmpIndex)) {
                    @unlink($tmpIndex);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('file_rewind.checkpoint_failed', [
                'run_id' => $runId,
                'turn_no' => $turnNo,
                'component' => 'file_rewind',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function hasCheckpointForTurn(string $runId, int $turnNo): bool
    {
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectCwd);
        $byTurn = $this->ledgerProjector->checkpointsByTurn(
            $this->ledgerStore->readCheckpoints($identity),
            $this->config->maxRetainedTurns,
        );

        return isset($byTurn[$turnNo]) && !$byTurn[$turnNo]->pruned;
    }

    /** @return list<FileRewindPreviewEntryDTO> */
    public function previewForTurn(string $runId, int $turnNo): array
    {
        if (!$this->isOperational()) {
            return [];
        }
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectCwd);
        $byTurn = $this->ledgerProjector->checkpointsByTurn(
            $this->ledgerStore->readCheckpoints($identity),
            $this->config->maxRetainedTurns,
        );
        if (!isset($byTurn[$turnNo]) || $byTurn[$turnNo]->pruned) {
            return [];
        }
        $target = $byTurn[$turnNo]->snapshotCommitSha;
        $scope = new RewindPathScope($this->projectCwd);
        $gitDir = $this->paths->hiddenGitDir($identity);
        $tmpIndex = $this->paths->tmpDir($identity).'/preview-'.bin2hex(random_bytes(4)).'.index';
        try {
            $currentTree = $this->backend->captureTreeSha($gitDir, $this->projectCwd, $tmpIndex, $scope);
            $targetTree = $this->backendCommitTree($gitDir, $target);
            $currentPaths = array_flip($this->backend->listTreePaths($gitDir, $this->projectCwd, $currentTree));
            $targetPaths = $this->backend->listTreePaths($gitDir, $this->projectCwd, $targetTree);
            $out = [];
            foreach ($targetPaths as $path) {
                if ($scope->shouldExcludeRelativePath($path)) {
                    continue;
                }
                $status = isset($currentPaths[$path]) ? 'modified' : 'added';
                $out[] = new FileRewindPreviewEntryDTO($path, $status, 0, 0, false, false);
            }
            foreach (array_keys($currentPaths) as $path) {
                if ($scope->shouldExcludeRelativePath($path)) {
                    continue;
                }
                if (!\in_array($path, $targetPaths, true)) {
                    $out[] = new FileRewindPreviewEntryDTO($path, 'deleted', 0, 0, false, false);
                }
            }
            usort($out, static fn ($a, $b) => strcmp($a->path, $b->path));

            return $out;
        } catch (\Throwable $e) {
            $this->logger->debug('file_rewind.preview_failed', ['error' => $e->getMessage()]);

            return [];
        } finally {
            if (is_file($tmpIndex)) {
                @unlink($tmpIndex);
            }
        }
    }

    public function executeAction(string $runId, int $turnNo, FileRewindActionEnum $action): void
    {
        match ($action) {
            FileRewindActionEnum::RestoreFiles => $this->restoreForTurn($runId, $turnNo),
            FileRewindActionEnum::UndoLastRestore => $this->undoLastRestore($runId),
            FileRewindActionEnum::Cancel => null,
            default => throw new \RuntimeException('Unsupported action: '.$action->value),
        };
    }

    public function restoreForTurn(string $runId, int $targetTurnNo): void
    {
        if (!$this->isOperational()) {
            throw new \RuntimeException('File rewind is unavailable (disabled or git missing).');
        }
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectCwd);
        $byTurn = $this->ledgerProjector->checkpointsByTurn(
            $this->ledgerStore->readCheckpoints($identity),
            $this->config->maxRetainedTurns,
        );
        if (!isset($byTurn[$targetTurnNo])) {
            throw new \RuntimeException('No file checkpoint for turn '.$targetTurnNo.'.');
        }
        $entry = $byTurn[$targetTurnNo];
        if ($entry->pruned) {
            throw new \RuntimeException($entry->unavailableReason ?? 'Checkpoint unavailable.');
        }
        $scope = new RewindPathScope($this->projectCwd);
        $gitDir = $this->paths->hiddenGitDir($identity);
        $tmpIndex = $this->paths->tmpDir($identity).'/undo-'.bin2hex(random_bytes(4)).'.index';
        try {
            $undoTree = $this->backend->captureTreeSha($gitDir, $this->projectCwd, $tmpIndex, $scope);
            $undoCommit = $this->backend->treeShaToCommitSha($gitDir, $this->projectCwd, $undoTree, 'hatfield file rewind undo');
            try {
                $this->backend->restoreCommitToWorktree(
                    $gitDir,
                    $this->projectCwd,
                    $entry->snapshotCommitSha,
                    $scope,
                    $this->paths->tmpDir($identity),
                );
                $this->ledgerStore->appendRestore($identity, [
                    'run_id' => $runId,
                    'turn_no' => $targetTurnNo,
                    'target_snapshot_commit_sha' => $entry->snapshotCommitSha,
                    'undo_snapshot_commit_sha' => $undoCommit,
                    'project_hash' => $identity->projectHash,
                    'status' => 'succeeded',
                    'restored_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                ]);
            } catch (\Throwable $e) {
                $this->ledgerStore->appendRestore($identity, [
                    'run_id' => $runId,
                    'turn_no' => $targetTurnNo,
                    'target_snapshot_commit_sha' => $entry->snapshotCommitSha,
                    'undo_snapshot_commit_sha' => $undoCommit,
                    'project_hash' => $identity->projectHash,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'restored_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                ]);
                unset($this->lastTreeShaByRunProject[$runId.'|'.$identity->projectHash]);
                throw $e;
            }
            $this->pruneRetainedRefs($identity);
            unset($this->lastTreeShaByRunProject[$runId.'|'.$identity->projectHash]);
        } finally {
            if (is_file($tmpIndex)) {
                @unlink($tmpIndex);
            }
        }
    }

    public function undoLastRestore(string $runId): void
    {
        if (!$this->isOperational()) {
            throw new \RuntimeException('File rewind is unavailable.');
        }
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectCwd);
        $undo = $this->ledgerProjector->findUndoCheckpoint($this->ledgerStore->readRestores($identity));
        if (null === $undo) {
            throw new \RuntimeException('No file rewind undo checkpoint available.');
        }
        $scope = new RewindPathScope($this->projectCwd);
        $gitDir = $this->paths->hiddenGitDir($identity);
        $this->backend->restoreCommitToWorktree(
            $gitDir,
            $this->projectCwd,
            $undo->snapshotCommitSha,
            $scope,
            $this->paths->tmpDir($identity),
        );
        unset($this->lastTreeShaByRunProject[$runId.'|'.$identity->projectHash]);
    }

    private function backendCommitTree(string $gitDir, string $commitSha): string
    {
        $env = ['GIT_DIR' => $gitDir, 'GIT_WORK_TREE' => $this->projectCwd];
        $r = $this->gitRunner->run(['rev-parse', $commitSha.'^{tree}'], $env);
        if (0 !== $r->exitCode) {
            throw new \RuntimeException('Cannot resolve commit tree.');
        }

        return $r->stdoutTrimmed();
    }

    private function pruneRetainedRefs(RewindProjectIdentity $identity): void
    {
        $byTurn = $this->ledgerProjector->checkpointsByTurn(
            $this->ledgerStore->readCheckpoints($identity),
            $this->config->maxRetainedTurns,
        );
        $keep = [];
        foreach ($byTurn as $entry) {
            if (!$entry->pruned && '' !== $entry->snapshotCommitSha) {
                $keep[] = $entry->snapshotCommitSha;
            }
        }
        $undo = $this->ledgerProjector->findUndoCheckpoint($this->ledgerStore->readRestores($identity));
        if (null !== $undo && '' !== $undo->snapshotCommitSha) {
            $keep[] = $undo->snapshotCommitSha;
        }
        $gitDir = $this->paths->hiddenGitDir($identity);
        $this->backend->pruneCommitRefs($gitDir, $this->projectCwd, $keep);
    }
}
