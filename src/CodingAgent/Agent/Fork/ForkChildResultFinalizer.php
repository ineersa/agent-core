<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Config\ForkLevelEnum;
use Psr\Log\LoggerInterface;

/**
 * Finalizes a fork child run by validating the handoff, writing artifacts,
 * and running repair attempts when the candidate handoff is invalid.
 *
 * This is the child-side lifecycle coordinator, analogous to
 * SubagentExecutionService's finalize/handleCompleted/handleFailed/handleCancelled.
 *
 * Flow:
 *   1. Poll RunStore until terminal state.
 *   2. Extract last assistant message as candidate handoff.
 *   3. Run ForkHandoffValidator::validate().
 *   4. If valid → write handoff.md → mark Completed.
 *   5. If invalid and attempts remaining → run repair (followUp) → poll → re-validate.
 *   6. If invalid after max attempts → write candidate-handoff.md + diagnostics in metadata → mark Failed.
 *   7. If cancelled → mark Cancelled with clear metadata / history paths.
 *   8. If failed → mark Failed with error diagnostics.
 */
final class ForkChildResultFinalizer
{
    /** Maximum number of handoff validation+repair attempts before hard failure. */
    public const int MAX_REPAIR_ATTEMPTS = 2;

    /** Polling interval in microseconds for run state checks. */
    private const int POLL_MICROS = 250_000; // 250ms

    public function __construct(
        private AgentRunnerInterface $agentRunner,
        private RunStoreInterface $runStore,
        private AgentArtifactRegistry $artifactRegistry,
        private ForkHandoffValidator $handoffValidator,
        private LoggerInterface $logger,
        private int $maxRepairAttempts = self::MAX_REPAIR_ATTEMPTS,
    ) {
    }

    /**
     * Finalize a fork child run and write result artifacts.
     *
     * Blocks until the run reaches terminal state, validates the final
     * handoff with optional repair, and writes all result artifacts to
     * the parent-provided artifact directory.
     *
     * @param string      $parentRunId   Parent session run ID
     * @param string      $artifactId    Artifact ID within parent scope
     * @param string      $childRunId    Child agent run ID
     * @param string      $resultDir     Absolute path to artifact directory for writing results
     * @param string      $cwd           Child working directory (for metadata)
     * @param string      $task          Task description (for metadata)
     * @param string      $level         Resolved fork level string
     * @param string|null $resolvedModel Resolved model identifier
     *
     * @return ForkFinalizationResultDTO The result of finalization
     */
    public function finalize(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel = null,
    ): ForkFinalizationResultDTO {
        $startedAt = new \DateTimeImmutable();

        // Mark artifact Running.
        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Running,
            startedAt: $startedAt,
        );

        // Poll until terminal state.
        $state = $this->pollUntilTerminal($childRunId);

        if (null === $state) {
            return $this->handleRunLost($parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $startedAt);
        }

