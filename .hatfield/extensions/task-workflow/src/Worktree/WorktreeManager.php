<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Worktree;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo;

final class WorktreeManager
{
    /** @var list<string> */
    private const WORKTREE_EXCLUDE_PATHS = [
        '.hatfield',
        '.vera',
        'var',
        'vendor',
        'apps/coding-agent/var',
        'apps/coding-agent/vendor',
        'packages/agent-core/var',
        'packages/agent-core/vendor',
        'packages/ai-index/vendor',
    ];

    public function __construct(
        private readonly GitExecutor $git,
        private readonly ExecInterface $exec,
    ) {
    }

    public static function defaultWorktreeBase(string $root): string
    {
        return \dirname($root).'/'.basename($root).'-worktrees';
    }

    /** @return list<string> */
    public static function staleAddedDeletedPaths(string $status): array
    {
        $paths = [];
        foreach (explode("\n", $status) as $line) {
            $line = rtrim($line);
            if (!str_starts_with($line, 'AD ')) {
                continue;
            }
            $path = trim(substr($line, 3));
            if ('' !== $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    public static function formatDirtyIntegrationCheckoutMessage(string $branch, string $status): string
    {
        $lines = array_values(array_filter(explode("\n", rtrim($status)), static fn (string $line): bool => '' !== $line));
        $stalePaths = self::staleAddedDeletedPaths($status);
        $untracked = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, '??')));
        $staged = array_values(array_filter($lines, static fn (string $l): bool => !str_starts_with($l, '??') && ($l[0] ?? ' ') !== ' '));
        $unstaged = array_values(array_filter($lines, static fn (string $l): bool => !str_starts_with($l, '??') && \strlen($l) > 1 && ' ' !== $l[1]));

        return implode("\n", [
            'Integration checkout is not clean; refusing to merge '.$branch.'.',
            '',
            'Status:',
            rtrim($status),
            '',
            'Categorized:',
            '- staged changes: '.\count($staged),
            '- unstaged changes: '.\count($unstaged),
            '- untracked files: '.\count($untracked),
            '- stale staged-add/deleted-worktree entries (AD): '.\count($stalePaths),
            '',
            'Suggested fixes:',
            '- Commit or stash unrelated integration-checkout changes before moving the task to DONE.',
            '- If the dirty status is only stale AD entries, retry move_task with cleanupStaleIndexEntries=true.',
            '- Use requireCleanMain=false only when you intentionally want to merge into a dirty checkout.',
        ]);
    }

    public function createWorktreeForTask(string $codeRoot, TaskInfo $task, ?string $worktreeBase): WorktreeCreateResult
    {
        $slug = preg_replace('/\.md$/', '', $task->file) ?? $task->file;
        $branch = 'task/'.$slug;
        $base = (null !== $worktreeBase && '' !== $worktreeBase)
            ? $this->resolvePath($codeRoot, $worktreeBase)
            : self::defaultWorktreeBase($codeRoot);
        $worktree = $base.'/'.$slug;
        if (!is_dir($base)) {
            mkdir($base, 0o755, true);
        }
        if (file_exists($worktree)) {
            throw new \RuntimeException('Worktree path already exists: '.$worktree);
        }

        $exists = $this->git->branchExists($codeRoot, $branch);
        $args = $exists
            ? ['worktree', 'add', $worktree, $branch]
            : ['worktree', 'add', '-b', $branch, $worktree, 'HEAD'];
        $result = $this->git->gitOk($args, $codeRoot);

        $vendorCopied = $this->copyTreeIfMissing($codeRoot.'/vendor', $worktree.'/vendor');
        $veraCopied = $this->copyTreeIfMissing($codeRoot.'/.vera', $worktree.'/.vera');
        $exclusion = $this->addWorktreeExclusions($slug, $base);
        $extensionsVendorInstalled = $this->installExtensionsVendor($worktree);

        return new WorktreeCreateResult(
            branch: $branch,
            worktree: $worktree,
            output: trim('' !== $result->stdout ? $result->stdout : $result->stderr),
            veraCopied: $veraCopied,
            vendorCopied: $vendorCopied,
            extensionsVendorInstalled: $extensionsVendorInstalled,
            ideaExclusionsUpdated: $exclusion['updated'],
            ideaNote: $exclusion['note'] ?? null,
        );
    }

