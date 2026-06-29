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
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * Controller-side fork terminal lifecycle watcher.
 *
 * Registers a non-blocking Revolt EventLoop repeat that polls RunStore for
 * terminal state (Completed, Failed, Cancelled).  When terminal, performs
 * handoff validation, repair (up to MAX_REPAIR_ATTEMPTS), and writes result
 * artifacts (handoff.md, fork-metadata.json, diagnostics).
 *
 * Runs in the controller process (started by StartRunHandler) so it has
 * access to AppAgent services (ForkHandoffValidator, AgentArtifactRegistry).
 * The TUI-side ForkAutoExitRegistrar stops the TUI when terminal state is
 * reached AND the finalization marker file (.fork-finalized) is present.
 *
 * After terminal state is handled, the EventLoop repeat callback is cancelled
 * to avoid wasting ticks.
 */
final readonly class ForkRunTerminalWatcher
{
    /** Maximum number of handoff validation+repair attempts before hard failure. */
    public const int MAX_REPAIR_ATTEMPTS = 2;

    /** EventLoop poll interval in seconds. */
    private const float POLL_INTERVAL = 0.5;

    /** Filename for fork runtime metadata (separate from AgentArtifactRegistry's metadata.json). */
    private const string FORK_METADATA_FILENAME = 'fork-metadata.json';

    /** Marker file written after all finalization artifacts are complete. */
    private const string FINALIZED_MARKER_FILENAME = '.fork-finalized';

    public function __construct(
        private RunStoreInterface $runStore,
        private AgentRunnerInterface $agentRunner,
        private AgentArtifactRegistry $artifactRegistry,
        private ForkHandoffValidator $handoffValidator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Start polling for fork run terminal state via Revolt EventLoop.
     *
     * Registers a non-blocking EventLoop repeat that polls RunStore until
     * the run reaches a terminal state (Completed, Failed, Cancelled).
     * When terminal, performs handoff validation/repair and writes result
     * artifacts.  After terminal state is handled, the repeat is cancelled.
     *
     * Loads resolvedModel from fork_resolved_model option if present
     * (set by ForkControllerStartService).  Does NOT deserialize the
     * snapshot — the controller start service already has this data.
     *
     * @param string               $runId       The child agent run ID
     * @param array<string, mixed> $forkOptions Scalar fork options (fork_mode,
     *                                          fork_snapshot_path, fork_result_dir, fork_parent_run_id,
     *                                          fork_artifact_id, fork_child_run_id, fork_task,
     *                                          fork_level, fork_cwd, fork_resolved_model)
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
        $resolvedModel = isset($forkOptions['fork_resolved_model'])
            ? (string) $forkOptions['fork_resolved_model']
            : null;
        if ('' === $resolvedModel) {
            $resolvedModel = null;
        }

        $repairAttempts = 0;
        $finalized = false;

        $callbackId = EventLoop::repeat(self::POLL_INTERVAL, function () use (
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
            &$finalized,
            &$callbackId,
        ): void {
            if ($finalized) {
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

            if ('done' === $result) {
                $finalized = true;
                // Cancel the repeat to avoid wasting ticks.
                if (null !== $callbackId) {
                    EventLoop::cancel($callbackId);
                    $callbackId = null;
                }
            }
        });
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
            $this->writeFinalizedMarker($resultDir);

            return 'done';
        }

        return match ($state->status) {
            RunStatus::Completed => $this->handleCompleted($state, $runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $repairAttempts),
            RunStatus::Failed => $this->handleFailed($state, $runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel),
            RunStatus::Cancelled => $this->handleCancelled($runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel),
            // Cancelling is NOT terminal — keep polling for the eventual Cancelled.
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
            $this->writeFinalizedMarker($resultDir);

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
            $this->atomicFilePut($candidatePath, $candidateHandoff);
        }

        $this->writeMetadata($resultDir, $parentRunId, $artifactId, $childRunId, $cwd, $task, $level, $resolvedModel, AgentArtifactStatusEnum::Failed, $error);
        $this->artifactRegistry->update($parentRunId, $artifactId, status: AgentArtifactStatusEnum::Failed, completedAt: new \DateTimeImmutable());
        $this->writeFinalizedMarker($resultDir);

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
        $this->writeFinalizedMarker($resultDir);

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

        // Write fork runtime metadata (separate file from artifact metadata.json).
        $this->writeMetadata($resultDir, $parentRunId, $artifactId, $childRunId, $cwd, $task, $level, $resolvedModel, AgentArtifactStatusEnum::Completed, null, $validationAttempts);
        $this->writeFinalizedMarker($resultDir);

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
        $this->atomicFilePut($candidatePath, $candidateHandoff);

        // Write validation diagnostics.
        $diagnosticsPath = $resultDir.'/handoff-validation.json';
        $this->atomicFilePut($diagnosticsPath, json_encode([
            'valid' => false,
            'reason' => $reason,
            'missing_sections' => $validationResult->missingSections,
            'repair_instruction' => $validationResult->repairInstruction,
            'attempts' => $attempts,
            'max_attempts' => self::MAX_REPAIR_ATTEMPTS,
        ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        $this->writeMetadata($resultDir, $parentRunId, $artifactId, $childRunId, $cwd, $task, $level, $resolvedModel, AgentArtifactStatusEnum::Failed, $error, $attempts);
        $this->artifactRegistry->update($parentRunId, $artifactId, status: AgentArtifactStatusEnum::Failed, completedAt: new \DateTimeImmutable());
        $this->writeFinalizedMarker($resultDir);

        return 'done';
    }

    /**
     * Write fork runtime metadata JSON to a fork-metadata.json file
     * (NOT metadata.json — that file is owned by AgentArtifactRegistry).
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

        $metadataPath = $resultDir.'/'.self::FORK_METADATA_FILENAME;
        $this->atomicFilePut($metadataPath, json_encode($metadata, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    /**
     * Write the finalization-complete marker file.
     *
     * The TUI-side ForkAutoExitRegistrar checks for this file before
     * stopping the TUI, preventing the race where TUI exits before
     * all artifacts are written.
     */
    private function writeFinalizedMarker(string $resultDir): void
    {
        $markerPath = $resultDir.'/'.self::FINALIZED_MARKER_FILENAME;
        $this->atomicFilePut($markerPath, json_encode([
            'finalized_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * Write a file atomically using temp file + rename.
     *
     * Creates a temporary file in the same directory, writes content,
     * sets permissions, then atomically renames over the target path.
     */
    private function atomicFilePut(string $path, string $content): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Failed to create directory: %s', $dir));
            }
        }

        $tmpPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        try {
            $written = file_put_contents($tmpPath, $content, \LOCK_EX);
            if (false === $written) {
                $this->cleanupTempFile($tmpPath);
                throw new \RuntimeException(\sprintf('Failed to write temporary file for: %s (written=%s)', $path, var_export($written, true)));
            }

            chmod($tmpPath, 0o644);

            if (!rename($tmpPath, $path)) {
                $this->cleanupTempFile($tmpPath);
                throw new \RuntimeException(\sprintf('Failed to atomically rename temporary file to: %s', $path));
            }
        } catch (\Throwable $e) {
            $this->cleanupTempFile($tmpPath);
            throw $e;
        }
    }

    /**
     * Best-effort cleanup of a temporary file. Silently ignores errors.
     */
    private function cleanupTempFile(string $path): void
    {
        try {
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (\Throwable) {
            // Best-effort only.
        }
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
