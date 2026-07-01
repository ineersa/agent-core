<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

use Psr\Log\LoggerInterface;

/**
 * Hatfield-owned hidden git repository for worktree snapshots.
 *
 * Never touches the project .git directory.
 */
final class HiddenGitSnapshotBackend
{
    private const string COMMIT_REF_PREFIX = 'refs/hatfield/snapshots/commits/';
    private const int DIR_MODE = 0700;
    private const int GIT_ADD_BATCH_SIZE = 50;
    private const int UPDATE_REF_MAX_RETRIES = 5;

    public function __construct(
        private readonly GitProcessRunner $git,
        private readonly LoggerInterface $logger,
        private readonly int $maxFileBytes,
    ) {
    }

    public function ensureInitialized(string $hiddenGitDir, string $workTree): void
    {
        if (!is_dir($hiddenGitDir)) {
            mkdir($hiddenGitDir, self::DIR_MODE, true);
        } else {
            @chmod($hiddenGitDir, self::DIR_MODE);
        }

        $env = $this->env($hiddenGitDir, $workTree);
        if (!is_file($hiddenGitDir.'/HEAD')) {
            $r = $this->git->run(['init'], $env);
            if (0 !== $r->exitCode) {
                throw new \RuntimeException('Hidden git init failed: '.$r->stderr);
            }
            foreach ([['core.autocrlf', 'false'], ['core.longpaths', 'true'], ['core.symlinks', 'true'], ['user.email', 'hatfield-rewind@local'], ['user.name', 'Hatfield Rewind']] as [$k, $v]) {
                $this->git->run(['config', $k, $v], $env);
            }
        }
    }

    /**
     * Capture worktree state as a tree SHA (dedupe-friendly).
     */
    public function captureTreeSha(
        string $hiddenGitDir,
        string $workTree,
        string $tmpIndexPath,
        RewindPathScope $scope,
    ): string {
        $this->ensureInitialized($hiddenGitDir, $workTree);
        $env = $this->env($hiddenGitDir, $workTree, $tmpIndexPath);

        if (is_file($tmpIndexPath)) {
            unlink($tmpIndexPath);
        }

        $this->stageWorktree($hiddenGitDir, $workTree, $tmpIndexPath, $scope, $env);

        $r = $this->git->run(['write-tree'], $env);
        if (0 !== $r->exitCode || '' === $r->stdoutTrimmed()) {
            throw new \RuntimeException('git write-tree failed: '.$r->stderr);
        }

        return $r->stdoutTrimmed();
    }

    public function treeShaToCommitSha(string $hiddenGitDir, string $workTree, string $treeSha, string $message): string
    {
        $env = $this->env($hiddenGitDir, $workTree);
        $r = $this->git->run(['commit-tree', $treeSha, '-m', $message], $env);
        if (0 !== $r->exitCode || '' === $r->stdoutTrimmed()) {
            throw new \RuntimeException('git commit-tree failed: '.$r->stderr);
        }

        $commit = $r->stdoutTrimmed();
        $this->pinCommitRef($hiddenGitDir, $workTree, $commit);

        return $commit;
    }