        // Route by terminal status.
        return match ($state->status) {
            RunStatus::Completed => $this->handleCompleted($parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $startedAt, $state),
            RunStatus::Failed => $this->handleFailed($parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $startedAt, $state),
            RunStatus::Cancelled, RunStatus::Cancelling => $this->handleCancelled($parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $startedAt, $state),
            default => $this->handleUnexpectedStatus($parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $startedAt, $state),
        };
    }

    /**
     * Poll RunStore until the run reaches a terminal state.
     *
     * @return \Ineersa\AgentCore\Domain\Run\RunState|null The terminal state, or null if the run cannot be found
     */
    private function pollUntilTerminal(string $childRunId): ?\Ineersa\AgentCore\Domain\Run\RunState
    {
        while (true) {
            $state = $this->runStore->get($childRunId);

            if (null === $state) {
                return null;
            }

            if ($this->isTerminal($state->status)) {
                return $state;
            }

            usleep(self::POLL_MICROS);
        }
    }

    /**
     * Check if a run status is terminal.
     */
    private function isTerminal(RunStatus $status): bool
    {
        return \in_array($status, [
            RunStatus::Completed,
            RunStatus::Failed,
            RunStatus::Cancelled,
            RunStatus::Cancelling,
        ], true);
    }

    /**
     * Handle a Completed run — extract handoff, validate, repair if needed.
     */
    private function handleCompleted(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        \DateTimeImmutable $startedAt,
        \Ineersa\AgentCore\Domain\Run\RunState $state,
    ): ForkFinalizationResultDTO {
        $candidateHandoff = $this->extractLastAssistantText($state);

        if ('' === trim($candidateHandoff)) {
            return $this->writeFailed(
                parentRunId: $parentRunId,
                artifactId: $artifactId,
                childRunId: $childRunId,
                resultDir: $resultDir,
                cwd: $cwd,
                task: $task,
                level: $level,
                resolvedModel: $resolvedModel,
                startedAt: $startedAt,
                error: 'Child run completed but produced no assistant response.',
                validationAttempts: 0,
            );
        }

        // Validate candidate handoff.
        $validationResult = $this->handoffValidator->validate($candidateHandoff);

        if ($validationResult->valid) {
            return $this->writeCompleted(
                parentRunId: $parentRunId,
                artifactId: $artifactId,
                childRunId: $childRunId,
                resultDir: $resultDir,
                cwd: $cwd,
                task: $task,
                level: $level,
                resolvedModel: $resolvedModel,
                startedAt: $startedAt,
                handoff: $candidateHandoff,
            );
        }

        // Invalid handoff — attempt repair.
        return $this->attemptRepair(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            childRunId: $childRunId,
            resultDir: $resultDir,
            cwd: $cwd,
            task: $task,
            level: $level,
            resolvedModel: $resolvedModel,
            startedAt: $startedAt,
            candidateHandoff: $candidateHandoff,
            validationResult: $validationResult,
            attempt: 1,
        );
    }

    /**
     * Attempt to repair an invalid handoff via followUp.
     *
     * @param int $attempt Current attempt number (1-based)
     */
    private function attemptRepair(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        \DateTimeImmutable $startedAt,
        string $candidateHandoff,
        ForkHandoffValidationResultDTO $validationResult,
        int $attempt,
    ): ForkFinalizationResultDTO {
        if ($attempt > $this->maxRepairAttempts) {
            // Exhausted repair attempts — fail with diagnostics.
            return $this->writeInvalidHandoff(
                parentRunId: $parentRunId,
                artifactId: $artifactId,
                childRunId: $childRunId,
                resultDir: $resultDir,
                cwd: $cwd,
                task: $task,
                level: $level,
                resolvedModel: $resolvedModel,
                startedAt: $startedAt,
                candidateHandoff: $candidateHandoff,
                validationResult: $validationResult,
                validationAttempts: $attempt,
            );
        }

        $this->logger->info('fork.handoff_repair', [
            'component' => 'fork.finalizer',
            'event_type' => 'fork.handoff_repair',
            'artifact_id' => $artifactId,
            'child_run_id' => $childRunId,
            'attempt' => $attempt,
            'max_attempts' => $this->maxRepairAttempts,
        ]);

        // Send repair instruction via followUp.
        $repairMessage = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $validationResult->repairInstruction ?? 'Please produce a valid handoff report with all required sections.']],
        );

        $this->agentRunner->followUp($childRunId, $repairMessage);

        // Poll for new terminal state after repair.
        $repairedState = $this->pollUntilTerminal($childRunId);

        if (null === $repairedState) {
            return $this->writeFailed(
                parentRunId: $parentRunId,
                artifactId: $artifactId,
                childRunId: $childRunId,
                resultDir: $resultDir,
                cwd: $cwd,
                task: $task,
                level: $level,
                resolvedModel: $resolvedModel,
                startedAt: $startedAt,
                error: \sprintf('Run state lost during handoff repair (attempt %d/%d).', $attempt, $this->maxRepairAttempts),
                validationAttempts: $attempt,
            );
        }

        // Check if repair itself produced a terminal state.
        if (RunStatus::Completed !== $repairedState->status) {
            return $this->handleUnexpectedStatus(
                $parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $startedAt, $repairedState,
            );
        }

        // Extract repaired handoff.
        $repairedHandoff = $this->extractLastAssistantText($repairedState);

        if ('' === trim($repairedHandoff)) {
            return $this->writeFailed(
                parentRunId: $parentRunId,
                artifactId: $artifactId,
                childRunId: $childRunId,
                resultDir: $resultDir,
                cwd: $cwd,
                task: $task,
                level: $level,
                resolvedModel: $resolvedModel,
                startedAt: $startedAt,
                error: \sprintf('Repair attempt %d produced empty assistant response.', $attempt),
                validationAttempts: $attempt,
            );
        }

        // Validate repaired handoff.
        $repairedValidation = $this->handoffValidator->validate($repairedHandoff);

        if ($repairedValidation->valid) {
            return $this->writeCompleted(
                parentRunId: $parentRunId,
                artifactId: $artifactId,
                childRunId: $childRunId,
                resultDir: $resultDir,
                cwd: $cwd,
                task: $task,
                level: $level,
                resolvedModel: $resolvedModel,
                startedAt: $startedAt,
                handoff: $repairedHandoff,
                validationAttempts: $attempt,
            );
        }

        // Still invalid — recursive repair.
        return $this->attemptRepair(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            childRunId: $childRunId,
            resultDir: $resultDir,
            cwd: $cwd,
            task: $task,
            level: $level,
            resolvedModel: $resolvedModel,
            startedAt: $startedAt,
            candidateHandoff: $repairedHandoff,
            validationResult: $repairedValidation,
            attempt: $attempt + 1,
        );
    }

    /**
     * Handle a run that was lost (null RunState).
     */
    private function handleRunLost(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        \DateTimeImmutable $startedAt,
    ): ForkFinalizationResultDTO {
        return $this->writeFailed(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            childRunId: $childRunId,
            resultDir: $resultDir,
            cwd: $cwd,
            task: $task,
            level: $level,
            resolvedModel: $resolvedModel,
            startedAt: $startedAt,
            error: 'Run state not found — child run may have been lost or failed to start.',
        );
    }

    /**
     * Handle a run that completed successfully with a valid handoff.
     */
    private function writeCompleted(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        \DateTimeImmutable $startedAt,
        string $handoff,
        int $validationAttempts = 0,
    ): ForkFinalizationResultDTO {
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

        // Write per-child metadata.json with fork-specific metadata.
        $this->writeChildMetadata($parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $startedAt, $completedAt, AgentArtifactStatusEnum::Completed, null, $validationAttempts);

        $this->logger->info('fork.completed', [
            'component' => 'fork.finalizer',
            'event_type' => 'fork.completed',
            'artifact_id' => $artifactId,
            'child_run_id' => $childRunId,
            'validation_attempts' => $validationAttempts,
        ]);

        return new ForkFinalizationResultDTO(
            status: AgentArtifactStatusEnum::Completed,
            handoffPath: \sprintf('%s/handoff.md', $resultDir),
            childRunId: $childRunId,
            validationAttempts: $validationAttempts,
        );
    }

    /**
     * Handle a run that failed.
     */
    private function handleFailed(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        \DateTimeImmutable $startedAt,
        \Ineersa\AgentCore\Domain\Run\RunState $state,
    ): ForkFinalizationResultDTO {
        $errorMsg = $state->errorMessage ?? 'Child run failed without error message.';
        $completedAt = new \DateTimeImmutable();

        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Failed,
            completedAt: $completedAt,
            failureReason: $errorMsg,
            summary: $errorMsg,
        );

        $this->writeChildMetadata($parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $startedAt, $completedAt, AgentArtifactStatusEnum::Failed, $errorMsg);

        $this->logger->info('fork.failed', [
            'component' => 'fork.finalizer',
            'event_type' => 'fork.failed',
            'artifact_id' => $artifactId,
            'error' => $errorMsg,
        ]);

        return new ForkFinalizationResultDTO(
            status: AgentArtifactStatusEnum::Failed,
            error: $errorMsg,
            childRunId: $childRunId,
        );
    }

    /**
     * Handle a run that was cancelled.
     */
    private function handleCancelled(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        \DateTimeImmutable $startedAt,
        \Ineersa\AgentCore\Domain\Run\RunState $state,
    ): ForkFinalizationResultDTO {
        $completedAt = new \DateTimeImmutable();
        $cancelMessage = 'Fork child was cancelled before producing an accepted handoff.';

        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Cancelled,
            completedAt: $completedAt,
            summary: $cancelMessage,
        );

        $this->writeChildMetadata($parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $startedAt, $completedAt, AgentArtifactStatusEnum::Cancelled, $cancelMessage);

        $this->logger->info('fork.cancelled', [
            'component' => 'fork.finalizer',
            'event_type' => 'fork.cancelled',
            'artifact_id' => $artifactId,
            'child_run_id' => $childRunId,
        ]);

        return new ForkFinalizationResultDTO(
            status: AgentArtifactStatusEnum::Cancelled,
            error: $cancelMessage,
            childRunId: $childRunId,
        );
    }

    /**
     * Handle an unexpected terminal status.
     */
    private function handleUnexpectedStatus(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        \DateTimeImmutable $startedAt,
        \Ineersa\AgentCore\Domain\Run\RunState $state,
    ): ForkFinalizationResultDTO {
        $errorMsg = \sprintf('Unexpected terminal status: %s', $state->status->value);
        $completedAt = new \DateTimeImmutable();

        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Failed,
            completedAt: $completedAt,
            failureReason: $errorMsg,
        );

        $this->writeChildMetadata($parentRunId, $artifactId, $childRunId, $resultDir, $cwd, $task, $level, $resolvedModel, $startedAt, $completedAt, AgentArtifactStatusEnum::Failed, $errorMsg);

        return new ForkFinalizationResultDTO(
            status: AgentArtifactStatusEnum::Failed,
            error: $errorMsg,
            childRunId: $childRunId,
        );
    }

    /**
     * Handle invalid handoff after max repair attempts.
     *
     * Writes the candidate handoff to candidate-handoff.md, preserves
     * validation diagnostics in metadata, and marks the artifact Failed.
     */
    private function writeInvalidHandoff(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        \DateTimeImmutable $startedAt,
        string $candidateHandoff,
        ForkHandoffValidationResultDTO $validationResult,
        int $validationAttempts,
    ): ForkFinalizationResultDTO {
        $completedAt = new \DateTimeImmutable();

        // Write candidate handoff for diagnostics.
        $candidatePath = $resultDir.'/'.ForkRunMetadataDTO::CANDIDATE_HANDOFF_FILENAME;
        $dir = \dirname($candidatePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $tmpPath = $candidatePath.'.'.bin2hex(random_bytes(4)).'.tmp';
        $written = file_put_contents($tmpPath, $candidateHandoff, \LOCK_EX);
        if (false !== $written) {
            chmod($tmpPath, 0o644);
            rename($tmpPath, $candidatePath);
        }

        $validationError = \sprintf(
            'Invalid handoff after %d attempt(s). Missing sections: %s.',
            $validationAttempts,
            [] !== $validationResult->missingSections ? implode(', ', $validationResult->missingSections) : 'filesystem statement check failed',
        );

        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Failed,
            completedAt: $completedAt,
            failureReason: $validationError,
            summary: 'Handoff validation failed after max repair attempts.',
        );

        $this->writeChildMetadata(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            childRunId: $childRunId,
            resultDir: $resultDir,
            cwd: $cwd,
            task: $task,
            level: $level,
            resolvedModel: $resolvedModel,
            startedAt: $startedAt,
            completedAt: $completedAt,
            status: AgentArtifactStatusEnum::Failed,
            error: $validationError,
            validationAttempts: $validationAttempts,
            candidateHandoffPath: $candidatePath,
            validationError: $validationError,
        );

        $this->logger->warning('fork.invalid_handoff', [
            'component' => 'fork.finalizer',
            'event_type' => 'fork.invalid_handoff',
            'artifact_id' => $artifactId,
            'child_run_id' => $childRunId,
            'attempts' => $validationAttempts,
            'missing_sections' => $validationResult->missingSections,
        ]);

        return new ForkFinalizationResultDTO(
            status: AgentArtifactStatusEnum::Failed,
            error: $validationError,
            childRunId: $childRunId,
            validationAttempts: $validationAttempts,
            candidateHandoffPath: $candidatePath,
        );
    }

    /**
     * Write a failed result without handoff validation (generic error path).
     */
    private function writeFailed(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        \DateTimeImmutable $startedAt,
        string $error,
        int $validationAttempts = 0,
    ): ForkFinalizationResultDTO {
        $completedAt = new \DateTimeImmutable();

        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Failed,
            completedAt: $completedAt,
            failureReason: $error,
            summary: $error,
        );

        $this->writeChildMetadata(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            childRunId: $childRunId,
            resultDir: $resultDir,
            cwd: $cwd,
            task: $task,
            level: $level,
            resolvedModel: $resolvedModel,
            startedAt: $startedAt,
            completedAt: $completedAt,
            status: AgentArtifactStatusEnum::Failed,
            error: $error,
            validationAttempts: $validationAttempts,
        );

        return new ForkFinalizationResultDTO(
            status: AgentArtifactStatusEnum::Failed,
            error: $error,
            childRunId: $childRunId,
            validationAttempts: $validationAttempts,
        );
    }

    /**
     * Write child metadata.json using ForkRunMetadataDTO.
     *
     * Writes the fork-specific metadata alongside the artifact registry's
     * metadata sidecar for inspection by the parent (via fork_retrieve).
     */
    private function writeChildMetadata(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $completedAt,
        AgentArtifactStatusEnum $status,
        ?string $error = null,
        int $validationAttempts = 0,
        ?string $candidateHandoffPath = null,
        ?string $validationError = null,
    ): void {
        // The artifact registry already writes its own metadata.json via
        // AgentArtifactRegistry::update().  Fork-specific fields are stored
        // in the artifact entry's summary/failureReason.  We write a
        // dedicated fork-metadata.json for richer fork-specific fields.
        $metadata = new ForkRunMetadataDTO(
            runId: $artifactId,
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            level: ForkLevelEnum::fromStringOrNull($level) ?? ForkLevelEnum::Middle,
            resolvedModel: $resolvedModel,
            cwd: $cwd,
            task: $task,
            status: $status,
            startedAt: $startedAt,
            completedAt: $completedAt,
            error: $error,
            validationAttempts: $validationAttempts,
            candidateHandoffPath: $candidateHandoffPath,
            validationError: $validationError,
        );

        // Use the existing registry metadata.json sidecar.
        // The artifact entry already has the status/timestamps from update()
        // above.  We write the target dir's fork-metadata.json directly.
        $forkMetadataDir = $resultDir;
        if (!is_dir($forkMetadataDir)) {
            mkdir($forkMetadataDir, 0o755, true);
        }

        $metadataPath = $forkMetadataDir.'/fork-metadata.json';
        $json = json_encode($metadata, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        $tmpPath = $metadataPath.'.'.bin2hex(random_bytes(4)).'.tmp';
        $written = file_put_contents($tmpPath, $json, \LOCK_EX);
        if (false !== $written) {
            chmod($tmpPath, 0o644);
            rename($tmpPath, $metadataPath);
        }
    }

    /**
     * Extract the last assistant message text from RunState.
     *
     * @return string The concatenated text content of the last assistant message
     */
    private function extractLastAssistantText(\Ineersa\AgentCore\Domain\Run\RunState $state): string
    {
        foreach (array_reverse($state->messages) as $message) {
            if ('assistant' !== $message->role) {
                continue;
            }
            $text = '';
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                    $text .= (string) $block['text'];
                }
            }
            if ('' !== trim($text)) {
                return $text;
            }
        }

        return '';
    }
}
