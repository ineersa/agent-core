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
    private const string KEEPALIVE_REF = 'refs/hatfield/snapshots/store';

    public function __construct(
        private readonly GitProcessRunner $git,
        private readonly LoggerInterface $logger,
        private readonly int $maxFileBytes,
    ) {
    }

    public function ensureInitialized(string $hiddenGitDir, string $workTree): void
    {
        if (!is_dir($hiddenGitDir)) {
            mkdir($hiddenGitDir, 0777, true);
        }

        $env = $this->env($hiddenGitDir, $workTree);
        if (!is_file($hiddenGitDir.'/HEAD')) {
            $r = $this->git->run(['init'], $env);
            if (0 !== $r->exitCode) {
                throw new \RuntimeException('Hidden git init failed: '.$r->stderr);
            }
            foreach ([['core.autocrlf', 'false'], ['core.longpaths', 'true'], ['core.symlinks', 'true']] as [$k, $v]) {
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
        $this->pinKeepalive($hiddenGitDir, $workTree, $commit);

        return $commit;
    }

    public function restoreCommitToWorktree(
        string $hiddenGitDir,
        string $workTree,
        string $commitSha,
        RewindPathScope $scope,
    ): void {
        $env = $this->env($hiddenGitDir, $workTree);
        $currentTree = $this->commitTreeSha($hiddenGitDir, $workTree, $commitSha);
        $tmpIndex = $workTree.'/.hatfield/tmp/rewind-restore-'.bin2hex(random_bytes(4)).'.index';
        @mkdir(\dirname($tmpIndex), 0777, true);

        try {
            $currentIndexTree = $this->captureTreeSha($hiddenGitDir, $workTree, $tmpIndex, $scope);
            $toDelete = $this->pathsOnlyInTree($hiddenGitDir, $workTree, $currentIndexTree, $currentTree, $scope);
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

            $r = $this->git->run(['restore', '--source='.$commitSha, '--worktree', '--', '.'], $env);
            if (0 !== $r->exitCode) {
                throw new \RuntimeException('git restore failed: '.$r->stderr);
            }
        } finally {
            if (is_file($tmpIndex)) {
                unlink($tmpIndex);
            }
        }
    }

    public function listTreePaths(string $hiddenGitDir, string $workTree, string $treeSha): array
    {
        $env = $this->env($hiddenGitDir, $workTree);
        $r = $this->git->run(['ls-tree', '-r', '--name-only', $treeSha], $env);
        if (0 !== $r->exitCode) {
            return [];
        }

        $lines = array_filter(array_map('trim', explode("\n", $r->stdout)));

        return array_values($lines);
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

    private function stageWorktree(
        string $hiddenGitDir,
        string $workTree,
        string $tmpIndexPath,
        RewindPathScope $scope,
        array $env,
    ): void {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workTree, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        $added = [];
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
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

        foreach (array_chunk($added, 200) as $chunk) {
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

    private function pinKeepalive(string $hiddenGitDir, string $workTree, string $commitSha): void
    {
        $env = $this->env($hiddenGitDir, $workTree);
        for ($i = 0; $i < 5; ++$i) {
            $old = $this->readRef($hiddenGitDir, $workTree);
            $args = ['update-ref', self::KEEPALIVE_REF, $commitSha];
            if (null !== $old) {
                $args[] = $old;
            }
            $r = $this->git->run($args, $env);
            if (0 === $r->exitCode) {
                return;
            }
        }
        throw new \RuntimeException('Failed to update keepalive ref after retries.');
    }

    private function readRef(string $hiddenGitDir, string $workTree): ?string
    {
        $env = $this->env($hiddenGitDir, $workTree);
        $r = $this->git->run(['rev-parse', self::KEEPALIVE_REF], $env);
        if (0 !== $r->exitCode) {
            return null;
        }

        return $r->stdoutTrimmed() ?: null;
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
