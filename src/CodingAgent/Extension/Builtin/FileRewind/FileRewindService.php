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
    private const int PREVIEW_MAX_LCS_LINES = 8_000;

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

    public function hasCheckpointForTurn(string $sessionId, int $turnNo): bool
    {
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectCwd);
        $byTurn = $this->ledgerProjector->checkpointsByTurn(
            $this->ledgerStore->readCheckpoints($identity),
            $this->config->maxRetainedTurns,
        );

        return isset($byTurn[$turnNo]) && !$byTurn[$turnNo]->pruned;
    }

    /** @return list<FileRewindPreviewEntryDTO> */
    public function previewForTurn(string $sessionId, int $turnNo): array
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
            $currentPaths = $this->backend->listTreePaths($gitDir, $this->projectCwd, $currentTree);
            $targetPaths = $this->backend->listTreePaths($gitDir, $this->projectCwd, $targetTree);
            $currentSet = array_flip($currentPaths);
            $targetSet = array_flip($targetPaths);
            $allPaths = array_unique(array_merge($targetPaths, $currentPaths));
            $out = [];
            foreach ($allPaths as $path) {
                if ($scope->shouldExcludeRelativePath($path)) {
                    continue;
                }
                $inCurrent = isset($currentSet[$path]);
                $inTarget = isset($targetSet[$path]);
                if ($inCurrent && $inTarget) {
                    $status = 'modified';
                } elseif ($inTarget) {
                    $status = 'added';
                } else {
                    $status = 'deleted';
                }
                $out[] = $this->buildPreviewEntry($gitDir, $currentTree, $targetTree, $path, $status);
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

    private function buildPreviewEntry(
        string $gitDir,
        string $currentTree,
        string $targetTree,
        string $path,
        string $status,
    ): FileRewindPreviewEntryDTO {
        if ('deleted' === $status) {
            return new FileRewindPreviewEntryDTO($path, $status, 0, 0, false, false);
        }
        $currentBlob = $this->treeBlobSha($gitDir, $currentTree, $path);
        $targetBlob = $this->treeBlobSha($gitDir, $targetTree, $path);
        if (null === $targetBlob && null === $currentBlob) {
            return new FileRewindPreviewEntryDTO($path, $status, 0, 0, false, true);
        }
        if (null !== $currentBlob && null !== $targetBlob && $currentBlob === $targetBlob) {
            return new FileRewindPreviewEntryDTO($path, $status, 0, 0, false, false);
        }
        $currentBytes = $this->readBlobBytes($gitDir, $currentBlob);
        $targetBytes = $this->readBlobBytes($gitDir, $targetBlob);
        if ($this->isBinaryBlob($currentBytes) || $this->isBinaryBlob($targetBytes)) {
            return new FileRewindPreviewEntryDTO($path, $status, 0, 0, true, false);
        }
        $maxBytes = $this->config->maxFileBytes;
        if ((null !== $currentBytes && \strlen($currentBytes) > $maxBytes)
            || (null !== $targetBytes && \strlen($targetBytes) > $maxBytes)) {
            return new FileRewindPreviewEntryDTO($path, $status, 0, 0, false, true);
        }
        $currentLines = $this->splitLines($currentBytes ?? '');
        $targetLines = $this->splitLines($targetBytes ?? '');
        if (\count($currentLines) > self::PREVIEW_MAX_LCS_LINES || \count($targetLines) > self::PREVIEW_MAX_LCS_LINES) {
            return new FileRewindPreviewEntryDTO($path, $status, 0, 0, false, true);
        }
        [$added, $removed] = $this->countLineDiff($currentLines, $targetLines);

        return new FileRewindPreviewEntryDTO($path, $status, $added, $removed, false, false);
    }

    private function treeBlobSha(string $gitDir, string $treeSha, string $path): ?string
    {
        $env = ['GIT_DIR' => $gitDir, 'GIT_WORK_TREE' => $this->projectCwd];
        $r = $this->gitRunner->run(['ls-tree', $treeSha, '--', $path], $env);
        if (0 !== $r->exitCode || '' === trim($r->stdout)) {
            return null;
        }
        $line = trim(explode("\n", trim($r->stdout))[0]);
        if (!preg_match('/^\d+ blob ([0-9a-f]{4,40})\t/', $line, $m)) {
            return null;
        }

        return strtolower($m[1]);
    }

    private function readBlobBytes(string $gitDir, ?string $blobSha): ?string
    {
        if (null === $blobSha || '' === $blobSha) {
            return null;
        }
        $env = ['GIT_DIR' => $gitDir, 'GIT_WORK_TREE' => $this->projectCwd];
        $r = $this->gitRunner->run(['cat-file', 'blob', $blobSha], $env);
        if (0 !== $r->exitCode) {
            return null;
        }

        return $r->stdout;
    }

    private function isBinaryBlob(?string $bytes): bool
    {
        if (null === $bytes || '' === $bytes) {
            return false;
        }
        if (str_contains($bytes, "\0")) {
            return true;
        }

        return !mb_check_encoding($bytes, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $text): array
    {
        if ('' === $text) {
            return [];
        }
        $parts = preg_split('/\r\n|\n|\r/', $text);

        return false === $parts ? [] : $parts;
    }

    /**
     * @param list<string> $currentLines
     * @param list<string> $targetLines
     *
     * @return array{0: int, 1: int}
     */
    private function countLineDiff(array $currentLines, array $targetLines): array
    {
        $lcs = $this->longestCommonSubsequenceLength($currentLines, $targetLines);
        $removed = \count($currentLines) - $lcs;
        $added = \count($targetLines) - $lcs;

        return [$added, $removed];
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function longestCommonSubsequenceLength(array $a, array $b): int
    {
        $m = \count($a);
        $n = \count($b);
        if (0 === $m || 0 === $n) {
            return 0;
        }
        $prev = array_fill(0, $n + 1, 0);
        $curr = array_fill(0, $n + 1, 0);
        for ($i = 1; $i <= $m; ++$i) {
            for ($j = 1; $j <= $n; ++$j) {
                if ($a[$i - 1] === $b[$j - 1]) {
                    $curr[$j] = $prev[$j - 1] + 1;
                } else {
                    $curr[$j] = max($prev[$j], $curr[$j - 1]);
                }
            }
            [$prev, $curr] = [$curr, array_fill(0, $n + 1, 0)];
        }

        return $prev[$n];
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