    /**
     * @param array{cleanupWorktree: bool, deleteBranch: bool, requireCleanMain: bool, cleanupStaleIndexEntries: bool} $options
     *
     * @return list<string>
     */
    public function mergeTaskBranch(string $codeRoot, TaskInfo $task, array $options): array
    {
        $branch = $task->branch;
        $worktree = $task->worktree;
        if (null === $branch || '' === $branch || null === $worktree || '' === $worktree) {
            return ['No Branch/Worktree metadata found; moved task without git merge.'];
        }

        $notes = [];
        if ($options['requireCleanMain']) {
            $mainStatus = $this->git->gitOk(['status', '--porcelain'], $codeRoot);
            if ('' !== trim($mainStatus->stdout) && $options['cleanupStaleIndexEntries']) {
                $stalePaths = self::staleAddedDeletedPaths($mainStatus->stdout);
                if ([] !== $stalePaths) {
                    $this->git->gitOk(array_merge(['reset', 'HEAD', '--'], $stalePaths), $codeRoot);
                    $notes[] = 'Reset stale staged entries: '.implode(', ', $stalePaths).'.';
                    $mainStatus = $this->git->gitOk(['status', '--porcelain'], $codeRoot);
                }
            }
            if ('' !== trim($mainStatus->stdout)) {
                throw new \RuntimeException(self::formatDirtyIntegrationCheckoutMessage($branch, $mainStatus->stdout));
            }
        }

        $wtStatus = $this->git->gitOk(['status', '--porcelain'], $worktree);
        if ('' !== trim($wtStatus->stdout)) {
            throw new \RuntimeException("Worktree has uncommitted changes; commit them before moving to DONE.\n{$worktree}\n{$wtStatus->stdout}");
        }

        $merge = $this->git->git(['merge', '--no-ff', '--no-edit', $branch], $codeRoot);
        if (0 !== $merge->exitCode) {
            $conflicts = $this->git->git(['diff', '--name-only', '--diff-filter=U'], $codeRoot);
            throw new \RuntimeException("Merge of {$branch} failed. Resolve conflicts in integration checkout, then retry move_task.\nConflicts:\n".('' !== trim($conflicts->stdout) ? $conflicts->stdout : '(none reported)')."\n\n".trim('' !== $merge->stderr ? $merge->stderr : $merge->stdout));
        }

        $notes[] = 'Merged '.$branch.' into integration checkout.';
        $notes[] = trim('' !== $merge->stdout ? $merge->stdout : $merge->stderr);

        if ($options['cleanupWorktree']) {
            $slug = preg_replace('/\.md$/', '', $task->file) ?? $task->file;
            $base = \dirname($worktree);
            $remove = $this->git->git(['worktree', 'remove', $worktree], $codeRoot);
            if (0 !== $remove->exitCode) {
                $notes[] = 'Worktree cleanup failed: '.trim('' !== $remove->stderr ? $remove->stderr : $remove->stdout);
                $notes[] = 'IDEA exclusions preserved for '.$worktree.' because worktree removal failed.';
            } else {
                $notes[] = 'Removed worktree '.$worktree.'.';
                $exclusion = $this->removeWorktreeExclusions($slug, $base);
                if (($exclusion['note'] ?? null) !== null) {
                    $notes[] = $exclusion['note'];
                }
                if ($exclusion['updated']) {
                    $notes[] = 'Removed IDEA exclusions for worktree '.$worktree.'.';
                }
            }
        }

        if ($options['deleteBranch']) {
            $del = $this->git->git(['branch', '-d', $branch], $codeRoot);
            $notes[] = 0 === $del->exitCode
                ? 'Deleted branch '.$branch.'.'
                : 'Branch deletion failed: '.trim('' !== $del->stderr ? $del->stderr : $del->stdout);
        }

        $pull = $this->git->git(['pull'], $codeRoot);
        if (0 === $pull->exitCode) {
            $notes[] = 'Pulled integration checkout: '.trim('' !== $pull->stdout ? $pull->stdout : $pull->stderr).'.';
        } else {
            $notes[] = 'Pull warning: '.trim('' !== $pull->stderr ? $pull->stderr : $pull->stdout);
        }

        return $notes;
    }

