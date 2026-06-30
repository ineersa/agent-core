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

/**
 * Fork-mode finalization service.
 *
 * Called from a RuntimeEventEmitter terminal-event callback when a fork child
 * run reaches terminal state (Completed, Failed, Cancelled).  Performs handoff
 * extraction, validation, optional repair steering (up to MAX_REPAIR_ATTEMPTS),
 * artifact writing (handoff.md, fork-metadata.json), and finalisation marker
 * (.fork-finalized).
 *
 * This is NOT a polling watcher — it is invoked by the emitter's event drain
 * when a terminal runtime event for a tracked fork run is seen.  Repair cycles
 * use the normal command dispatch (followUp) and rely on subsequent terminal
 * events to trigger re-evaluation.
 *
 * Lives in the controller process where all AppAgent services are available.
 * The TUI-side ForkAutoExitRegistrar stops the TUI only after finalization is
 * confirmed by the .fork-finalized marker file.
 *
 * Registered by StartRunHandler on the RuntimeEventEmitter for terminal events.
 *
 * @see StartRunHandler
 * @see RuntimeEventEmitter::onRunEvent()
 */
final class ForkRunFinalizer
{
    /** Maximum number of handoff validation+repair attempts before hard failure. */
    public const int MAX_REPAIR_ATTEMPTS = 2;

    /** Filename for fork runtime metadata (separate from AgentArtifactRegistry's metadata.json). */
    private const string FORK_METADATA_FILENAME = 'fork-metadata.json';

    /** Marker file written after all finalization artifacts are complete. */
    private const string FINALIZED_MARKER_FILENAME = '.fork-finalized';

    /** @var array<string, true> Run IDs that have been fully finalized. */
    private array $finalizedRuns = [];

    /** @var array<string, int> Run ID => current repair attempt for runs being repaired. */
    private array $repairAttempts = [];

    public function __construct(
        private RunStoreInterface $runStore,
        private AgentRunnerInterface $agentRunner,
        private AgentArtifactRegistry $artifactRegistry,
        private ForkHandoffValidator $handoffValidator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Called on a terminal runtime event (run.completed/failed/cancelled) for
     * a fork child run.  Checks current RunStore state and finalizes accordingly.
     *
     * Idempotent after the first successful finalization for a given runId.
     * Repair cycles (followUp) will cause subsequent terminal events to call this
     * again until handoff is accepted or max repair attempts are exhausted.
     *
     * @param array<string, mixed> $forkOptions Scalar fork options (fork_mode,
     *                                          fork_snapshot_path, fork_result_dir, fork_parent_run_id,
     *                                          fork_artifact_id, fork_child_run_id, fork_task,
     *                                          fork_level, fork_cwd, fork_resolved_model)
     */
    public function finalize(string $runId, array $forkOptions): void
    {
        // ── Guard: already finalized ──
        if (isset($this->finalizedRuns[$runId])) {
            return;
        }

        // ── Extract fork options ──
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

        // ── Read current run state ──
        $state = $this->runStore->get($runId);

        if (null === $state) {
            $this->logger->error('fork.finalizer.run_lost', [
                'component' => 'fork.finalizer',
                'event_type' => 'fork.finalizer.run_lost',
                'run_id' => $runId,
                'artifact_id' => $artifactId,
            ]);
            $this->writeMetadata($resultDir, $parentRunId, $artifactId, $childRunId, $cwd, $task, $level, $resolvedModel, AgentArtifactStatusEnum::Failed, 'Run state not found — child run may have been lost.');
            $this->artifactRegistry->update($parentRunId, $artifactId, status: AgentArtifactStatusEnum::Failed, completedAt: new \DateTimeImmutable());
            $this->writeFinalizedMarker($resultDir);
            $this->finalizedRuns[$runId] = true;

            return;
        }

        $result = match ($state->status) {
            RunStatus::Completed => $this->handleCompleted($state, $runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel),
            RunStatus::Failed => $this->handleFailed($state, $runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel),
            RunStatus::Cancelled => $this->handleCancelled($runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel),
            // Non-terminal statuses (Running, Queued, Compacting) are ignored.
            // The emitter callback will fire again when a terminal event arrives.
            default => null,
        };

        if ('done' === $result) {
            $this->finalizedRuns[$runId] = true;
        }
        // 'repairing' → callback will fire again on next terminal event → re-evaluate.
        // null → not terminal → callback will fire again on next terminal event.
    }

    // ── Terminal handlers ──

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
    ): string {
        $attempts = $this->repairAttempts[$runId] ?? 0;
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
            return $this->writeCompletedHandoff($runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $candidateHandoff, $attempts);
        }

        // Invalid handoff — attempt repair if within limit.
        if ($attempts < self::MAX_REPAIR_ATTEMPTS) {
            $this->repairAttempts[$runId] = $attempts + 1;

            $this->logger->info('fork.finalizer.repair', [
                'component' => 'fork.finalizer',
                'event_type' => 'fork.finalizer.repair',
                'artifact_id' => $artifactId,
                'attempt' => $attempts + 1,
                'max_attempts' => self::MAX_REPAIR_ATTEMPTS,
            ]);

            // Send repair instruction via followUp.
            $repairMessage = new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => $validationResult->repairInstruction ?? $this->getDefaultRepairInstruction($validationResult)]],
            );

            $this->agentRunner->followUp($runId, $repairMessage);

            return 'repairing'; // Await subsequent terminal event callback after followUp
        }

        // Exhausted repair attempts — fail with diagnostics.
        return $this->writeInvalidHandoff($runId, $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $candidateHandoff, $validationResult, $attempts);
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

        $this->logger->error('fork.finalizer.failed', [
            'component' => 'fork.finalizer',
            'event_type' => 'fork.finalizer.failed',
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
        $this->logger->info('fork.finalizer.cancelled', [
            'component' => 'fork.finalizer',
            'event_type' => 'fork.finalizer.cancelled',
            'artifact_id' => $artifactId,
            'run_id' => $runId,
        ]);

        $this->writeMetadata($resultDir, $parentRunId, $artifactId, $childRunId, $cwd, $task, $level, $resolvedModel, AgentArtifactStatusEnum::Cancelled, 'Fork child was cancelled before producing an accepted handoff.');
        $this->artifactRegistry->update($parentRunId, $artifactId, status: AgentArtifactStatusEnum::Cancelled, completedAt: new \DateTimeImmutable());
        $this->writeFinalizedMarker($resultDir);

        return 'done';
    }

    // ── Artifact writers ──

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

        $this->logger->info('fork.finalizer.completed', [
            'component' => 'fork.finalizer',
            'event_type' => 'fork.finalizer.completed',
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

        $this->logger->error('fork.finalizer.invalid_handoff', [
            'component' => 'fork.finalizer',
            'event_type' => 'fork.finalizer.invalid_handoff',
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
        } catch (\Throwable $e) {
            $this->logger->debug('fork.finalizer.temp_cleanup_failed', [
                'component' => 'fork.finalizer',
                'event_type' => 'fork.finalizer.temp_cleanup_failed',
                'temp_file' => basename($path),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
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
