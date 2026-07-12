<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/**
 * Generic foreground child-run lifecycle: artifact registration, start, poll, cancel, timeout, terminal mapping.
 *
 * Reusable typed boundary for future child kinds; callers supply {@see PreparedAgentChildRunDTO} without catalog types.
 */
final class ForegroundAgentChildRunSupervisor
{
    private const int DEFAULT_POLL_MICROS = 250_000;

    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly RunStoreInterface $childRunStore,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly AgentChildProgressEmitter $progressEmitter,
        private readonly AgentChildArtifactFinalizer $artifactFinalizer,
        private readonly AgentChildHandoffRenderer $handoffRenderer,
        private readonly AgentChildParentSequenceCoordinator $sequenceCoordinator,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    /**
     * Run one prepared child to a terminal tool result string (success, failure message, timeout text, or cancellation text).
     */
    public function superviseUntilTerminal(
        PreparedAgentChildRunDTO $prepared,
        int $timeoutSeconds,
        AgentArtifactKindEnum $artifactKind = AgentArtifactKindEnum::Subagent,
    ): string {
        $parentRunId = $prepared->parentRunId;
        $artifactId = $prepared->artifactId;
        $childRunId = $prepared->childRunId;
        $displayName = $prepared->displayName;
        $taskSummary = $prepared->taskSummary;
        $definitionModel = $prepared->definitionModel;

        $entry = $this->artifactRegistry->create(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            agentRunId: $childRunId,
            agentName: $displayName,
            kind: $artifactKind,
        );
        $this->childRunDirectory->register($entry);

        $this->agentRunner->start($prepared->startRunInput);

        $startedAt = new \DateTimeImmutable();
        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Running,
            startedAt: $startedAt,
        );

        $progressStartedMicros = $this->nowMicros();
        $deadline = $progressStartedMicros + $timeoutSeconds * 1_000_000;
        $context = $this->contextAccessor->current();
        $cancelToken = $context?->cancellationToken();

        $progressSeq = $this->sequenceCoordinator->resolveNextProgressSeq($parentRunId);
        $lastSingleProgressSignature = null;

        while (true) {
            if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                $this->agentRunner->cancel($childRunId, 'Parent run cancelled subagent tool.');
                $cancelState = $this->childRunStore->get($childRunId);
                $this->artifactFinalizer->finalize(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::Cancelled,
                    summary: 'Cancelled by parent run.',
                    displayName: $displayName,
                    childRunId: $childRunId,
                    childState: $cancelState,
                );

                if (null !== $cancelState) {
                    $this->progressEmitter->emitTerminalSingle(
                        parentRunId: $parentRunId,
                        childRunId: $childRunId,
                        displayName: $displayName,
                        artifactId: $artifactId,
                        taskSummary: $taskSummary,
                        definitionModel: $definitionModel,
                        state: $cancelState,
                        terminalStatus: 'cancelled',
                        seq: $progressSeq,
                        progressStartedMicros: $progressStartedMicros,
                    );
                    $this->sequenceCoordinator->advanceParentSequence($parentRunId, $progressSeq);
                }

                throw new ToolCallException($this->handoffRenderer->formatParentCancelledSingleMessage($displayName, $artifactId), retryable: false);
            }

            if ($this->nowMicros() > $deadline) {
                $this->agentRunner->cancel($childRunId, 'Subagent timed out.');
                $timeoutState = $this->childRunStore->get($childRunId);
                if (null !== $timeoutState) {
                    $this->progressEmitter->emitTerminalSingle(
                        parentRunId: $parentRunId,
                        childRunId: $childRunId,
                        displayName: $displayName,
                        artifactId: $artifactId,
                        taskSummary: $taskSummary,
                        definitionModel: $definitionModel,
                        state: $timeoutState,
                        terminalStatus: 'failed',
                        seq: $progressSeq,
                        progressStartedMicros: $progressStartedMicros,
                    );
                    $this->sequenceCoordinator->advanceParentSequence($parentRunId, $progressSeq);
                    ++$progressSeq;
                }
                $this->artifactFinalizer->finalize(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::Failed,
                    failureReason: 'Child run timed out.',
                    summary: 'Timed out after '.$timeoutSeconds.'s.',
                );

                return $this->handoffRenderer->formatTimeoutResult($displayName, $timeoutSeconds, $taskSummary, $artifactId);
            }