    /** @return array{updated: bool, note?: string} */
    public function addWorktreeExclusions(string $slug, string $worktreeBase): array
    {
        $imlPath = $this->findParentIdeaModule($worktreeBase);
        if (null === $imlPath) {
            return ['updated' => false, 'note' => 'Parent IDEA module not found or ambiguous; skipping exclusion update.'];
        }

        $content = file_get_contents($imlPath);
        if (false === $content) {
            return ['updated' => false, 'note' => 'Failed to read parent IDEA module: '.$imlPath];
        }

        $startTag = self::startMarker($slug);
        $endTag = self::endMarker($slug);
        $startIdx = strpos($content, $startTag);
        $endIdx = strpos($content, $endTag);
        $hasStart = false !== $startIdx;
        $hasEnd = false !== $endIdx;
        if ($hasStart !== $hasEnd) {
            return ['updated' => false, 'note' => 'Parent IDEA module has mismatched exclusion markers for '.$slug.' ('.($hasStart ? 'start-only' : 'end-only').'); skipping update to avoid corruption.'];
        }
        if ($hasStart && false !== $endIdx && false !== $startIdx && $endIdx < $startIdx) {
            return ['updated' => false, 'note' => 'Parent IDEA module has reversed exclusion markers for '.$slug.' (end before start); skipping update to avoid corruption.'];
        }

        $newBlock = $this->buildExclusionBlock($slug);
        if ($hasStart && false !== $startIdx && false !== $endIdx) {
            $content = substr($content, 0, $startIdx).$newBlock.substr($content, $endIdx + \strlen($endTag));
        } else {
            $contentCloseIdx = strpos($content, '</content>');
            if (false === $contentCloseIdx) {
                return ['updated' => false, 'note' => 'Parent IDEA module has no <content> element; cannot insert exclusions.'];
            }
            $content = substr($content, 0, $contentCloseIdx).$newBlock."\n".substr($content, $contentCloseIdx);
        }

        if (false === file_put_contents($imlPath, $content)) {
            return ['updated' => false, 'note' => 'Failed to write parent IDEA module: '.$imlPath];
        }

        return ['updated' => true];
    }

    /** @return array{updated: bool, note?: string} */
    public function removeWorktreeExclusions(string $slug, string $worktreeBase): array
    {
        $imlPath = $this->findParentIdeaModule($worktreeBase);
        if (null === $imlPath) {
            return ['updated' => false, 'note' => 'Parent IDEA module not found; skipping exclusion cleanup.'];
        }

        $content = file_get_contents($imlPath);
        if (false === $content) {
            return ['updated' => false, 'note' => 'Failed to read parent IDEA module for cleanup: '.$imlPath];
        }

        $startTag = self::startMarker($slug);
        $endTag = self::endMarker($slug);
        $startIdx = strpos($content, $startTag);
        $endIdx = strpos($content, $endTag);
        $hasStart = false !== $startIdx;
        $hasEnd = false !== $endIdx;
        if ($hasStart !== $hasEnd) {
            return ['updated' => false, 'note' => 'Parent IDEA module has mismatched exclusion markers for '.$slug.' ('.($hasStart ? 'start-only' : 'end-only').'); skipping cleanup to avoid corruption.'];
        }
        if ($hasStart && false !== $endIdx && false !== $startIdx && $endIdx < $startIdx) {
            return ['updated' => false, 'note' => 'Parent IDEA module has reversed exclusion markers for '.$slug.' (end before start); skipping cleanup to avoid corruption.'];
        }
        if (!$hasStart || false === $startIdx || false === $endIdx) {
            return ['updated' => false];
        }

        $beforeStart = strrpos(substr($content, 0, $startIdx), "\n");
        $removeStart = false !== $beforeStart && $beforeStart > 0 ? $beforeStart : $startIdx;
        $content = substr($content, 0, $removeStart).substr($content, $endIdx + \strlen($endTag));

        if (false === file_put_contents($imlPath, $content)) {
            return ['updated' => false, 'note' => 'Failed to write parent IDEA module for cleanup: '.$imlPath];
        }

        return ['updated' => true];
    }

