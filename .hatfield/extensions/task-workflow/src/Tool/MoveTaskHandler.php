<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Pr\PrManager;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardLock;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskMarkdown;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;

final readonly class MoveTaskHandler implements ExtensionToolHandlerInterface
{
    public function __construct(
        private TaskBoardStore $store,
        private GitExecutor $git,
        private WorktreeManager $worktrees,
        private PrManager $pr,
        private ExecInterface $exec,
        private TaskWorkflowSettings $config,
        private string $codeRoot,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{content: list<array{type: string, text: string}>, details: array<string, mixed>}
     */
    public function __invoke(array $arguments): array
    {
        $taskQuery = $arguments['task'] ?? null;
        if (!\is_string($taskQuery) || '' === $taskQuery) {
            throw new \InvalidArgumentException('task is required');
        }
        if (!isset($arguments['to']) || !\is_string($arguments['to'])) {
            throw new \InvalidArgumentException('to is required');
        }

        $taskRoot = $this->store->resolveTaskRoot();
        $this->store->ensureTaskDirs($taskRoot);
        $lock = new TaskBoardLock(TaskBoardLock::lockPathForRoot($taskRoot));

        return $lock->withLock(function () use ($taskRoot, $taskQuery, $arguments): array {
            $to = TaskStatusEnum::fromMixed($arguments['to']);
            $from = null;
            if (isset($arguments['from']) && \is_string($arguments['from']) && '' !== $arguments['from']) {
                $from = TaskStatusEnum::fromMixed($arguments['from']);
            }

            $task = $this->store->findTask($taskRoot, $taskQuery, $from);
            if ($task->status === $to) {
                return ToolResult::text('Task already in '.$to->value.': '.$task->status->value.'/'.$task->file, ['task' => $task]);
            }

            $text = file_get_contents($task->path);
            if (false === $text) {
                throw new \RuntimeException('Failed to read task file: '.$task->path);
            }

            $notes = ['Moved '.$task->status->value.' → '.$to->value.'.'];

            if (TaskStatusEnum::TODO === $task->status && TaskStatusEnum::IN_PROGRESS === $to) {
                $text = $this->transitionTodoToInProgress($text, $task, $arguments, $notes);
            } elseif (TaskStatusEnum::IN_PROGRESS === $task->status && TaskStatusEnum::CODE_REVIEW === $to) {
                $text = $this->transitionInProgressToCodeReview($text, $task, $arguments, $notes);
            } elseif (TaskStatusEnum::DONE === $to) {
                $text = $this->transitionToDone($text, $task, $arguments, $notes);
            } else {
                $text = TaskMarkdown::updateField($text, 'Status', $to->value);
            }

            if (isset($arguments['forkRun']) && \is_string($arguments['forkRun']) && '' !== $arguments['forkRun']) {
                $text = TaskMarkdown::updateField($text, 'Fork run', $arguments['forkRun']);
            }
            if (isset($arguments['validation']) && \is_array($arguments['validation'])) {
                $vals = array_values(array_filter($arguments['validation'], is_string(...)));
                if ([] !== $vals) {
                    $notes[] = 'Validation: '.implode('; ', $vals);
                }
            }
            if (isset($arguments['summary']) && \is_string($arguments['summary']) && '' !== $arguments['summary']) {
                $notes[] = 'Summary: '.$arguments['summary'];
            }

            $text = TaskMarkdown::appendLog($text, $notes);
            $target = $this->store->moveFileWithMetadata($task, $to, $text, $taskRoot);

            // NOTE: No git commit to code repo. Task board is external.

            return ToolResult::text(
                implode("\n", array_merge(['Moved task to '.$this->store->rel($taskRoot, $target).'.'], $notes)),
                ['from' => $task->status->value, 'to' => $to->value, 'path' => $target, 'notes' => $notes]
            );
        });
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $notes
     */
    private function transitionTodoToInProgress(string $text, \Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo $task, array $arguments, array &$notes): string
    {
        $mainStatus = $this->git->gitOk(['status', '--porcelain'], $this->codeRoot);
        if ('' !== trim($mainStatus->stdout)) {
            throw new \RuntimeException("Integration checkout is not clean; commit or stash changes before claiming a task.\n".$mainStatus->stdout);
        }

        $worktreeBase = isset($arguments['worktreeBase']) && \is_string($arguments['worktreeBase']) ? $arguments['worktreeBase'] : null;
        $wtResult = $this->worktrees->createWorktreeForTask($this->codeRoot, $task, $worktreeBase);

        $text = TaskMarkdown::updateField($text, 'Status', TaskStatusEnum::IN_PROGRESS->value);
        $text = TaskMarkdown::updateField($text, 'Branch', $wtResult->branch);
        $text = TaskMarkdown::updateField($text, 'Worktree', $wtResult->worktree);
        $text = TaskMarkdown::updateField($text, 'Started', (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM));
        if (isset($arguments['forkRun']) && \is_string($arguments['forkRun']) && '' !== $arguments['forkRun']) {
            $text = TaskMarkdown::updateField($text, 'Fork run', $arguments['forkRun']);
        }

        $notes[] = 'Created branch '.$wtResult->branch.'.';
        $notes[] = 'Created worktree '.$wtResult->worktree.'.';
        if ($wtResult->vendorCopied) {
            $notes[] = 'Copied vendor directory into '.$wtResult->worktree.'.';
        }
        if ($wtResult->veraCopied) {
            $notes[] = 'Copied .vera index into '.$wtResult->worktree.'.';
        }
        if ($wtResult->ideaExclusionsUpdated) {
            $notes[] = 'Updated parent IDEA worktree exclusions for '.$wtResult->worktree.'.';
        }
        if (null !== $wtResult->ideaNote && '' !== $wtResult->ideaNote) {
            $notes[] = $wtResult->ideaNote;
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $notes
     */
    private function transitionInProgressToCodeReview(string $text, \Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo $task, array $arguments, array &$notes): string
    {
        $branch = $task->branch;
        if (null === $branch || '' === $branch) {
            throw new \RuntimeException('Task has no Branch metadata. Was it moved to IN-PROGRESS via move_task?');
        }

        $worktree = $task->worktree;
        if (null === $worktree || '' === $worktree || !is_dir($worktree)) {
            throw new \RuntimeException("Task worktree is missing or does not exist. Cannot push without a worktree.\n".'Worktree: '.($worktree ?? '(not set)')."\n".'Claim the task with move_task(to="IN-PROGRESS") to create a worktree first.');
        }

        $wtStatus = $this->git->gitOk(['status', '--porcelain'], $worktree);
        if ('' !== trim($wtStatus->stdout)) {
            throw new \RuntimeException("Worktree has uncommitted changes; commit them before moving to CODE-REVIEW.\n{$worktree}\n{$wtStatus->stdout}");
        }

        $checkTimeout = $this->resolveCastorCheckTimeout($arguments);
        $notes[] = 'Running deterministic castor check in worktree (timeout '.$checkTimeout.'s)...';

        $checkStart = microtime(true);
        $checkResult = $this->exec->exec(
            'timeout',
            ['--kill-after=30s', (string) $checkTimeout.'s', 'env', 'LLM_MODE=true', 'castor', 'check'],
            new ExecOptionsDTO(cwd: $worktree, timeout: (float) ($checkTimeout + 45), env: ['LLM_MODE' => 'true'])
        );
        $checkDuration = microtime(true) - $checkStart;
        $checkKilled = 124 === $checkResult->exitCode || 137 === $checkResult->exitCode;

        if (0 !== $checkResult->exitCode) {
            $reason = $checkKilled
                ? 'timeout after '.$checkTimeout.'s'
                : 'exit code '.$checkResult->exitCode;
            $detail = trim('' !== $checkResult->stderr ? $checkResult->stderr : $checkResult->stdout);
            if ('' === $detail) {
                $detail = '(no output)';
            }
            throw new \RuntimeException('Castor check FAILED ('.$reason.') in the worktree. Fix the failures, re-validate with focused Castor commands, then move to CODE-REVIEW again.'."\n".'Worktree: '.$worktree."\n".'Output:'."\n".substr($detail, 0, 2000));
        }

        $notes[] = 'castor check passed ('.number_format($checkDuration, 1).'s).';

        $pushResult = $this->pr->pushTaskBranch($this->codeRoot, $branch);
        $notes[] = 'Pushed '.$branch.' to origin.';
        $notes[] = trim($pushResult);

        $pushOnly = isset($arguments['pushOnly']) && true === $arguments['pushOnly'];
        if (!$pushOnly) {
            $ghStatus = $this->pr->ghAvailable($this->codeRoot);
            if (!$ghStatus['available']) {
                throw new \RuntimeException('Branch pushed, but cannot create PR: '.($ghStatus['reason'] ?? 'unknown')."\n\n".'To skip PR creation and move without a PR, pass pushOnly: true.'."\n".'To create a PR manually: gh pr create --head '.$branch);
            }

            $existingPr = $this->pr->findExistingPr($this->codeRoot, $branch);
            if (null !== $existingPr) {
                $notes[] = 'PR already exists: '.$existingPr;
                $text = TaskMarkdown::updateField($text, 'PR URL', $existingPr);
                $text = TaskMarkdown::updateField($text, 'PR Status', 'open');
            } else {
                $prTitle = isset($arguments['prTitle']) && \is_string($arguments['prTitle']) && '' !== $arguments['prTitle']
                    ? $arguments['prTitle']
                    : $task->title;
                $prBody = isset($arguments['prBody']) && \is_string($arguments['prBody']) && '' !== $arguments['prBody']
                    ? $arguments['prBody']
                    : 'Task: '.$task->title."\nBranch: ".$branch."\n\nAuto-created by move_task (CODE-REVIEW).";
                $prBase = isset($arguments['prBaseBranch']) && \is_string($arguments['prBaseBranch']) ? $arguments['prBaseBranch'] : null;
                $prUrl = $this->pr->createPr($this->codeRoot, $branch, $prTitle, $prBody, $prBase);
                $notes[] = 'Created PR: '.$prUrl;
                $text = TaskMarkdown::updateField($text, 'PR URL', $prUrl);
                $text = TaskMarkdown::updateField($text, 'PR Status', 'open');
            }
        } else {
            $notes[] = 'Skipped PR creation (pushOnly: true).';
        }

        return TaskMarkdown::updateField($text, 'Status', TaskStatusEnum::CODE_REVIEW->value);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $notes
     */
    private function transitionToDone(string $text, \Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo $task, array $arguments, array &$notes): string
    {
        $mergeNotes = $this->worktrees->mergeTaskBranch($this->codeRoot, $task, [
            'cleanupWorktree' => !isset($arguments['cleanupWorktree']) || false !== $arguments['cleanupWorktree'],
            'deleteBranch' => isset($arguments['deleteBranch']) && true === $arguments['deleteBranch'],
            'requireCleanMain' => !isset($arguments['requireCleanMain']) || false !== $arguments['requireCleanMain'],
            'cleanupStaleIndexEntries' => isset($arguments['cleanupStaleIndexEntries']) && true === $arguments['cleanupStaleIndexEntries'],
        ]);

        $text = TaskMarkdown::updateField($text, 'Status', TaskStatusEnum::DONE->value);
        $text = TaskMarkdown::updateField($text, 'Completed', (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM));
        if (null !== $task->prUrl && '' !== $task->prUrl) {
            $text = TaskMarkdown::updateField($text, 'PR Status', 'merged');
        }

        array_push($notes, ...$mergeNotes);

        return $text;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveCastorCheckTimeout(array $arguments): int
    {
        if (isset($arguments['castorCheckTimeoutSeconds']) && is_numeric($arguments['castorCheckTimeoutSeconds'])) {
            $v = (int) $arguments['castorCheckTimeoutSeconds'];
            if ($v >= 60 && $v <= 1200) {
                return $v;
            }
        }

        $v = $this->config->castorCheckTimeoutSeconds;
        if ($v >= 60 && $v <= 1200) {
            return $v;
        }

        return 480;
    }
}