            $state = $this->childRunStore->get($childRunId);
            if (null === $state) {
                $this->sleepPollInterval();
                continue;
            }

            $status = $state->status;

            if (RunStatus::Running === $status || RunStatus::Queued === $status || RunStatus::Compacting === $status) {
                $entry = $this->artifactRegistry->get($parentRunId, $artifactId);
                if (null !== $entry && AgentArtifactStatusEnum::NeedsClarification === $entry->status) {
                    $this->artifactRegistry->update(
                        parentRunId: $parentRunId,
                        artifactId: $artifactId,
                        status: AgentArtifactStatusEnum::Running,
                    );
                }

                $signature = $this->progressEmitter->singleProgressSignature(
                    $parentRunId,
                    $childRunId,
                    $artifactId,
                    $state,
                    $definitionModel,
                );
                if (null === $lastSingleProgressSignature || $signature !== $lastSingleProgressSignature) {
                    $this->progressEmitter->emitRunningOrWaiting(
                        parentRunId: $parentRunId,
                        childRunId: $childRunId,
                        displayName: $displayName,
                        artifactId: $artifactId,
                        taskSummary: $taskSummary,
                        definitionModel: $definitionModel,
                        state: $state,
                        seq: $progressSeq,
                        progressStartedMicros: $progressStartedMicros,
                    );
                    $this->sequenceCoordinator->advanceParentSequence($parentRunId, $progressSeq);
                    ++$progressSeq;
                    $lastSingleProgressSignature = $signature;
                }

                $this->sleepPollInterval();
                continue;
            }

            if (RunStatus::WaitingHuman === $status) {
                $this->artifactRegistry->update(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::NeedsClarification,
                );
                $signature = $this->progressEmitter->singleProgressSignature(
                    $parentRunId,
                    $childRunId,
                    $artifactId,
                    $state,
                    $definitionModel,
                );
                if (null === $lastSingleProgressSignature || $signature !== $lastSingleProgressSignature) {
                    $this->progressEmitter->emitRunningOrWaiting(
                        parentRunId: $parentRunId,
                        childRunId: $childRunId,
                        displayName: $displayName,
                        artifactId: $artifactId,
                        taskSummary: $taskSummary,
                        definitionModel: $definitionModel,
                        state: $state,
                        seq: $progressSeq,
                        progressStartedMicros: $progressStartedMicros,
                        progressStatus: 'waiting_human',
                    );
                    $this->sequenceCoordinator->advanceParentSequence($parentRunId, $progressSeq);
                    ++$progressSeq;
                    $lastSingleProgressSignature = $signature;
                }

                $this->sleepPollInterval();
                continue;
            }

            $terminalStatus = $this->progressEmitter->mapChildTerminalProgressStatus($status);
            $this->progressEmitter->emitTerminalSingle(
                parentRunId: $parentRunId,
                childRunId: $childRunId,
                displayName: $displayName,
                artifactId: $artifactId,
                taskSummary: $taskSummary,
                definitionModel: $definitionModel,
                state: $state,
                terminalStatus: $terminalStatus,
                seq: $progressSeq,
                progressStartedMicros: $progressStartedMicros,
            );
            $this->sequenceCoordinator->advanceParentSequence($parentRunId, $progressSeq);
            ++$progressSeq;

            return match ($status) {
                RunStatus::Completed => $this->artifactFinalizer->handleCompleted(
                    $parentRunId, $artifactId, $displayName, $state,
                ),
                RunStatus::Failed => $this->artifactFinalizer->handleFailed(
                    $parentRunId, $artifactId, $displayName, $state,
                ),
                RunStatus::Cancelled, RunStatus::Cancelling => $this->artifactFinalizer->handleCancelled(
                    $parentRunId, $artifactId, $displayName, $state,
                ),
            };
        }
    }

    private function nowMicros(): int
    {
        $instant = $this->clock->now();
        $seconds = (int) $instant->format('U');
        $micro = (int) $instant->format('u');

        return ($seconds * 1_000_000) + $micro;
    }

    private function sleepPollInterval(): void
    {
        $this->clock->sleep(self::DEFAULT_POLL_MICROS / 1_000_000);
    }
}
