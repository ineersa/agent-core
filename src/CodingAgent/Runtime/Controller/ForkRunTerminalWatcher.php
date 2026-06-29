<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Fork\ForkHandoffValidationResultDTO;
use Ineersa\CodingAgent\Agent\Fork\ForkHandoffValidator;
use Ineersa\CodingAgent\Agent\Fork\ForkSessionSnapshotSerializer;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * Controller-side fork terminal lifecycle watcher.
 *
 * Registers a non-blocking Revolt EventLoop repeat that polls RunStore for
 * terminal state (Completed, Failed, Cancelled).  When terminal, performs
 * handoff validation, repair (up to MAX_REPAIR_ATTEMPTS), and writes result
 * artifacts (handoff.md, metadata.json).
 *
 * Runs in the controller process (started by StartRunHandler) so it has
 * access to AppAgent services (ForkHandoffValidator, AgentArtifactRegistry).
 * The TUI-side ForkAutoExitRegistrar simply stops the TUI event loop on
 * terminal — all AppAgent-intensive work is here in the controller.
 */
final readonly class ForkRunTerminalWatcher
{
    /** Maximum number of handoff validation+repair attempts before hard failure. */
    public const int MAX_REPAIR_ATTEMPTS = 2;

    /** EventLoop poll interval in seconds. */
    private const float POLL_INTERVAL = 0.5;

    public function __construct(
        private RunStoreInterface $runStore,
        private AgentRunnerInterface $agentRunner,
        private AgentArtifactRegistry $artifactRegistry,
        private ForkHandoffValidator $handoffValidator,
        private ForkSessionSnapshotSerializer $snapshotSerializer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Start polling for fork run terminal state via Revolt EventLoop.
     *
     * Registers a non-blocking EventLoop repeat that polls RunStore until
     * the run reaches a terminal state (Completed, Failed, Cancelled).
     * When terminal, performs handoff validation/repair and writes result
     * artifacts.  After terminal state is reached, subsequent ticks no-op
     * (lightweight null-check per tick until the controller process exits).
     *
     * Loads the fork snapshot from fork_snapshot_path once to extract
     * resolvedModel and any other metadata needed for finalization.
     *
     * @param string               $runId       The child agent run ID
     * @param array<string, mixed> $forkOptions Scalar fork options (fork_mode,
     *                                          fork_snapshot_path, fork_result_dir, fork_parent_run_id,
     *                                          fork_artifact_id, fork_child_run_id, fork_task,
     *                                          fork_level, fork_cwd)
     */
    public function startForForkRun(string $runId, array $forkOptions): void
    {
        $parentRunId = (string) ($forkOptions['fork_parent_run_id'] ?? '');
        $artifactId = (string) ($forkOptions['fork_artifact_id'] ?? '');
        $childRunId = (string) ($forkOptions['fork_child_run_id'] ?? '');
        $resultDir = (string) ($forkOptions['fork_result_dir'] ?? '');
        $cwd = (string) ($forkOptions['fork_cwd'] ?? '');
        $task = (string) ($forkOptions['fork_task'] ?? '');
        $level = (string) ($forkOptions['fork_level'] ?? '');

        // Load resolvedModel from the snapshot (loaded once, not per tick).
        $resolvedModel = $this->loadResolvedModel($forkOptions);

        $repairAttempts = 0;
        $cancelled = false;

        $callback = function () use (
            $runId,
            $parentRunId,
            $artifactId,
            $childRunId,
            $resultDir,
            $cwd,
            $task,
            $level,
            $resolvedModel,
            &$repairAttempts,
            &$cancelled,
        ): void {
            if ($cancelled) {
                return;
            }

            $result = $this->handleTerminalRun(
                $runId,
                $parentRunId,
                $artifactId,
                $childRunId,
                $resultDir,
                $cwd,
                $task,
                $level,
                $resolvedModel,
                $repairAttempts,
            );

            // Mark cancelled when we reach terminal state so subsequent
            // ticks are no-ops.  The EventLoop will keep the repeat active
            // but it's a lightweight null check per tick.
            if ('done' === $result || 'exit' === $result) {
                $cancelled = true;
            }
        };

        EventLoop::repeat(self::POLL_INTERVAL, $callback);
    }

    /**
     * Extract resolvedModel from fork options or load from snapshot.
     *
     * Checks fork_resolved_model in options first (set by ForkControllerStartService
     * or AgentCommand); falls back to loading the snapshot from fork_snapshot_path.
     *
     * @param array<string, mixed> $forkOptions
     */
    private function loadResolvedModel(array $forkOptions): ?string
    {
        // Prefer explicit option value.
        if (isset($forkOptions['fork_resolved_model'])) {
            $value = $forkOptions['fork_resolved_model'];
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        // Fall back to loading from snapshot.
        $snapshotPath = (string) ($forkOptions['fork_snapshot_path'] ?? '');
        if ('' !== $snapshotPath && is_file($snapshotPath)) {
            try {
                $snapshot = $this->snapshotSerializer->fromFile($snapshotPath);

                return $snapshot->resolvedModel;
            } catch (\Throwable $e) {
                $this->logger->warning('fork.watcher.snapshot_load_failed', [
                    'component' => 'fork.watcher',
                    'event_type' => 'fork.watcher.snapshot_load_failed',
                    'snapshot_path' => $snapshotPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    // ── Shared terminal handling logic ──

    /**
     * Handle a terminal run — called from the EventLoop repeat callback.
     *
     * Reads the current run state and makes a decision based on the
     * terminal status.  May do I/O (RunStore, artifact writes).
     *
     * @return string|null 'done', 'repairing', or null (not ready)
     */
    private function handleTerminalRun(
        string $runId,
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        int &$repairAttempts,
    ): ?string {
        $state = $this->runStore->get($runId);

        if (null === $state) {
            $this->logger->error('fork.terminal.run_lost', [
                'component' => 'fork.watcher',
                'event_type' => 'fork.terminal.run_lost',
                'run_id' => $runId,
                'artifact_id' => $artifactId,
            ]);
            $this->writeMetadata($resultDir, $parentRunId, $artifactId, $childRunId, $cwd, $task, $level, $resolvedModel, AgentArtifactStatusEnum::Failed, 'Run state not found — child run may have been lost.');
            $this->artifactRegistry->update($parentRunId, $artifactId, status: AgentArtifactStatusEnum::Failed, completedAt: new \DateTimeImmutable());

            return 'done';
        }

        return match ($state->status) {
            RunStatus::Completed => $this->handleCompleted($state, $runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $repairAttempts),
            RunStatus::Failed => $this->handleFailed($state, $runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel),
            RunStatus::Cancelled, RunStatus::Cancelling => $this->handleCancelled($runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel),
            default => null, // Not terminal — keep polling
        };
    }

    /**
     * Handle a Completed run — extract handoff, validate, repair if needed.
     */
    private function handleCompleted(
        \Ineersa\AgentCore\Domain\Run\RunState $state,
        string $runId,
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        int &$repairAttempts,
    ): string {
        $candidateHandoff = $this->extractLastAssistantText($state);

        if ('' === trim($candidateHandoff)) {
            $this->writeMetadata($resultDir, $parentRunId, $artifactId, $childRunId, $cwd, $task, $level, $resolvedModel, AgentArtifactStatusEnum::Failed, 'Child run completed but produced no assistant response.');
            $this->artifactRegistry->update($parentRunId, $artifactId, status: AgentArtifactStatusEnum::Failed, completedAt: new \DateTimeImmutable());

            return 'done';
        }

        // Validate candidate handoff.
        $validationResult = $this->handoffValidator->validate($candidateHandoff);

        if ($validationResult->valid) {
            // Valid handoff — write artifacts and signal done.
            return $this->writeCompletedHandoff($runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $candidateHandoff, $repairAttempts);
        }

        // Invalid handoff — attempt repair if within limit.
        if ($repairAttempts < self::MAX_REPAIR_ATTEMPTS) {
            ++$repairAttempts;

            $this->logger->info('fork.terminal.repair', [
                'component' => 'fork.watcher',
                'event_type' => 'fork.terminal.repair',
                'artifact_id' => $artifactId,
                'attempt' => $repairAttempts,
                'max_attempts' => self::MAX_REPAIR_ATTEMPTS,
            ]);

            // Send repair instruction via followUp.
            $repairMessage = new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => $validationResult->repairInstruction ?? $this->getDefaultRepairInstruction($validationResult)]],
            );

            $this->agentRunner->followUp($runId, $repairMessage);

            return 'repairing'; // Keep polling for the next response
        }

        // Exhausted repair attempts — fail with diagnostics.
        return $this->writeInvalidHandoff($runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $candidateHandoff, $validationResult, $repairAttempts);
    }

    /**
     * Handle a Failed run.
     */
    private function handleFailed(
        \Ineersa\AgentCore\Domain\Run\RunState $state,
        string $runId,
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
    ): string {
        $error = $state->errorMessage ?? 'Child run failed without specific error.';

        $this->logger->error('fork.terminal.failed', [
            'component' => 'fork.watcher',
            'event_type' => 'fork.terminal.failed',
            'artifact_id' => $artifactId,
            'error' => $error,
        ]);

        // Write candidate handoff if available as recovery aid.
        $candidateHandoff = $this->extractLastAssistantText($state);
        if ('' !== trim($candidateHandoff)) {
            $candidatePath = $resultDir.'/candidate-handoff.md';
            file_put_contents($candidatePath, $candidateHandoff);
        }

        $this->writeMetadata($resultDir, $parentRunId, $artifactId, $childRunId, $cwd, $task, $level, $resolvedModel, AgentArtifactStatusEnum::Failed, $error);
        $this->artifactRegistry->update($parentRunId, $artifactId, status: AgentArtifactStatusEnum::Failed, completedAt: new \DateTimeImmutable());

        return 'done';
    }

    /**
     * Handle a Cancelled run.
     */
    private function handleCancelled(
        string $runId,
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
    ): string {
        $this->logger->info('fork.terminal.cancelled', [
            'component' => 'fork.watcher',
            'event_type' => 'fork.terminal.cancelled',
            'artifact_id' => $artifactId,
            'run_id' => $runId,
        ]);

        $this->writeMetadata($resultDir, $parentRunId, $artifactId, $childRunId, $cwd, $task, $level, $resolvedModel, AgentArtifactStatusEnum::Cancelled, 'Fork child was cancelled before producing an accepted handoff.');
        $this->artifactRegistry->update($parentRunId, $artifactId, status: AgentArtifactStatusEnum::Cancelled, completedAt: new \DateTimeImmutable());

        return 'done';
    }

    /**
     * Write a valid handoff and update artifact status to Completed.
     */
    private function writeCompletedHandoff(
        string $runId,
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        string $handoff,
        int $validationAttempts,
    ): string {
        $completedAt = new \DateTimeImmutable();

        // Write handoff.md to the artifact directory.
        $this->artifactRegistry->writeHandoff($parentRunId, $artifactId, $handoff);

        // Update artifact status.
        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Completed,
            completedAt: $completedAt,
            summary: \sprintf('Fork child completed after %d validation attempt(s).', $validationAttempts),
        );

        // Write metadata.
        $this->writeMetadata($resultDir, $parentRunId, $artifactId, $childRunId, $cwd, $task, $level, $resolvedModel, AgentArtifactStatusEnum::Completed, null, $validationAttempts);

        $this->logger->info('fork.terminal.completed', [
            'component' => 'fork.watcher',
            'event_type' => 'fork.terminal.completed',
            'artifact_id' => $artifactId,
            'validation_attempts' => $validationAttempts,
        ]);

        return 'done';
    }

    /**
     * Write an invalid-handoff failure with diagnostics.
     */
    private function writeInvalidHandoff(
        string $runId,
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        string $candidateHandoff,
        ForkHandoffValidationResultDTO $validationResult,
        int $attempts,
    ): string {
        $reason = $this->formatValidationReason($validationResult);
        $error = \sprintf('Handoff validation failed after %d attempt(s): %s', $attempts, $reason);

        $this->logger->error('fork.terminal.invalid_handoff', [
            'component' => 'fork.watcher',
            'event_type' => 'fork.terminal.invalid_handoff',
            'artifact_id' => $artifactId,
            'attempts' => $attempts,
            'reason' => $reason,
        ]);

        // Write candidate handoff for diagnostics.
        $candidatePath = $resultDir.'/candidate-handoff.md';
        file_put_contents($candidatePath, $candidateHandoff);

        // Write validation diagnostics.
        $diagnosticsPath = $resultDir.'/handoff-validation.json';
        file_put_contents($diagnosticsPath, json_encode([
            'valid' => false,
            'reason' => $reason,
            'missing_sections' => $validationResult->missingSections,
            'repair_instruction' => $validationResult->repairInstruction,
            'attempts' => $attempts,
            'max_attempts' => self::MAX_REPAIR_ATTEMPTS,
        ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        $this->writeMetadata($resultDir, $parentRunId, $artifactId, $childRunId, $cwd, $task, $level, $resolvedModel, AgentArtifactStatusEnum::Failed, $error, $attempts);
        $this->artifactRegistry->update($parentRunId, $artifactId, status: AgentArtifactStatusEnum::Failed, completedAt: new \DateTimeImmutable());

        return 'done';
    }

    /**
     * Write fork metadata JSON to the result directory.
     */
    private function writeMetadata(
        string $resultDir,
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        AgentArtifactStatusEnum $status,
        ?string $error = null,
        int $validationAttempts = 0,
    ): void {
        $metadata = [
            'fork_run_id' => $artifactId,
            'parent_run_id' => $parentRunId,
            'child_run_id' => $childRunId,
            'kind' => 'fork_child',
            'status' => $status->value,
            'level' => $level,
            'resolved_model' => $resolvedModel,
            'cwd' => $cwd,
            'task' => $task,
            'completed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'validation_attempts' => $validationAttempts,
            'error' => $error,
        ];

        $metadataPath = $resultDir.'/metadata.json';
        file_put_contents($metadataPath, json_encode($metadata, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    /**
     * Extract the last assistant message text from a run state.
     */
    private function extractLastAssistantText(\Ineersa\AgentCore\Domain\Run\RunState $state): string
    {
        foreach (array_reverse($state->messages) as $msg) {
            if ('assistant' === $msg->role) {
                $text = '';
                foreach ((array) $msg->content as $block) {
                    if (\is_array($block) && 'text' === ($block['type'] ?? '')) {
                        $text .= (string) ($block['text'] ?? '');
                    }
                }

                return trim($text);
            }
        }

        return '';
    }

    /**
     * Generate a human-readable reason string from validation result.
     */
    private function formatValidationReason(ForkHandoffValidationResultDTO $result): string
    {
        if ([] !== $result->missingSections) {
            return 'Missing sections: '.implode(', ', $result->missingSections);
        }

        if (null !== $result->repairInstruction) {
            return $result->repairInstruction;
        }

        return 'Invalid handoff format.';
    }

    /**
     * Generate a default repair instruction from validation result.
     */
    private function getDefaultRepairInstruction(ForkHandoffValidationResultDTO $validationResult): string
    {
        $parts = ['Please produce a valid handoff report.'];

        if ([] !== $validationResult->missingSections) {
            $parts[] = 'Missing sections: '.implode(', ', $validationResult->missingSections).'.';
        }

        if (null !== $validationResult->repairInstruction) {
            $parts[] = 'Issue: '.$validationResult->repairInstruction;
        }

        $parts[] = 'Follow the required template format with ##-prefixed sections.';

        return implode("\n", $parts);
    }
}
