<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Worktree;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Ide\JetBrainsMcpClient;
use Ineersa\HatfieldExt\TaskWorkflow\Ide\WorktreeIdeaSetup;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\InvocationControl;

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

    public function createWorktreeForTask(
        string $codeRoot,
        TaskInfo $task,
        ?string $worktreeBase,
        ?InvocationControl $control = null,
    ): WorktreeCreateResult|ExecResultDTO {
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

        if (null !== ($interrupt = $control?->interrupted('Cancelled before worktree creation.'))) {
            return $this->interruptResult($interrupt);
        }

        $exists = $this->git->branchExists($codeRoot, $branch, $control);
        if (null !== ($interrupt = $control?->interrupted('Interrupted while checking branch existence.'))) {
            return $this->interruptResult($interrupt);
        }

        $args = $exists
            ? ['worktree', 'add', $worktree, $branch]
            : ['worktree', 'add', '-b', $branch, $worktree, 'HEAD'];
        $result = $this->git->gitOk($args, $codeRoot, 120.0, $control);
        if ($result->cancelled || $result->timedOut) {
            // git worktree add may leave a partial path; clean reversible owned resources.
            $this->cleanupPartialWorktree($codeRoot, $worktree, $slug, $base);

            return $result;
        }

        if (null !== ($interrupt = $control?->interrupted('Interrupted after worktree create.'))) {
            $this->cleanupPartialWorktree($codeRoot, $worktree, $slug, $base);

            return $this->interruptResult($interrupt);
        }

        $vendorCopied = $this->copyTreeIfMissing($codeRoot.'/vendor', $worktree.'/vendor', $control);
        if ($vendorCopied instanceof ExecResultDTO) {
            $this->cleanupPartialWorktree($codeRoot, $worktree, $slug, $base);

            return $vendorCopied;
        }
        if (null !== ($interrupt = $control?->interrupted('Interrupted while copying vendor.'))) {
            $this->cleanupPartialWorktree($codeRoot, $worktree, $slug, $base);

            return $this->interruptResult($interrupt);
        }

        $veraCopied = $this->copyTreeIfMissing($codeRoot.'/.vera', $worktree.'/.vera', $control);
        if ($veraCopied instanceof ExecResultDTO) {
            $this->cleanupPartialWorktree($codeRoot, $worktree, $slug, $base);

            return $veraCopied;
        }
        if (null !== ($interrupt = $control?->interrupted('Interrupted while copying .vera.'))) {
            $this->cleanupPartialWorktree($codeRoot, $worktree, $slug, $base);

            return $this->interruptResult($interrupt);
        }

        if (is_dir($codeRoot.'/vendor/ineersa/hatfield-extension-api') && !$this->hasUsableExtensionApiLink($worktree)) {
            $repair = $this->repairRootVendor($worktree, $control);
            if ($repair instanceof ExecResultDTO) {
                $this->cleanupPartialWorktree($codeRoot, $worktree, $slug, $base);

                return $repair;
            }
            if (!$this->hasUsableExtensionApiLink($worktree)) {
                $this->cleanupPartialWorktree($codeRoot, $worktree, $slug, $base);
                throw new \RuntimeException('vendor package link broken: vendor/ineersa/hatfield-extension-api is unavailable after Composer repair.');
            }
        }

        $exclusion = $this->addWorktreeExclusions($slug, $base);
        if (null !== ($interrupt = $control?->interrupted('Interrupted after IDEA exclusion update.'))) {
            $this->cleanupPartialWorktree($codeRoot, $worktree, $slug, $base);

            return $this->interruptResult($interrupt);
        }

        $extensionsVendorInstalled = $this->installExtensionsVendor($worktree, $control);
        if ($extensionsVendorInstalled instanceof ExecResultDTO) {
            $this->cleanupPartialWorktree($codeRoot, $worktree, $slug, $base);

            return $extensionsVendorInstalled;
        }

        $ideaSetup = WorktreeIdeaSetup::ensure($codeRoot, $worktree);
        $ideOpenNote = JetBrainsMcpClient::openWorktreeProject($codeRoot, $worktree);

        return new WorktreeCreateResult(
            branch: $branch,
            worktree: $worktree,
            output: trim('' !== $result->stdout ? $result->stdout : $result->stderr),
            veraCopied: $veraCopied,
            vendorCopied: $vendorCopied,
            extensionsVendorInstalled: $extensionsVendorInstalled,
            ideaExclusionsUpdated: $exclusion['updated'],
            ideaNote: $exclusion['note'] ?? null,
            ideaSetupNote: $ideaSetup['note'] ?? null,
            ideOpenNote: $ideOpenNote,
        );
    }

    /**
     * @param array{cleanupWorktree: bool, deleteBranch: bool, requireCleanMain: bool, cleanupStaleIndexEntries: bool} $options
     *
     * @return list<string>|ExecResultDTO
     */
    public function mergeTaskBranch(string $codeRoot, TaskInfo $task, array $options, ?InvocationControl $control = null): array|ExecResultDTO
    {
        $branch = $task->branch;
        $worktree = $task->worktree;
        if (null === $branch || '' === $branch || null === $worktree || '' === $worktree) {
            return ['No Branch/Worktree metadata found; moved task without git merge.'];
        }

        $notes = [];
        if ($options['requireCleanMain']) {
            $mainStatus = $this->git->gitOk(['status', '--porcelain'], $codeRoot, 120.0, $control);
            if ($mainStatus->cancelled || $mainStatus->timedOut) {
                return $mainStatus;
            }
            if ('' !== trim($mainStatus->stdout) && $options['cleanupStaleIndexEntries']) {
                $stalePaths = self::staleAddedDeletedPaths($mainStatus->stdout);
                if ([] !== $stalePaths) {
                    $reset = $this->git->gitOk(array_merge(['reset', 'HEAD', '--'], $stalePaths), $codeRoot, 120.0, $control);
                    if ($reset->cancelled || $reset->timedOut) {
                        return $reset;
                    }
                    $notes[] = 'Reset stale staged entries: '.implode(', ', $stalePaths).'.';
                    $mainStatus = $this->git->gitOk(['status', '--porcelain'], $codeRoot, 120.0, $control);
                    if ($mainStatus->cancelled || $mainStatus->timedOut) {
                        return $mainStatus;
                    }
                }
            }
            if ('' !== trim($mainStatus->stdout)) {
                throw new \RuntimeException(self::formatDirtyIntegrationCheckoutMessage($branch, $mainStatus->stdout));
            }
        }

        if (null !== ($interrupt = $control?->interrupted('Interrupted before worktree status check.'))) {
            return $this->interruptResult($interrupt);
        }

        $wtStatus = $this->git->gitOk(['status', '--porcelain'], $worktree, 120.0, $control);
        if ($wtStatus->cancelled || $wtStatus->timedOut) {
            return $wtStatus;
        }
        if ('' !== trim($wtStatus->stdout)) {
            throw new \RuntimeException("Worktree has uncommitted changes; commit them before moving to DONE.\n{$worktree}\n{$wtStatus->stdout}");
        }

        // Close IDE project only after dirty-worktree preflight and only when cleanup will remove it.
        $closedForCleanup = false;
        if ($options['cleanupWorktree'] && is_dir($worktree)) {
            $notes[] = JetBrainsMcpClient::closeWorktreeProject($codeRoot, $worktree);
            $closedForCleanup = true;
        }

        $merge = $this->git->git(['merge', '--no-ff', '--no-edit', $branch], $codeRoot, 120.0, $control);
        if ($merge->cancelled || $merge->timedOut) {
            if ($closedForCleanup && is_dir($worktree)) {
                $notes[] = JetBrainsMcpClient::openWorktreeProject($codeRoot, $worktree);
                $notes[] = 'Reopened JetBrains project after interrupted merge for '.$worktree.'.';
            }

            return $merge;
        }
        if (0 !== $merge->exitCode) {
            if ($closedForCleanup && is_dir($worktree)) {
                $notes[] = JetBrainsMcpClient::openWorktreeProject($codeRoot, $worktree);
                $notes[] = 'Reopened JetBrains project after failed merge for '.$worktree.'.';
            }
            $conflicts = $this->git->git(['diff', '--name-only', '--diff-filter=U'], $codeRoot, 120.0, $control);
            if ($conflicts->cancelled || $conflicts->timedOut) {
                return $conflicts;
            }
            throw new \RuntimeException("Merge of {$branch} failed. Resolve conflicts in integration checkout, then retry move_task.\nConflicts:\n".('' !== trim($conflicts->stdout) ? $conflicts->stdout : '(none reported)')."\n\n".trim('' !== $merge->stderr ? $merge->stderr : $merge->stdout));
        }

        $notes[] = 'Merged '.$branch.' into integration checkout.';
        $notes[] = trim('' !== $merge->stdout ? $merge->stdout : $merge->stderr);

        if ($options['cleanupWorktree']) {
            // Shared non-forced cleanup: remove worktree first, then IDEA exclusions only
            // after a successful remove so a dirty/failed worktree keeps its markers.
            $cleanup = $this->cleanupWorktreeAndIdeaExclusions($codeRoot, $task, failClosed: false, control: $control);
            if ($cleanup instanceof ExecResultDTO) {
                if ($closedForCleanup && is_dir($worktree)) {
                    $notes[] = JetBrainsMcpClient::openWorktreeProject($codeRoot, $worktree);
                    $notes[] = 'Reopened JetBrains project after interrupted worktree cleanup for '.$worktree.'.';
                }

                return $cleanup;
            }
            array_push($notes, ...$cleanup);
            if ($closedForCleanup && is_dir($worktree)) {
                $notes[] = JetBrainsMcpClient::openWorktreeProject($codeRoot, $worktree);
                $notes[] = 'Reopened JetBrains project after failed worktree cleanup for '.$worktree.'.';
            }
        }

        if ($options['deleteBranch']) {
            $del = $this->git->git(['branch', '-d', $branch], $codeRoot, 120.0, $control);
            if ($del->cancelled || $del->timedOut) {
                return $del;
            }
            $notes[] = 0 === $del->exitCode
                ? 'Deleted branch '.$branch.'.'
                : 'Branch deletion failed: '.trim('' !== $del->stderr ? $del->stderr : $del->stdout);
        }

        $pull = $this->git->git(['pull'], $codeRoot, 120.0, $control);
        if ($pull->cancelled || $pull->timedOut) {
            return $pull;
        }
        if (0 === $pull->exitCode) {
            $notes[] = 'Pulled integration checkout: '.trim('' !== $pull->stdout ? $pull->stdout : $pull->stderr).'.';
        } else {
            $notes[] = 'Pull warning: '.trim('' !== $pull->stderr ? $pull->stderr : $pull->stdout);
        }

        return $notes;
    }

    /**
     * Cancel/cleanup path: remove a task worktree without merge/pull/branch deletion.
     *
     * Fail closed: if Worktree metadata points at a directory and safe
     * `git worktree remove` fails, throw without removing IDEA exclusions.
     * Leave the git branch intact. Historical Branch/Worktree metadata is left
     * for the caller to preserve in the task Markdown.
     *
     * @return list<string>|ExecResultDTO
     */
    public function removeTaskWorktreeSafely(string $codeRoot, TaskInfo $task, ?InvocationControl $control = null): array|ExecResultDTO
    {
        $worktree = $task->worktree;
        if (null === $worktree || '' === $worktree) {
            return ['No Worktree metadata; cancelled without git worktree cleanup.'];
        }

        if (!is_dir($worktree)) {
            // Directory already gone — still try IDEA exclusion cleanup so markers
            // do not linger after an external/manual worktree removal.
            $slug = preg_replace('/\.md$/', '', $task->file) ?? $task->file;
            $base = \dirname($worktree);
            $notes = ['Worktree path missing ('.$worktree.'); skipping git worktree remove.'];
            $exclusion = $this->removeWorktreeExclusions($slug, $base);
            if (($exclusion['note'] ?? null) !== null) {
                $notes[] = $exclusion['note'];
            }
            if ($exclusion['updated']) {
                $notes[] = 'Removed IDEA exclusions for worktree '.$worktree.'.';
            }

            return $notes;
        }

        // Fail-closed: dirty worktree must throw before close so an active dirty
        // cancellation does not leave the IDE project closed.
        $wtStatus = $this->git->gitOk(['status', '--porcelain'], $worktree, 120.0, $control);
        if ($wtStatus->cancelled || $wtStatus->timedOut) {
            return $wtStatus;
        }
        if ('' !== trim($wtStatus->stdout)) {
            throw new \RuntimeException('Safe worktree cleanup failed; leaving task unmoved and IDEA project open.'."\n".'Worktree has uncommitted changes.'."\n".'Worktree: '.$worktree."\n".$wtStatus->stdout);
        }

        $notes = [];
        $notes[] = JetBrainsMcpClient::closeWorktreeProject($codeRoot, $worktree);
        try {
            $cleanup = $this->cleanupWorktreeAndIdeaExclusions($codeRoot, $task, failClosed: true, control: $control);
            if ($cleanup instanceof ExecResultDTO) {
                // Cleanup interrupted before/without remove: reopen best-effort.
                $notes[] = JetBrainsMcpClient::openWorktreeProject($codeRoot, $worktree);
                $notes[] = 'Reopened JetBrains project after interrupted cancellation cleanup for '.$worktree.'.';

                return $cleanup;
            }
            array_push($notes, ...$cleanup);

            return $notes;
        } catch (\Throwable $e) {
            // Fail-closed remove leaves the worktree; reopen best-effort so IDE stays usable.
            $notes[] = JetBrainsMcpClient::openWorktreeProject($codeRoot, $worktree);
            $notes[] = 'Reopened JetBrains project after failed cancellation cleanup for '.$worktree.'.';
            throw new \RuntimeException($e->getMessage()."\n".implode("\n", $notes), 0, $e);
        }
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

    /**
     * Shared worktree + IDEA exclusion cleanup used by DONE merge and CANCELLED.
     *
     * Ordering invariant: never strip IDEA exclusions until `git worktree remove`
     * succeeds. Never force-delete or recursively delete a dirty worktree.
     *
     * @return list<string>|ExecResultDTO
     */
    private function cleanupWorktreeAndIdeaExclusions(
        string $codeRoot,
        TaskInfo $task,
        bool $failClosed,
        ?InvocationControl $control = null,
    ): array|ExecResultDTO {
        $worktree = $task->worktree;
        if (null === $worktree || '' === $worktree) {
            return [];
        }

        $slug = preg_replace('/\.md$/', '', $task->file) ?? $task->file;
        $base = \dirname($worktree);
        $notes = [];

        // Non-forced remove only. Dirty trees must fail rather than be deleted.
        $remove = $this->git->git(['worktree', 'remove', $worktree], $codeRoot, 120.0, $control);
        if ($remove->cancelled || $remove->timedOut) {
            return $remove;
        }
        if (0 !== $remove->exitCode) {
            $detail = trim('' !== $remove->stderr ? $remove->stderr : $remove->stdout);
            if ($failClosed) {
                throw new \RuntimeException('Safe worktree cleanup failed; leaving task unmoved and IDEA exclusions intact.'."\nWorktree: ".$worktree."\n".('' !== $detail ? $detail : '(no git output)'));
            }

            $notes[] = 'Worktree cleanup failed: '.('' !== $detail ? $detail : '(no git output)');
            $notes[] = 'IDEA exclusions preserved for '.$worktree.' because worktree removal failed.';

            return $notes;
        }

        $notes[] = 'Removed worktree '.$worktree.'.';
        $exclusion = $this->removeWorktreeExclusions($slug, $base);
        if (($exclusion['note'] ?? null) !== null) {
            $notes[] = $exclusion['note'];
        }
        if ($exclusion['updated']) {
            $notes[] = 'Removed IDEA exclusions for worktree '.$worktree.'.';
        }

        return $notes;
    }

    private function resolvePath(string $codeRoot, string $worktreeBase): string
    {
        if ('' !== $worktreeBase && '/' === $worktreeBase[0]) {
            return $worktreeBase;
        }

        return rtrim($codeRoot, '/').'/'.ltrim($worktreeBase, '/');
    }

    /**
     * @return bool|ExecResultDTO true when copied, false when skipped/nonfatal failure,
     *                            ExecResultDTO when cancelled/timed out (caller must cleanup)
     */
    private function copyTreeIfMissing(string $source, string $dest, ?InvocationControl $control = null): bool|ExecResultDTO
    {
        if (!is_dir($source) || is_dir($dest)) {
            return false;
        }
        try {
            if (!is_dir($dest)) {
                mkdir($dest, 0o755, true);
            }

            $result = $this->exec->exec(
                'cp',
                ['-a', $source.'/.', $dest.'/'],
                new ExecOptionsDTO(
                    timeout: $control?->remainingTimeoutSeconds(),
                    cancellationToken: $control?->cancellationToken,
                ),
            );
            if ($result->cancelled || $result->timedOut) {
                return $result;
            }
            if (0 !== $result->exitCode) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            // Non-fatal: vendor/.vera are developer-convenience copies; the worker can run
            // composer install or fall back to absolute-path reads. Do not hard-fail here.
            return false;
        }
    }

    /** @phpstan-impure */
    private function hasUsableExtensionApiLink(string $worktree): bool
    {
        $package = $worktree.'/vendor/ineersa/hatfield-extension-api';

        return is_dir($package) && false !== realpath($package);
    }

    private function repairRootVendor(string $worktree, ?InvocationControl $control): bool|ExecResultDTO
    {
        if (!is_file($worktree.'/composer.json')) {
            return false;
        }
        $result = $this->exec->exec('composer', ['install', '--no-interaction', '--no-progress'], new ExecOptionsDTO(
            cwd: $worktree,
            timeout: $control?->remainingTimeoutSeconds(120.0) ?? 120.0,
            cancellationToken: $control?->cancellationToken,
        ));
        if ($result->cancelled || $result->timedOut) {
            return $result;
        }

        return 0 === $result->exitCode;
    }

    private function installExtensionsVendor(string $worktree, ?InvocationControl $control = null): bool|ExecResultDTO
    {
        $extensionsDir = $worktree.'/.hatfield/extensions';
        if (!is_dir($extensionsDir) || !is_file($extensionsDir.'/composer.json')) {
            return false;
        }
        try {
            $result = $this->exec->exec(
                'composer',
                ['install', '-d', $extensionsDir, '--no-interaction', '--no-progress'],
                new ExecOptionsDTO(
                    cwd: $worktree,
                    timeout: $control?->remainingTimeoutSeconds(120.0) ?? 120.0,
                    cancellationToken: $control?->cancellationToken,
                ),
            );
            if ($result->cancelled || $result->timedOut) {
                return $result;
            }
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

    /**
     * Best-effort cleanup of a worktree created during this interrupted transition.
     * Uses existing non-forced git worktree remove + IDEA exclusion cleanup ordering.
     * IDEA exclusions are removed only when git worktree remove succeeds (exit 0),
     * matching cleanupWorktreeAndIdeaExclusions fail-closed ordering.
     */
    private function cleanupPartialWorktree(string $codeRoot, string $worktree, string $slug, string $base): void
    {
        if (!is_dir($worktree)) {
            // Directory already gone — still clear IDEA markers that point at it.
            $this->removeWorktreeExclusions($slug, $base);

            return;
        }

        $remove = $this->git->git(['worktree', 'remove', $worktree], $codeRoot);
        if (0 === $remove->exitCode) {
            $this->removeWorktreeExclusions($slug, $base);
        }
    }

    /**
     * @param array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message?: string} $interrupt
     */
    private function interruptResult(array $interrupt): ExecResultDTO
    {
        return new ExecResultDTO(
            stdout: '',
            stderr: (string) ($interrupt['message'] ?? 'Interrupted.'),
            exitCode: -1,
            timedOut: true === ($interrupt['timed_out'] ?? false),
            cancelled: true === ($interrupt['cancelled'] ?? false),
        );
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
