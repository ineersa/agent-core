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
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardLock;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskMarkdown;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;

use function Symfony\Component\String\u;

final readonly class MoveTaskHandler implements ContextualExtensionToolHandlerInterface
{
    private const int CASTOR_CHECK_WALL_SECONDS = 210;
    private const int CASTOR_CHECK_OUTER_GUARD_SECONDS = 240;
    private const int CASTOR_CHECK_HOST_TIMEOUT_SECONDS = 285;

    public function __construct(
        private TaskBoardStore $store,
        private GitExecutor $git,
        private WorktreeManager $worktrees,
        private PrManager $pr,
        private ExecInterface $exec,
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
            function () use ($taskRoot, $taskQuery, $arguments, $control, $context): mixed {
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

                // Do not claim the status move until the transition and board write succeed.
                $notes = [];

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
                    $review = $this->transitionInProgressToCodeReview($text, $task, $arguments, $notes, $control, $context->runId);
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

                array_unshift($notes, 'Moved '.$task->status->value.' → '.$to->value.'.');
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
        string $runId,
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

        $completed = [];
        $qaReportDir = null;
        $persistEvidence = function (array $lines) use (&$text, $task): void {
            $text = $this->persistPartialEvidence($task, $text, $lines);
        };

        $checkStart = microtime(true);
        // timeout(1) allows cleanup grace beyond Castor's fixed 210s wall; the host
        // budget exceeds its maximum 270s lifetime, including --kill-after=30s.
        $checkResult = $this->exec->exec(
            'timeout',
            ['--kill-after=30s', self::CASTOR_CHECK_OUTER_GUARD_SECONDS.'s', 'castor', 'check'],
            new ExecOptionsDTO(
                cwd: $worktree,
                timeout: $control->remainingTimeoutSeconds((float) self::CASTOR_CHECK_HOST_TIMEOUT_SECONDS),
                cancellationToken: $control->cancellationToken,
            ),
        );
        if (null !== ($interrupt = $this->execInterrupt($checkResult, $control, 'Interrupted during castor check.'))) {
            return $interrupt;
        }
        $checkDuration = microtime(true) - $checkStart;
        $checkKilled = 124 === $checkResult->exitCode || 137 === $checkResult->exitCode;
        $qaReportDir = $this->extractQaReportDir($worktree, $checkResult);

        if (0 !== $checkResult->exitCode) {
            $reason = $checkKilled
                ? 'outer cleanup guard after Castor\'s '.self::CASTOR_CHECK_WALL_SECONDS.'s wall ('.self::CASTOR_CHECK_OUTER_GUARD_SECONDS.'s)'
                : 'exit code '.$checkResult->exitCode;
            $failure = $this->formatCastorCheckFailure($reason, $worktree, $checkResult);
            $persistEvidence([
                'Attempted IN-PROGRESS → CODE-REVIEW.',
                'Failed step: castor check ('.$reason.').',
                'Task remains IN-PROGRESS: '.$this->store->rel($this->store->resolveTaskRoot(), $task->path).'.',
                'Session/run: '.$runId.'.',
                ...(null !== $qaReportDir ? ['QA reports: '.$qaReportDir.'.'] : []),
                'Next: fix the failures, re-validate with focused Castor commands, then retry move_task(to="CODE-REVIEW").',
            ]);
            throw new \RuntimeException($this->formatPartialTransitionFailure(attempted: 'IN-PROGRESS → CODE-REVIEW', completed: $completed, failedStep: 'castor check', cause: $failure, task: $task, runId: $runId, nextAction: 'Fix the failures, re-validate with focused Castor commands, then retry move_task(to="CODE-REVIEW"). Do not assume push or PR already happened.', qaReportDir: $qaReportDir));
        }

        $completed[] = 'castor check passed ('.number_format($checkDuration, 1).'s).';
        $persistEvidence([
            'Attempted IN-PROGRESS → CODE-REVIEW.',
            'Completed: castor check passed ('.number_format($checkDuration, 1).'s).',
            ...(null !== $qaReportDir ? ['QA reports: '.$qaReportDir.'.'] : []),
            'Session/run: '.$runId.'.',
            'Task remains IN-PROGRESS pending push/PR.',
        ]);

        if (null !== ($interrupt = $control->interrupted('Cancelled before push.'))) {
            return $interrupt;
        }

        try {
            $pushResult = $this->pr->pushTaskBranch($this->codeRoot, $branch, $control);
        } catch (\RuntimeException $e) {
            $persistEvidence([
                'Attempted IN-PROGRESS → CODE-REVIEW.',
                'Completed: castor check passed.',
                ...(null !== $qaReportDir ? ['QA reports: '.$qaReportDir.'.'] : []),
                'Failed step: git push.',
                'Cause: '.$this->sanitizeDiagnostic($e->getMessage()).'.',
                'Task remains IN-PROGRESS: '.$this->store->rel($this->store->resolveTaskRoot(), $task->path).'.',
                'Session/run: '.$runId.'.',
                'Next: inspect remote branch state before retrying. A new CODE-REVIEW attempt runs the mandatory QA gate again.',
            ]);
            throw new \RuntimeException($this->formatPartialTransitionFailure(attempted: 'IN-PROGRESS → CODE-REVIEW', completed: $completed, failedStep: 'git push', cause: $this->sanitizeDiagnostic($e->getMessage()), task: $task, runId: $runId, nextAction: 'Inspect remote branch state before retrying. A new CODE-REVIEW attempt runs the mandatory QA gate again.', qaReportDir: $qaReportDir), 0, $e);
        }
        if ($pushResult instanceof ExecResultDTO) {
            return $this->fromExecResult($pushResult, $control, 'Interrupted during push.');
        }
        $completed[] = 'Pushed '.$branch.' to origin.';
        $persistEvidence([
            'Attempted IN-PROGRESS → CODE-REVIEW.',
            'Completed: castor check passed; pushed '.$branch.' to origin.',
            ...(null !== $qaReportDir ? ['QA reports: '.$qaReportDir.'.'] : []),
            'Session/run: '.$runId.'.',
            'Task remains IN-PROGRESS pending PR creation.',
        ]);

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
                $cause = $this->sanitizeDiagnostic((string) ($ghStatus['reason'] ?? 'unknown'));
                $persistEvidence([
                    'Attempted IN-PROGRESS → CODE-REVIEW.',
                    'Completed: castor check passed; pushed '.$branch.' to origin.',
                    ...(null !== $qaReportDir ? ['QA reports: '.$qaReportDir.'.'] : []),
                    'Failed step: PR creation.',
                    'Cause: '.$cause.'.',
                    'Task remains IN-PROGRESS: '.$this->store->rel($this->store->resolveTaskRoot(), $task->path).'.',
                    'Session/run: '.$runId.'.',
                    'Next: restore GitHub authentication and inspect existing PRs before retrying. CODE-REVIEW retries run mandatory QA again; pushOnly skips PR creation, not QA.',
                ]);
                throw new \RuntimeException($this->formatPartialTransitionFailure(attempted: 'IN-PROGRESS → CODE-REVIEW', completed: $completed, failedStep: 'PR creation', cause: $cause, task: $task, runId: $runId, nextAction: 'Restore GitHub authentication and inspect existing PRs before retrying. CODE-REVIEW retries run mandatory QA again; pushOnly skips PR creation, not QA.', qaReportDir: $qaReportDir));
            }

            $existingPr = $this->pr->findExistingPr($this->codeRoot, $branch, $control);
            if ($existingPr instanceof ExecResultDTO) {
                return $this->fromExecResult($existingPr, $control, 'Interrupted while listing PRs.');
            }
            if (null !== $existingPr) {
                $completed[] = 'PR already exists: '.$existingPr;
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
                try {
                    $prUrl = $this->pr->createPr($this->codeRoot, $branch, $prTitle, $prBody, $prBase, $control);
                } catch (\RuntimeException $e) {
                    $cause = $this->sanitizeDiagnostic($e->getMessage());
                    $persistEvidence([
                        'Attempted IN-PROGRESS → CODE-REVIEW.',
                        'Completed: castor check passed; pushed '.$branch.' to origin.',
                        ...(null !== $qaReportDir ? ['QA reports: '.$qaReportDir.'.'] : []),
                        'Failed step: PR creation.',
                        'Cause: '.$cause.'.',
                        'Task remains IN-PROGRESS: '.$this->store->rel($this->store->resolveTaskRoot(), $task->path).'.',
                        'Session/run: '.$runId.'.',
                        'Next: inspect existing PRs and resolve the reported cause before retrying. CODE-REVIEW retries run mandatory QA again; pushOnly skips PR creation, not QA.',
                    ]);
                    throw new \RuntimeException($this->formatPartialTransitionFailure(attempted: 'IN-PROGRESS → CODE-REVIEW', completed: $completed, failedStep: 'PR creation', cause: $cause, task: $task, runId: $runId, nextAction: 'Inspect existing PRs and resolve the reported cause before retrying. CODE-REVIEW retries run mandatory QA again; pushOnly skips PR creation, not QA.', qaReportDir: $qaReportDir), 0, $e);
                }
                if ($prUrl instanceof ExecResultDTO) {
                    return $this->fromExecResult($prUrl, $control, 'Interrupted during PR creation.');
                }
                $completed[] = 'Created PR: '.$prUrl;
                $text = TaskMarkdown::updateField($text, 'PR URL', $prUrl);
                $text = TaskMarkdown::updateField($text, 'PR Status', 'open');
            }
        } else {
            $completed[] = 'Skipped PR creation (pushOnly: true).';
        }

        // Preserve the PR identity even if result delivery or the final board
        // move is interrupted. This is evidence, not permission to skip QA.
        $persistEvidence([
            ...$completed,
            'Session/run: '.$runId.'.',
            'Task remains IN-PROGRESS pending final metadata move.',
        ]);
        array_push($notes, ...$completed);

        return TaskMarkdown::updateField($text, 'Status', TaskStatusEnum::CODE_REVIEW->value);
    }

    private function formatCastorCheckFailure(string $reason, string $worktree, ExecResultDTO $result): string
    {
        $output = trim($result->stdout."\n".$result->stderr);
        $qaRun = preg_match('/QA run:\s*(qa-[A-Za-z0-9_-]+)/', $output, $matches) ? $matches[1] : null;
        $lane = preg_match('/^\s*-\s*([A-Za-z0-9][A-Za-z0-9:_-]*):\s*exit code\s+\d+\s*$/mi', $output, $matches) ? $matches[1] : null;
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

        return $message."\n".'First failure:'."\n".u($snippet)->truncate(1200)->toString();
    }

    /**
     * Persist completed/failed step evidence on the current task file without changing status.
     *
     * @param list<string> $lines
     */
    private function persistPartialEvidence(TaskInfo $task, string $text, array $lines): string
    {
        $safe = [];
        foreach ($lines as $line) {
            $sanitized = $this->sanitizeDiagnostic($line);
            if ('' !== $sanitized) {
                $safe[] = $sanitized;
            }
        }
        if ([] === $safe) {
            return $text;
        }

        $updated = TaskMarkdown::appendLog($text, $safe);
        if (false === file_put_contents($task->path, $updated)) {
            throw new \RuntimeException('Failed to write partial transition evidence to task file: '.$task->path);
        }

        return $updated;
    }

    /**
     * @param list<string> $completed
     */
    private function formatPartialTransitionFailure(
        string $attempted,
        array $completed,
        string $failedStep,
        string $cause,
        TaskInfo $task,
        string $runId,
        string $nextAction,
        ?string $qaReportDir = null,
    ): string {
        $taskRoot = $this->store->resolveTaskRoot();
        $relPath = $this->store->rel($taskRoot, $task->path);
        $lines = [
            'move_task partial failure.',
            'Attempted: '.$attempted.'.',
            'Completed steps:',
        ];
        if ([] === $completed) {
            $lines[] = '- (none)';
        } else {
            foreach ($completed as $step) {
                $lines[] = '- '.$this->sanitizeDiagnostic($step);
            }
        }
        $lines[] = 'Failed step: '.$failedStep.'.';
        $lines[] = 'Cause: '.$this->sanitizeDiagnostic($cause);
        $lines[] = 'Current task status: '.$task->status->value.' ('.$relPath.').';
        $lines[] = 'Task identity: '.$task->file.'; Session/run: '.$runId.'.';
        if (null !== $qaReportDir && '' !== $qaReportDir) {
            $lines[] = 'QA reports: '.$qaReportDir.'.';
        }
        $lines[] = 'Next: '.$nextAction;

        return implode("\n", $lines);
    }

    private function extractQaReportDir(string $worktree, ExecResultDTO $result): ?string
    {
        $output = trim($result->stdout."\n".$result->stderr);
        if (!preg_match('/QA run:\s*(qa-[A-Za-z0-9_-]+)/', $output, $matches)) {
            return null;
        }

        return $worktree.'/var/reports/'.$matches[1];
    }

    private function sanitizeDiagnostic(string $raw): string
    {
        $scrubbed = preg_replace('#https?://\S+#i', '<url>', $raw) ?? $raw;
        $scrubbed = preg_replace('/bearer\s+\S+/i', 'Bearer <redacted>', $scrubbed) ?? $scrubbed;
        $scrubbed = preg_replace('/(authorization|token|api[_-]?key|bearer|password|secret)\s*[:=]\s*\S+/i', '$1=<redacted>', $scrubbed) ?? $scrubbed;
        $scrubbed = preg_replace('/\b(?:gh[pousr]?_[A-Za-z0-9_]+|github_pat_[A-Za-z0-9_]+)\b/', '<redacted>', $scrubbed) ?? $scrubbed;
        $scrubbed = preg_replace("/\n{3,}/", "\n\n", $scrubbed) ?? $scrubbed;

        return u(trim($scrubbed))->truncate(1200)->toString();
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