    private function resolvePath(string $codeRoot, string $worktreeBase): string
    {
        if ('' !== $worktreeBase && '/' === $worktreeBase[0]) {
            return $worktreeBase;
        }

        return rtrim($codeRoot, '/').'/'.ltrim($worktreeBase, '/');
    }

    private function copyTreeIfMissing(string $source, string $dest): bool
    {
        if (!is_dir($source) || is_dir($dest)) {
            return false;
        }
        try {
            $this->recursiveCopy($source, $dest);

            return true;
        } catch (\Throwable) {
            // Non-fatal: vendor/.vera are developer-convenience copies; the worker can run
            // composer install or fall back to absolute-path reads. Do not hard-fail here.
            return false;
        }
    }

    private function installExtensionsVendor(string $worktree): bool
    {
        $extensionsDir = $worktree.'/.hatfield/extensions';
        if (!is_dir($extensionsDir) || !is_file($extensionsDir.'/composer.json')) {
            return false;
        }
        try {
            $result = $this->exec->exec(
                'composer',
                ['install', '-d', $extensionsDir, '--no-interaction', '--no-progress'],
                new ExecOptionsDTO(cwd: $worktree, timeout: 120.0),
            );
            if (0 !== $result->exitCode) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            // Non-fatal: extensions vendor is a developer-convenience; the worker
            // can run composer install manually or fall back. Do not hard-fail here.
            return false;
        }
    }

    private function recursiveCopy(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0o755, true);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest.\DIRECTORY_SEPARATOR.$iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0o755, true);
                }
            } elseif (!copy($item->getPathname(), $target)) {
                throw new \RuntimeException('Copy failed: '.$item->getPathname());
            }
        }
    }

    private function findParentIdeaModule(string $worktreeBase): ?string
    {
        $ideaDir = $worktreeBase.'/.idea';
        if (!is_dir($ideaDir)) {
            return null;
        }
        $primary = $ideaDir.'/'.basename($worktreeBase).'.iml';
        if (is_file($primary)) {
            return $primary;
        }
        $entries = scandir($ideaDir);
        if (false === $entries) {
            return null;
        }
        $imlFiles = array_values(array_filter($entries, static fn (string $e): bool => str_ends_with($e, '.iml')));
        if (1 === \count($imlFiles)) {
            return $ideaDir.'/'.$imlFiles[0];
        }

        return null;
    }

    private function buildExclusionBlock(string $slug): string
    {
        $lines = ['', self::startMarker($slug)];
        foreach (self::WORKTREE_EXCLUDE_PATHS as $relPath) {
            $lines[] = '    <excludeFolder url="file://$MODULE_DIR$/'.$slug.'/'.$relPath.'" />';
        }
        $lines[] = self::endMarker($slug);

        return implode("\n", $lines);
    }

    private static function startMarker(string $slug): string
    {
        return '<!-- pi-task-workflow:start '.$slug.' -->';
    }

    private static function endMarker(string $slug): string
    {
        return '<!-- pi-task-workflow:end '.$slug.' -->';
    }
}
