<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\InProcess;

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
 * TUI-integrated fork terminal lifecycle watcher.
 *
 * Provides a non-blocking callable that the TUI tick handler invokes
 * when the fork child's run reaches a terminal state.  The callable:
 *
 *   1. Reads the run state from RunStore.
 *   2. Extracts the candidate handoff from the final assistant message.
 *   3. Validates it via ForkHandoffValidator.
 *   4. If valid, writes handoff.md + metadata via AgentArtifactRegistry.
 *   5. If invalid and repair attempts remain, sends a followUp repair
 *      instruction via AgentRunnerInterface and returns 'repairing'.
 *   6. If invalid after max attempts, writes diagnostics and returns 'done'.
 *
 * This service lives in AppRuntimeInternals so that AgentCommand (AppCli)
 * can create the terminal callback and pass it through StartRunRequest
 * options to the ForkAutoExitRegistrar (TuiApplication).
 *
 * The callback is stateful across multiple invocations via the
 * $repairAttempts mutable counter captured in the closure.
 */
final readonly class ForkRunTerminalWatcher
{
    /** Maximum number of handoff validation+repair attempts before hard failure. */
    public const int MAX_REPAIR_ATTEMPTS = 2;

    public function __construct(
        private RunStoreInterface $runStore,
        private AgentRunnerInterface $agentRunner,
        private AgentArtifactRegistry $artifactRegistry,
        private ForkHandoffValidator $handoffValidator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Create a terminal callback for use in StartRunRequest options.
     *
     * @param string      $parentRunId   Parent session run ID
     * @param string      $artifactId    Artifact ID within parent scope
     * @param string      $childRunId    Child agent run ID
     * @param string      $resultDir     Absolute path to result artifact directory
     * @param string      $cwd           Child working directory (for metadata)
     * @param string      $task          Task description (for metadata)
     * @param string      $level         Resolved fork level string
     * @param string|null $resolvedModel Resolved model identifier
     *
     * @return callable(string $runId): ?string Returns 'done', 'exit', 'repairing', or null
     */
    public function createTerminalCallback(
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $resultDir,
        string $cwd,
        string $task,
        string $level,
        ?string $resolvedModel = null,
    ): callable {
        $repairAttempts = 0;

        return function (string $runId) use (
            $parentRunId,
            $artifactId,
            $childRunId,
            $resultDir,
            $cwd,
            $task,
            $level,
            $resolvedModel,
            &$repairAttempts,
        ): ?string {
            return $this->handleTerminalRun(
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
        };
    }

    /**
     * Handle a terminal run from the TUI tick.
     *
     * This is called from the TUI event loop — it must not block.
     * It reads the current run state and makes a decision based on
     * the terminal status.
     *
     * @return string|null 'done', 'repairing', or null
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

            return 'repairing'; // Keep TUI alive for the next response
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
