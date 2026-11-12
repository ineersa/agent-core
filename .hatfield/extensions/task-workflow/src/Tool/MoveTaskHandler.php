<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\DTO\GitHubTaskDTO;
use Ineersa\HatfieldExt\TaskWorkflow\DTO\PullRequestDTO;
use Ineersa\HatfieldExt\TaskWorkflow\DTO\TaskInputDTO;
use Ineersa\HatfieldExt\TaskWorkflow\DTO\WorkspaceBindingDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\TaskTransitionException;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Pr\PrManager;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\GitHubTaskStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Validation\TaskInputMapper;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\HookRunner;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorkspaceStateStore;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;
use Symfony\Component\Filesystem\Path;

final readonly class MoveTaskHandler implements ContextualExtensionToolHandlerInterface
{
    public function __construct(
        private GitHubTaskStore $store,
        private GitExecutor $git,
        private WorktreeManager $worktrees,
        private PrManager $pr,
        private HookRunner $hooks,
        private WorkspaceStateStore $states,
        private string $primaryRoot,
        private TaskWorkflowSettings $settings,
    ) {
    }

    /** @param array<string, mixed> $arguments */
    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $input = TaskInputMapper::map($arguments, 'move');
        $control = InvocationControl::fromContext($context);
        if (null !== ($interrupted = $control->interrupted())) {
            return ToolResult::structured($interrupted);
        }
        foreach (['setup', 'validate', 'cleanup'] as $hook) {
            if (!isset($this->settings->hooks[$hook]['script'], $this->settings->hooks[$hook]['timeout_seconds'])) {
                throw new TaskTransitionException('Configure all three project hooks before task transitions.');
            }
        }
        $to = TaskStatusEnum::fromMixed($input->to);
        $task = $this->store->read($input->task, $control);
        $lock = $this->states->lock($this->settings->repository, $task->number);
        $result = $lock->withLock(function () use ($task, $to, $input, $control): string {
            // A local lock is cooperative, not a distributed GitHub lock. Always read
            // again under it and detect conflicting remote writes before publication.
            $current = $this->store->read($task->number, $control);
            if (null === $current->status) {
                if (!$input->adopt) {
                    throw new TaskTransitionException('Issue is unmanaged. Explicit adopt=true is required, including historical closed issues.');
                }
                $current = $this->store->adopt($current, $control);
            }
            if (null !== $input->from && $current->status->value !== $input->from) {
                throw new TaskTransitionException('Workflow differs from the expected source status.');
            }
            $state = $this->states->load($this->settings->repository, $current->number);
            $pending = $state['pending_operation'] ?? null;
            if (null !== $pending && !\in_array($pending, ['start', 'review'], true)) {
                $requested = TaskStatusEnum::DONE === $to ? 'done' : (TaskStatusEnum::CANCELLED === $to ? 'cancel' : '');
                if ($requested !== $pending) {
                    // A verified external merge can change cancellation into DONE,
                    // but cannot silently undo partial teardown into implementation.
                    if ('cancel' !== $pending || 'done' !== $requested) {
                        throw new TaskTransitionException('Pending '.$pending.' finalization must be resumed before another transition.');
                    }
                }
            }
            $allowed = match ($to) {
                TaskStatusEnum::IN_PROGRESS => \in_array($current->status, [TaskStatusEnum::TODO, TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::CODE_REVIEW], true),
                TaskStatusEnum::CODE_REVIEW => TaskStatusEnum::IN_PROGRESS === $current->status,
                TaskStatusEnum::DONE => \in_array($current->status, [TaskStatusEnum::CODE_REVIEW, TaskStatusEnum::DONE], true) || 'cancel' === $pending,
                TaskStatusEnum::CANCELLED => \in_array($current->status, [TaskStatusEnum::TODO, TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::CODE_REVIEW, TaskStatusEnum::CANCELLED], true),
                default => false,
            };
            if (!$allowed) {
                throw new TaskTransitionException('Unsupported Workflow transition.');
            }

            return match ($to) {
                TaskStatusEnum::IN_PROGRESS => $this->start($current, $state, $input, $control),
                TaskStatusEnum::CODE_REVIEW => $this->review($current, $state, $input, $control),
                default => $this->finish($current, $state, $input, $to, $control),
            };
        }, $control->cancellationToken, $control->deadlineNs, $control->timeoutSeconds);

        return \is_array($result) ? ToolResult::structured($result) : $result;
    }

    /** @param array<string,mixed>|null $state */
    private function start(GitHubTaskDTO $task, ?array $state, TaskInputDTO $input, InvocationControl $control): string
    {
        $pr = $this->resolvePr($task, $state, $input, $control);
        if (null !== $pr && ('MERGED' === $pr->state || null !== $pr->mergedAt)) {
            throw new TaskTransitionException('PR is already merged. Use DONE finalization instead of restarting implementation.');
        }
        $branch = $pr->headBranch ?? $state['branch'] ?? null;
        $base = $pr->baseBranch ?? $this->settings->baseBranch;
        if (null === $branch) {
            $branches = array_values(array_filter($this->store->relationships($task->number, $control), static fn ($link): bool => 'branch' === $link->type));
            if (null !== $input->branch) {
                $branches = array_values(array_filter($branches, static fn ($link): bool => $link->branch === $input->branch));
            }
            if (\count($branches) > 1) {
                throw new TaskTransitionException('Multiple linked branches; provide an explicit branch.');
            }
            if ([] !== $branches) {
                $branch = $branches[0]->branch;
                $expectedRepository = implode('/', \array_slice(explode('/', $this->settings->repository), -2));
                if ($branches[0]->repository !== $expectedRepository) {
                    throw new TaskTransitionException('Linked branch belongs to another repository.');
                }
            } else {
                if (TaskStatusEnum::TODO !== $task->status) {
                    throw new TaskTransitionException('Active task has no recoverable native branch or PR; do not fabricate a resume from base.');
                }
                $branch = 'task/issue-'.$task->number;
                $this->pr->develop($task->number, $branch, $base, $control);
            }
        }
        $this->assertRemote($control);
        $this->gitOk(['check-ref-format', '--branch', $branch], $this->primaryRoot, $control);
        $remoteRef = 'refs/remotes/'.$this->settings->remote.'/'.$branch;
        $this->gitOk(['fetch', '--no-tags', $this->settings->remote, 'refs/heads/'.$branch.':'.$remoteRef], $this->primaryRoot, $control);
        if (null === $state) {
            $root = Path::makeAbsolute($this->settings->worktreeRoot, $this->primaryRoot);
            $path = rtrim($root, '/').'/'.substr(hash('sha256', $this->settings->repository), 0, 12).'-issue-'.$task->number;
            // A matching local branch name is not proof we own its work. A newly
            // adopted binding may reuse it only at the exact fetched native head.
            if ($this->git->branchExists($this->primaryRoot, $branch, $control)) {
                $local = trim($this->gitOk(['rev-parse', 'refs/heads/'.$branch], $this->primaryRoot, $control)->stdout);
                $remote = trim($this->gitOk(['rev-parse', $remoteRef], $this->primaryRoot, $control)->stdout);
                if ($local !== $remote) {
                    throw new TaskTransitionException('Unowned existing local branch differs from the native remote branch; preserve/reconcile it explicitly.');
                }
            }
            $state = $this->states->initialize($this->settings->repository, $task->number, $task->url, $branch, $path);
        }
        if ($state['branch'] !== $branch) {
            throw new TaskTransitionException('Retained workspace branch conflicts with the native task branch.');
        }
        $existed = is_dir($state['worktree']);
        $binding = $this->worktrees->create($this->primaryRoot, $branch, $state['worktree'], $remoteRef, $control);
        if (!$existed) {
            $state['setup_complete'] = false;
        }
        if (true !== ($state['setup_complete'] ?? false)) {
            $state['setup_attempted'] = true;
            $state['pending_operation'] = 'start';
            $this->save($task, $state);
            $result = $this->runHook('setup', 'start', $task, $state, $base, $control);
            if (!$this->hookSucceeded($result)) {
                return $this->hookFailure('setup', $task, $state, $result);
            }
            $state['setup_complete'] = true;
            $this->save($task, $state);
        }
        $published = $this->store->publish($this->fresh($task, $control), TaskStatusEnum::IN_PROGRESS, $control);
        $state['pending_operation'] = null;
        $this->save($task, $state);

        return ToolResult::text('Workspace ready; Workflow IN-PROGRESS.', ['task' => $published, 'branch' => $binding->branch, 'worktree' => $binding->worktree, 'setup' => 'passed']);
    }

    /** @param array<string,mixed>|null $state */
    private function review(GitHubTaskDTO $task, ?array $state, TaskInputDTO $input, InvocationControl $control): string
    {
        if (null === $state || true !== ($state['setup_complete'] ?? false)) {
            throw new TaskTransitionException('No complete owned workspace on this machine. Resume IN-PROGRESS first.');
        }
        $this->assertWorkspace($state, $control);
        $sha = $this->worktrees->head($state['worktree'], $control);
        $pr = $this->resolvePr($task, $state, $input, $control);
        if (null !== $pr && 'OPEN' !== $pr->state) {
            throw new TaskTransitionException('Associated PR is closed or merged; reconcile it before submitting.');
        }
        $base = $pr->baseBranch ?? $this->settings->baseBranch;
        $result = $this->runHook('validate', 'review', $task, $state, $base, $control);
        if (!$this->hookSucceeded($result)) {
            return $this->hookFailure('validate', $task, $state, $result);
        }
        $this->assertWorkspace($state, $control);
        if ($sha !== $this->worktrees->head($state['worktree'], $control)) {
            throw new TaskTransitionException('Validation changed HEAD. Commit and validate the intended revision again.');
        }
        $this->assertRemote($control);
        $this->gitOk(['push', $this->settings->remote, $sha.':refs/heads/'.$state['branch']], $state['worktree'], $control);
        $remote = $this->gitOk(['ls-remote', '--exit-code', $this->settings->remote, 'refs/heads/'.$state['branch']], $this->primaryRoot, $control);
        if ($sha !== strtok(trim($remote->stdout), "\t ")) {
            throw new TaskTransitionException('Remote branch no longer equals the validated revision.');
        }
        if (null === $pr) {
            $matches = $this->pr->find($state['branch'], $base, $task->number, $control);
            if (\count($matches) > 1) {
                throw new TaskTransitionException('Multiple matching PRs; select one explicitly.');
            }
            $pr = $matches[0] ?? $this->pr->create($task->number, $state['branch'], $base, $input->prTitle ?? $task->title, $input->prBody ?? $task->body, $control);
        }
        $this->pr->assertIdentity($pr, $state['branch'], $base, $task->number);
        $pr = $this->pr->view($pr->number, $control);
        if ('OPEN' !== $pr->state || $sha !== $pr->headSha) {
            throw new TaskTransitionException('PR is not open at the validated head.');
        }
        $state['submitted_sha'] = $sha;
        $state['submitted_base'] = $base;
        $state['pr_url'] = $pr->url;
        $state['pending_operation'] = 'review';
        $this->save($task, $state);
        $published = $this->store->publish($this->fresh($task, $control), TaskStatusEnum::CODE_REVIEW, $control);
        $state['pending_operation'] = null;
        $this->save($task, $state);

        return ToolResult::text('Validation passed; PR ready for review.', ['task' => $published, 'pr' => $pr->url, 'validated_sha' => $sha, 'validation' => ['exit_code' => 0, 'outcome' => 'passed']]);
    }

    /** @param array<string,mixed>|null $state */
    private function finish(GitHubTaskDTO $task, ?array $state, TaskInputDTO $input, TaskStatusEnum $to, InvocationControl $control): string
    {
        $done = TaskStatusEnum::DONE === $to;
        $reason = $done ? 'done' : 'cancel';
        $pr = $this->resolvePr($task, $state, $input, $control);
        if ($done && null === $pr) {
            throw new TaskTransitionException('DONE requires a verified task PR, including on repeated finalization.');
        }
        if (!$done && null !== $pr && ('MERGED' === $pr->state || null !== $pr->mergedAt)) {
            throw new TaskTransitionException('Task PR merged; cancel is forbidden. Use explicit DONE reconciliation.');
        }
        if (null !== $state && is_dir($state['worktree'])) {
            $this->assertWorkspace($state, $control);
            if ($done && $this->worktrees->head($state['worktree'], $control) !== $pr->headSha) {
                throw new TaskTransitionException('Local committed work is not the confirmed PR head; preserve it before completion.');
            }
        }
        if (null === $state) {
            // Even never-started cancellation gets cleanup, but no guessed path.
            $state = $this->states->initialize($this->settings->repository, $task->number, $task->url, $pr->headBranch ?? '', '');
        }
        if ($done) {
            $accepted = $input->acceptedHead ?? $state['submitted_sha'] ?? null;
            if (null === $accepted) {
                throw new TaskTransitionException('No local submitted revision. Inspect actual PR head/review state and provide acceptedHead explicitly.');
            }
            if ($accepted !== $pr->headSha) {
                throw new TaskTransitionException('Accepted/submitted SHA differs from the current PR head. Review before retrying.');
            }
            $state['pending_operation'] = 'done';
            $state['submitted_sha'] = $accepted;
            $state['pr_url'] = $pr->url;
            $this->save($task, $state);
            $pr = $this->pr->merge($pr, $accepted, $control);
            if ('MERGED' !== $pr->state || null === $pr->mergedAt) {
                return ToolResult::text('Merge is pending (queue or auto-merge); workspace retained. Retry DONE after confirmed merge.', ['pending' => true, 'pr' => $pr->url, 'worktree' => $state['worktree']]);
            }
            $this->assertRemote($control);
            $this->worktrees->fastForwardBase($this->primaryRoot, $pr->baseBranch, $this->settings->remote, $control);
        } elseif (null !== $pr) {
            $state['pr_url'] = $pr->url;
            $state['pending_operation'] = 'cancel';
            $this->save($task, $state);
            $pr = $this->pr->close($pr, $control);
        }
        $previousReason = $state['cleanup_reason'] ?? null;
        $cleanupComplete = true === ($state['cleanup_complete'] ?? false) && $previousReason === $reason;
        $state = $this->states->beginCleanup($this->settings->repository, $task->number, $reason);
        if (!$cleanupComplete) {
            $result = $this->runHook('cleanup', $reason, $task, $state, $pr->baseBranch ?? $this->settings->baseBranch, $control);
            if (!$this->hookSucceeded($result)) {
                return $this->hookFailure('cleanup', $task, $state, $result);
            }
            $state['cleanup_complete'] = true;
            $state['cleanup_reason'] = $reason;
            $this->save($task, $state);
        }
        if ('' !== $state['worktree']) {
            $this->worktrees->remove($this->primaryRoot, new WorkspaceBindingDTO($state['branch'], $state['worktree']), $done ? $pr->headSha : null, $control);
            if (!$this->worktrees->verifyRemoved($this->primaryRoot, $state['worktree'], $control)) {
                throw new TaskTransitionException('Worktree deletion postconditions failed. Retained finalization must be retried.');
            }
        }
        if ($done) {
            // Validate the integrated checkout after removal, not the pre-merge task
            // tree. Failure retains recovery evidence and must not publish DONE.
            $result = $this->runHook('validate', 'done', $task, $state, $pr->baseBranch, $control);
            if (!$this->hookSucceeded($result)) {
                return $this->hookFailure('validate', $task, $state, $result);
            }
        }
        // PR state can change during cleanup. Never publish cancelled after a merge
        // or reopened PR; keep deletion evidence for explicit reconciliation.
        if (null !== $pr) {
            $currentPr = $this->pr->view($pr->number, $control);
            if ((!$done && ('CLOSED' !== $currentPr->state || null !== $currentPr->mergedAt)) || ($done && ('MERGED' !== $currentPr->state || $currentPr->headSha !== $pr->headSha || null === $currentPr->mergedAt))) {
                throw new TaskTransitionException('PR changed during finalization. Cleanup evidence retained; reconcile the remote state.');
            }
        }
        $closed = $this->store->close($this->fresh($task, $control), $done ? 'completed' : 'not_planned', $control);
        $published = $this->store->publish($closed, $to, $control);
        $this->states->forget($this->settings->repository, $task->number);

        return ToolResult::text('Finalization confirmed: cleanup passed'.($done ? ', merged-base validation passed' : '').', owned workspace absent, Workflow '.$to->value.'.', ['task' => $published, 'cleanup' => 'passed', 'worktree_removed' => true, 'execution_checkout' => $this->primaryRoot]);
    }

    /** @param array<string,mixed>|null $state */
    private function resolvePr(GitHubTaskDTO $task, ?array $state, TaskInputDTO $input, InvocationControl $control): ?PullRequestDTO
    {
        $links = array_values(array_filter($this->store->relationships($task->number, $control), static fn ($link): bool => 'pull_request' === $link->type));
        $reference = $input->pr ?? $state['pr_url'] ?? null;
        if (null !== $reference) {
            $links = array_values(array_filter($links, static fn ($link): bool => $link->url === $reference || (string) $link->number === (string) $reference));
            if (1 !== \count($links)) {
                throw new TaskTransitionException('Selected PR has no unique native relationship to this issue.');
            }
        }
        if (\count($links) > 1) {
            throw new TaskTransitionException('Multiple native linked PRs; select one by explicit pr number or URL.');
        }
        if ([] === $links) {
            return null;
        }
        $pr = $this->pr->view($links[0]->url, $control);
        $this->pr->assertIdentity($pr, $state['branch'] ?? $pr->headBranch, $state['submitted_base'] ?? $pr->baseBranch, $task->number);

        return $pr;
    }

    private function fresh(GitHubTaskDTO $expected, InvocationControl $control): GitHubTaskDTO
    {
        $current = $this->store->read($expected->number, $control);
        // Native closure/timeline changes are expected during branch/PR operations;
        // an incompatible Workflow or specification edit is not ours to overwrite.
        if ($current->status !== $expected->status || $current->title !== $expected->title || $current->body !== $expected->body) {
            throw new TaskTransitionException('Issue specification or Workflow changed concurrently; inspect it before retrying.');
        }

        return $current;
    }

    /** @param array<string,mixed> $state */
    private function assertWorkspace(array $state, InvocationControl $control): void
    {
        $matches = array_values(array_filter($this->worktrees->inspect($this->primaryRoot, $control), static fn (array $entry): bool => $entry['path'] === $state['worktree'] && $entry['branch'] === $state['branch']));
        if (1 !== \count($matches) || !$this->worktrees->isClean($state['worktree'], $control)) {
            throw new TaskTransitionException('Expected owned workspace/branch is missing or contains valuable uncommitted changes.');
        }
    }

    private function assertRemote(InvocationControl $control): void
    {
        $url = trim($this->gitOk(['remote', 'get-url', $this->settings->remote], $this->primaryRoot, $control)->stdout);
        $parts = explode('/', $this->settings->repository);
        $repository = implode('/', \array_slice($parts, -2));
        $host = 3 === \count($parts) ? $parts[0] : 'github.com';
        $normalized = preg_replace('~\.git$~', '', $url);
        if (!\in_array($normalized, ['git@'.$host.':'.$repository, 'https://'.$host.'/'.$repository, 'ssh://git@'.$host.'/'.$repository], true)) {
            throw new TaskTransitionException('Configured Git remote does not match the explicit GitHub repository/host.');
        }
    }

    /** @param list<string> $args */
    private function gitOk(array $args, string $cwd, InvocationControl $control): ExecResultDTO
    {
        if (null !== $control->interrupted()) {
            throw new TaskTransitionException('Git operation interrupted before launch.');
        }
        $result = $this->git->git($args, $cwd, 120.0, $control);
        if (!$this->hookSucceeded($result)) {
            throw new TaskTransitionException('Git operation failed or interrupted: '.substr($result->stderr, 0, 3000));
        }

        return $result;
    }

    /** @param array<string,mixed> $state */
    private function save(GitHubTaskDTO $task, array $state): void
    {
        $this->states->save($this->settings->repository, $task->number, $state);
    }

    /** @param array<string,mixed> $state */
    private function runHook(string $name, string $reason, GitHubTaskDTO $task, array $state, string $base, InvocationControl $control): ExecResultDTO
    {
        $hook = $this->settings->hooks[$name] ?? null;
        if (null === $hook) {
            throw new TaskTransitionException('Required '.$name.' project hook is not configured.');
        }

        return $this->hooks->run($this->primaryRoot, $hook['script'], (float) $hook['timeout_seconds'], [
            'HATFIELD_PROJECT_ROOT' => $this->primaryRoot,
            'HATFIELD_TASK_REPOSITORY' => $this->settings->repository,
            'HATFIELD_TASK_NUMBER' => (string) $task->number,
            'HATFIELD_TASK_URL' => $task->url,
            'HATFIELD_TASK_BRANCH' => $state['branch'],
            'HATFIELD_TASK_BASE_BRANCH' => $base,
            'HATFIELD_TASK_WORKTREE' => $state['worktree'],
            'HATFIELD_TASK_WORKTREE_EXISTS' => is_dir($state['worktree']) ? '1' : '0',
            'HATFIELD_TASK_SETUP_ATTEMPTED' => true === ($state['setup_attempted'] ?? false) ? '1' : '0',
            'HATFIELD_TASK_REASON' => $reason,
            'HATFIELD_TASK_RESOURCE_ID' => $state['resource_id'],
            'HATFIELD_TASK_DATA_DIR' => $state['data_dir'],
        ], $control);
    }

    private function hookSucceeded(ExecResultDTO $result): bool
    {
        return 0 === $result->exitCode && !$result->timedOut && !$result->cancelled;
    }

    /** @param array<string,mixed> $state */
    private function hookFailure(string $name, GitHubTaskDTO $task, array $state, ExecResultDTO $result): string
    {
        return ToolResult::text($name.' hook did not succeed. Workflow unchanged; preserve/repair resources and retry the same transition.', [
            'task' => $task, 'pending' => true, 'worktree' => $state['worktree'], 'hook' => $name,
            'exit_code' => $result->exitCode, 'timed_out' => $result->timedOut, 'cancelled' => $result->cancelled,
            'diagnostics' => "stderr:\n".substr($result->stderr, -2000)."\nstdout:\n".substr($result->stdout, -2000),
        ]);
    }
}