    public function restoreCommitToWorktree(
        string $hiddenGitDir,
        string $workTree,
        string $commitSha,
        RewindPathScope $scope,
    ): void {
        $env = $this->env($hiddenGitDir, $workTree);
        $targetTree = $this->commitTreeSha($hiddenGitDir, $workTree, $commitSha);
        $tmpIndex = $workTree.'/.hatfield/tmp/rewind-restore-'.bin2hex(random_bytes(4)).'.index';
        @mkdir(\dirname($tmpIndex), self::DIR_MODE, true);

        try {
            $currentIndexTree = $this->captureTreeSha($hiddenGitDir, $workTree, $tmpIndex, $scope);
            $toDelete = $this->pathsOnlyInTree($hiddenGitDir, $workTree, $currentIndexTree, $targetTree, $scope);
            foreach ($toDelete as $rel) {
                if (!$scope->isInsideProjectRoot($rel)) {
                    throw new \RuntimeException('Refusing to delete path outside project root: '.$rel);
                }
                $full = $workTree.'/'.$rel;
                if (is_file($full)) {
                    unlink($full);
                } elseif (is_dir($full)) {
                    $this->removeEmptyDirTree($full, $workTree);
                }
            }

            $this->checkoutCommitToWorktree($hiddenGitDir, $workTree, $commitSha, $scope, $env);
        } finally {
            if (is_file($tmpIndex)) {
                unlink($tmpIndex);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function listTreePaths(string $hiddenGitDir, string $workTree, string $treeSha): array
    {
        $env = $this->env($hiddenGitDir, $workTree);
        $r = $this->git->run(['ls-tree', '-r', '--name-only', $treeSha], $env);
        if (0 !== $r->exitCode) {
            return [];
        }

        $lines = array_filter(array_map('trim', explode("\n", $r->stdout)), static fn (string $line): bool => '' !== $line);

        return array_values($lines);
    }

    public function commitRefName(string $commitSha): string
    {
        if (!preg_match('/^[0-9a-f]{4,40}$/i', $commitSha)) {
            throw new \InvalidArgumentException('Invalid commit sha for ref pin.');
        }

        return self::COMMIT_REF_PREFIX.strtolower($commitSha);
    }

    public function pinCommitRef(string $hiddenGitDir, string $workTree, string $commitSha): void
    {
        $env = $this->env($hiddenGitDir, $workTree);
        $ref = $this->commitRefName($commitSha);
        for ($i = 0; $i < self::UPDATE_REF_MAX_RETRIES; ++$i) {
            $old = $this->readRefSha($hiddenGitDir, $workTree, $ref);
            $args = ['update-ref', $ref, $commitSha];
            if (null !== $old) {
                $args[] = $old;
            }
            $r = $this->git->run($args, $env);
            if (0 === $r->exitCode) {
                return;
            }
        }
        throw new \RuntimeException('Failed to pin commit ref after retries.');
    }

    public function commitShaReachable(string $hiddenGitDir, string $workTree, string $commitSha): bool
    {
        $env = $this->env($hiddenGitDir, $workTree);
        $r = $this->git->run(['rev-parse', '--verify', $commitSha.'^{commit}'], $env);

        return 0 === $r->exitCode && '' !== $r->stdoutTrimmed();
    }

    /**
     * @param list<string> $keepCommitShas full commit SHAs to retain refs for
     */
    public function pruneCommitRefs(string $hiddenGitDir, string $workTree, array $keepCommitShas): void
    {
        $keep = [];
        foreach ($keepCommitShas as $sha) {
            if ('' !== $sha) {
                $keep[strtolower($sha)] = true;
            }
        }

        $env = $this->env($hiddenGitDir, $workTree);
        $r = $this->git->run(['for-each-ref', '--format=%(refname)', self::COMMIT_REF_PREFIX], $env);
        if (0 !== $r->exitCode) {
            return;
        }

        $deletedAny = false;
        foreach (array_filter(array_map('trim', explode("\n", $r->stdout)), static fn (string $line): bool => '' !== $line) as $ref) {
            if ('' === $ref || !str_starts_with($ref, self::COMMIT_REF_PREFIX)) {
                continue;
            }
            $sha = substr($ref, \strlen(self::COMMIT_REF_PREFIX));
            if (isset($keep[strtolower($sha)])) {
                continue;
            }
            $del = $this->git->run(['update-ref', '-d', $ref], $env);
            if (0 === $del->exitCode) {
                $deletedAny = true;
            }
        }

        if ($deletedAny) {
            // Best-effort object cleanup inside hidden GIT_DIR only (never project .git).
            $this->git->run(['gc', '--prune=now'], $env);
        }
    }

    /**
     * @param array<string, string> $env
     */
    private function checkoutCommitToWorktree(
        string $hiddenGitDir,
        string $workTree,
        string $commitSha,
        RewindPathScope $scope,
        array $env,
    ): void {
        $tmpIndex = $workTree.'/.hatfield/tmp/rewind-checkout-'.bin2hex(random_bytes(4)).'.index';
        @mkdir(\dirname($tmpIndex), self::DIR_MODE, true);
        $checkoutEnv = $env + ['GIT_INDEX_FILE' => $tmpIndex];
        try {
            $r = $this->git->run(['read-tree', $commitSha], $checkoutEnv);
            if (0 !== $r->exitCode) {
                throw new \RuntimeException('git read-tree failed: '.$r->stderr);
            }
            $r = $this->git->run(['checkout-index', '-a', '-f'], $checkoutEnv);
            if (0 !== $r->exitCode) {
                throw new \RuntimeException('git checkout-index failed: '.$r->stderr);
            }
        } finally {
            if (is_file($tmpIndex)) {
                unlink($tmpIndex);
            }
        }
    }

    private function commitTreeSha(string $hiddenGitDir, string $workTree, string $commitSha): string
    {
        $env = $this->env($hiddenGitDir, $workTree);
        $r = $this->git->run(['rev-parse', $commitSha.'^{tree}'], $env);
        if (0 !== $r->exitCode) {
            throw new \RuntimeException('Cannot resolve commit tree: '.$r->stderr);
        }

        return $r->stdoutTrimmed();
    }

    /**
     * @return list<string> relative paths in $fromTree not present in $toTree
     */
    private function pathsOnlyInTree(
        string $hiddenGitDir,
        string $workTree,
        string $fromTree,
        string $toTree,
        RewindPathScope $scope,
    ): array {
        $from = array_flip($this->listTreePaths($hiddenGitDir, $workTree, $fromTree));
        $to = array_flip($this->listTreePaths($hiddenGitDir, $workTree, $toTree));
        $delete = [];
        foreach (array_keys($from) as $path) {
            if (!isset($to[$path]) && !$scope->shouldExcludeRelativePath($path)) {
                $delete[] = $path;
            }
        }

        return $delete;
    }

    /**
     * @param array<string, string> $env
     */
    private function stageWorktree(
        string $hiddenGitDir,
        string $workTree,
        string $tmpIndexPath,
        RewindPathScope $scope,
        array $env,
    ): void {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $workTree,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        $added = [];
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                // Never traverse symlink targets (dirs or files outside the tree).
                continue;
            }
            if (!$file->isFile()) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
            $real = realpath($full);
            $workReal = realpath($workTree);
            if (false === $real || false === $workReal || !str_starts_with($real.'/', $workReal.'/')) {
                continue;
            }
            $rel = ltrim(str_replace($workTree.'/', '', $full), '/');
            if ($scope->shouldExcludeRelativePath($rel) || !$scope->isInsideProjectRoot($rel)) {
                continue;
            }
            if ($file->getSize() > $this->maxFileBytes) {
                continue;
            }
            $added[] = $rel;
        }

        if ([] === $added) {
            $this->git->run(['read-tree', '--empty'], $env);

            return;
        }

        foreach (array_chunk($added, self::GIT_ADD_BATCH_SIZE) as $chunk) {
            $args = array_merge(['add', '-f', '--'], $chunk);
            $r = $this->git->run($args, $env);
            if (0 !== $r->exitCode) {
                $this->logger->warning('file_rewind.git_add_partial_failed', [
                    'component' => 'hidden_git_snapshot',
                    'stderr' => substr($r->stderr, 0, 500),
                ]);
            }
        }
    }

    private function readRefSha(string $hiddenGitDir, string $workTree, string $ref): ?string
    {
        $env = $this->env($hiddenGitDir, $workTree);
        $r = $this->git->run(['rev-parse', $ref], $env);
        if (0 !== $r->exitCode) {
            return null;
        }

        $trimmed = $r->stdoutTrimmed();

        return '' !== $trimmed ? $trimmed : null;
    }

    private function removeEmptyDirTree(string $dir, string $projectRoot): void
    {
        if (!is_dir($dir) || !str_starts_with(str_replace('\\', '/', $dir).'/', str_replace('\\', '/', $projectRoot).'/')) {
            return;
        }
        $items = scandir($dir);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeEmptyDirTree($path, $projectRoot);
            }
        }
        @rmdir($dir);
    }

    /**
     * @return array<string, string>
     */
    private function env(string $gitDir, string $workTree, ?string $indexFile = null): array
    {
        $e = [
            'GIT_DIR' => $gitDir,
            'GIT_WORK_TREE' => $workTree,
        ];
        if (null !== $indexFile) {
            $e['GIT_INDEX_FILE'] = $indexFile;
        }

        return $e;
    }
}
