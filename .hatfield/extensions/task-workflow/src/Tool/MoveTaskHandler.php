<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Pr\PrManager;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardLock;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskMarkdown;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;

final readonly class MoveTaskHandler implements ContextualExtensionToolHandlerInterface
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
     */
    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $control = InvocationControl::fromContext($context);
        if (null !== ($interrupt = $control->interrupted('Cancelled before move_task started.'))) {
            return $interrupt;
        }

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

        $locked = $lock->withLock(
            function () use ($taskRoot, $taskQuery, $arguments, $control): mixed {
                if (null !== ($interrupt = $control->interrupted('Cancelled before resolving task.'))) {
                    return $interrupt;
                }

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

                if (TaskStatusEnum::ARCHIVE === $to) {
                    $text = $this->transitionToArchive($text, $task, $notes);
                } elseif (TaskStatusEnum::CANCELLED === $to) {
                    // Destination collision must fail before any worktree/IDEA cleanup.
                    // Fail closed before rewriting metadata: if safe worktree cleanup
                    // cannot complete, the task must stay in its current status folder.
                    $this->store->assertDestinationAvailable($task, $to, $taskRoot);
                    $cancelled = $this->transitionToCancelled($text, $task, $notes, $control);
                    if (\is_array($cancelled)) {
                        return $cancelled;
                    }
                    $text = $cancelled;
                } elseif (TaskStatusEnum::TODO === $task->status && TaskStatusEnum::IN_PROGRESS === $to) {
                    $progress = $this->transitionTodoToInProgress($text, $task, $arguments, $notes, $control);
                    if (\is_array($progress)) {
                        return $progress;
                    }
                    $text = $progress;
                } elseif (TaskStatusEnum::IN_PROGRESS === $task->status && TaskStatusEnum::CODE_REVIEW === $to) {
                    $review = $this->transitionInProgressToCodeReview($text, $task, $arguments, $notes, $control);
                    if (\is_array($review)) {
                        return $review;
                    }
                    $text = $review;
                } elseif (TaskStatusEnum::DONE === $to) {
                    $done = $this->transitionToDone($text, $task, $arguments, $notes, $control);
                    if (\is_array($done)) {
                        return $done;
                    }
                    $text = $done;
                } else {
                    $text = TaskMarkdown::updateField($text, 'Status', $to->value);
                }

                if (null !== ($interrupt = $control->interrupted('Cancelled before writing task metadata move.'))) {
                    return $interrupt;
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
            },
            $control->cancellationToken,
            $control->deadlineNs,
            $control->timeoutSeconds,
        );

        if (InvocationControl::isInterruptMap($locked)) {
            return $locked;
        }

        return $locked;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $notes
     *
     * @return string|array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message: string}
     */
    private function transitionTodoToInProgress(
        string $text,
        TaskInfo $task,
        array $arguments,
        array &$notes,
        InvocationControl $control,
    ): string|array {
        $mainStatus = $this->git->gitOk(['status', '--porcelain'], $this->codeRoot, 120.0, $control);
        if (null !== ($interrupt = $this->execInterrupt($mainStatus, $control, 'Interrupted while checking integration checkout status.'))) {
            return $interrupt;
        }
        if ('' !== trim($mainStatus->stdout)) {
            throw new \RuntimeException("Integration checkout is not clean; commit or stash changes before claiming a task.\n".$mainStatus->stdout);
        }

        if (null !== ($interrupt = $control->interrupted('Cancelled before worktree creation.'))) {
            return $interrupt;
        }

        $worktreeBase = isset($arguments['worktreeBase']) && \is_string($arguments['worktreeBase']) ? $arguments['worktreeBase'] : null;
        $wtResult = $this->worktrees->createWorktreeForTask($this->codeRoot, $task, $worktreeBase, $control);
        if ($wtResult instanceof ExecResultDTO) {
            return $this->fromExecResult($wtResult, $control, 'Interrupted during worktree creation.');
        }

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
        if ($wtResult->extensionsVendorInstalled) {
            $notes[] = 'Installed extensions vendor into '.$wtResult->worktree.'.';
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
        if (null !== $wtResult->ideaSetupNote && '' !== $wtResult->ideaSetupNote) {
            $notes[] = $wtResult->ideaSetupNote;
        }
        if (null !== $wtResult->ideOpenNote && '' !== $wtResult->ideOpenNote) {
            $notes[] = $wtResult->ideOpenNote;
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $notes
     *
     * @return string|array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message: string}
     */
    private function transitionInProgressToCodeReview(
        string $text,
        TaskInfo $task,
        array $arguments,
        array &$notes,
        InvocationControl $control,
    ): string|array {
        $branch = $task->branch;
        if (null === $branch || '' === $branch) {
            throw new \RuntimeException('Task has no Branch metadata. Was it moved to IN-PROGRESS via move_task?');
        }

        $worktree = $task->worktree;
        if (null === $worktree || '' === $worktree || !is_dir($worktree)) {
            throw new \RuntimeException("Task worktree is missing or does not exist. Cannot push without a worktree.\n".'Worktree: '.($worktree ?? '(not set)')."\n".'Claim the task with move_task(to="IN-PROGRESS") to create a worktree first.');
        }

        $wtStatus = $this->git->gitOk(['status', '--porcelain'], $worktree, 120.0, $control);
        if (null !== ($interrupt = $this->execInterrupt($wtStatus, $control, 'Interrupted while checking worktree status.'))) {
            return $interrupt;
        }
        if ('' !== trim($wtStatus->stdout)) {
            throw new \RuntimeException("Worktree has uncommitted changes; commit them before moving to CODE-REVIEW.\n{$worktree}\n{$wtStatus->stdout}");
        }

        if (null !== ($interrupt = $control->interrupted('Cancelled before castor check.'))) {
            return $interrupt;
        }

        $checkTimeout = $this->resolveCastorCheckTimeout($arguments);
        $notes[] = 'Running deterministic castor check in worktree (timeout '.$checkTimeout.'s)...';

        $checkStart = microtime(true);
        // +45s outer Process budget: covers timeout(1) startup plus --kill-after=30s
        // grace after the castor check wall, so the host can stop a stuck tree cleanly.
        $checkResult = $this->exec->exec(
            'timeout',
            ['--kill-after=30s', (string) $checkTimeout.'s', 'env', 'LLM_MODE=true', 'castor', 'check'],
            new ExecOptionsDTO(
                cwd: $worktree,
                timeout: $control->remainingTimeoutSeconds((float) ($checkTimeout + 45)),
                env: ['LLM_MODE' => 'true'],
                cancellationToken: $control->cancellationToken,
            ),
        );
        if (null !== ($interrupt = $this->execInterrupt($checkResult, $control, 'Interrupted during castor check.'))) {
            return $interrupt;
        }
        $checkDuration = microtime(true) - $checkStart;
        $checkKilled = 124 === $checkResult->exitCode || 137 === $checkResult->exitCode;

        if (0 !== $checkResult->exitCode) {
            $reason = $checkKilled
                ? 'timeout after '.$checkTimeout.'s'
                : 'exit code '.$checkResult->exitCode;
            throw new \RuntimeException($this->formatCastorCheckFailure($reason, $worktree, $checkResult));
        }

        $notes[] = 'castor check passed ('.number_format($checkDuration, 1).'s).';

        if (null !== ($interrupt = $control->interrupted('Cancelled before push.'))) {
            return $interrupt;
        }

        $pushResult = $this->pr->pushTaskBranch($this->codeRoot, $branch, $control);
        if ($pushResult instanceof ExecResultDTO) {
            return $this->fromExecResult($pushResult, $control, 'Interrupted during push.');
        }
        $notes[] = 'Pushed '.$branch.' to origin.';
        $notes[] = trim($pushResult);

        $pushOnly = isset($arguments['pushOnly']) && true === $arguments['pushOnly'];
        if (!$pushOnly) {
            if (null !== ($interrupt = $control->interrupted('Cancelled before PR creation.'))) {
                return $interrupt;
            }

            $ghStatus = $this->pr->ghAvailable($this->codeRoot, $control);
            if ($ghStatus instanceof ExecResultDTO) {
                return $this->fromExecResult($ghStatus, $control, 'Interrupted while checking gh auth.');
            }
            if (!$ghStatus['available']) {
                throw new \RuntimeException('Branch pushed, but cannot create PR: '.($ghStatus['reason'] ?? 'unknown')."\n\n".'To skip PR creation and move without a PR, pass pushOnly: true.'."\n".'To create a PR manually: gh pr create --head '.$branch);
            }

            $existingPr = $this->pr->findExistingPr($this->codeRoot, $branch, $control);
            if ($existingPr instanceof ExecResultDTO) {
                return $this->fromExecResult($existingPr, $control, 'Interrupted while listing PRs.');
            }
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
                $prUrl = $this->pr->createPr($this->codeRoot, $branch, $prTitle, $prBody, $prBase, $control);
                if ($prUrl instanceof ExecResultDTO) {
                    return $this->fromExecResult($prUrl, $control, 'Interrupted during PR creation.');
                }
                $notes[] = 'Created PR: '.$prUrl;
                $text = TaskMarkdown::updateField($text, 'PR URL', $prUrl);
                $text = TaskMarkdown::updateField($text, 'PR Status', 'open');
            }
        } else {
            $notes[] = 'Skipped PR creation (pushOnly: true).';
        }

        return TaskMarkdown::updateField($text, 'Status', TaskStatusEnum::CODE_REVIEW->value);
    }

    private function formatCastorCheckFailure(string $reason, string $worktree, ExecResultDTO $result): string
    {
        $output = trim('' !== $result->stderr ? $result->stderr : $result->stdout);
        $qaRun = preg_match('/QA run:\s*(qa-[A-Za-z0-9_-]+)/', $result->stdout."\n".$result->stderr, $matches) ? $matches[1] : null;
        $lane = preg_match('/(?:quality failed|failed lanes?)[: ]+([^,\n]+)/i', $output, $matches) ? trim($matches[1]) : null;
        $reportDir = null === $qaRun ? null : 'var/reports/'.$qaRun;
        $log = null === $lane || null === $reportDir ? null : $reportDir.'/check-'.$lane.'.log';
        $logPath = null === $log ? null : $worktree.'/'.$log;
        $snippet = $output;
        if (null !== $logPath && is_file($logPath)) {
            $contents = file_get_contents($logPath);
            if (false !== $contents && '' !== trim($contents)) {
                $snippet = trim($contents);
            }
        }
        if ('' === $snippet) {
            $snippet = '(no output)';
        }
        $message = 'Castor check FAILED ('.$reason.') in the worktree. Fix the failures, re-validate with focused Castor commands, then move to CODE-REVIEW again.'
            ."\n".'Worktree: '.$worktree;
        if (null !== $lane && null !== $log && null !== $logPath && is_file($logPath)) {
            $message .= "\n".'Failing lane: '.$lane."\n".'Log: '.$log;
        } elseif (null !== $reportDir) {
            $message .= "\n".'QA reports: '.$reportDir;
        }

        return $message."\n".'First failure:'."\n".substr($snippet, 0, 1200);
    }

    /**
     * DONE → ARCHIVE only: metadata/status update + file move by caller; no git side effects.
     *
     * @param list<string> $notes
     */
    private function transitionToArchive(string $text, TaskInfo $task, array &$notes): string
    {
        if (TaskStatusEnum::DONE !== $task->status) {
            throw new \RuntimeException('ARCHIVE is only allowed from DONE. Task is currently '.$task->status->value.'.');
        }

        $notes[] = 'Archived task without git, worktree, PR, or branch side effects.';

        return TaskMarkdown::updateField($text, 'Status', TaskStatusEnum::ARCHIVE->value);
    }

    /**
     * ANY → CANCELLED: update status metadata and, when Worktree metadata is present,
     * safely remove that worktree + IDEA exclusions. Never merge, pull, push, or delete branch.
     *
     * @param list<string> $notes
     *
     * @return string|array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message: string}
     */
    private function transitionToCancelled(
        string $text,
        TaskInfo $task,
        array &$notes,
        InvocationControl $control,
    ): string|array {
        $cleanupNotes = $this->worktrees->removeTaskWorktreeSafely($this->codeRoot, $task, $control);
        if ($cleanupNotes instanceof ExecResultDTO) {
            return $this->fromExecResult($cleanupNotes, $control, 'Interrupted during worktree cleanup.');
        }
        array_push($notes, ...$cleanupNotes);

        return TaskMarkdown::updateField($text, 'Status', TaskStatusEnum::CANCELLED->value);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $notes
     *
     * @return string|array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message: string}
     */
    private function transitionToDone(
        string $text,
        TaskInfo $task,
        array $arguments,
        array &$notes,
        InvocationControl $control,
    ): string|array {
        $mergeNotes = $this->worktrees->mergeTaskBranch($this->codeRoot, $task, [
            'cleanupWorktree' => !isset($arguments['cleanupWorktree']) || false !== $arguments['cleanupWorktree'],
            'deleteBranch' => isset($arguments['deleteBranch']) && true === $arguments['deleteBranch'],
            'requireCleanMain' => !isset($arguments['requireCleanMain']) || false !== $arguments['requireCleanMain'],
            'cleanupStaleIndexEntries' => isset($arguments['cleanupStaleIndexEntries']) && true === $arguments['cleanupStaleIndexEntries'],
        ], $control);
        if ($mergeNotes instanceof ExecResultDTO) {
            return $this->fromExecResult($mergeNotes, $control, 'Interrupted during merge.');
        }

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

    /**
     * @return array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message: string}|null
     */
    private function execInterrupt(ExecResultDTO $result, InvocationControl $control, string $message): ?array
    {
        if ($result->cancelled || $result->timedOut) {
            return $this->fromExecResult($result, $control, $message);
        }

        return $control->interrupted($message);
    }

    /**
     * @return array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message: string}
     */
    private function fromExecResult(ExecResultDTO $result, InvocationControl $control, string $fallbackMessage): array
    {
        if ($result->cancelled || (null !== $control->cancellationToken && $control->cancellationToken->isCancellationRequested())) {
            return [
                'cancelled' => true,
                'message' => '' !== trim($result->stderr) ? trim($result->stderr) : $fallbackMessage,
            ];
        }

        return [
            'timed_out' => true,
            'timeout_seconds' => $control->timeoutSeconds ?? 0,
            'message' => '' !== trim($result->stderr) ? trim($result->stderr) : $fallbackMessage,
        ];
    }
}
