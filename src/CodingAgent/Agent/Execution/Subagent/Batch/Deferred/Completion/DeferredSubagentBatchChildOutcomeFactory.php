<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion;

use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Shared child identity and natural-outcome builders reused by both natural
 * and interruption completion services (Piece 4C1a architecture refactor).
 */
final readonly class DeferredSubagentBatchChildOutcomeFactory
{
    public function __construct(
        private RunStateRebuilderInterface $runStateRebuilder,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function identityFromChild(
        DeferredSubagentBatchProjectionDTO $batch,
        DeferredSubagentChildProjectionDTO $child,
    ): ChildRunIdentityDTO {
        // Authoritative artifact kind lives in AgentArtifactRegistry; this reconstructed identity
        // is keyed by parentRunId + artifactId for lookup/finalization only (batch projection placeholder).
        return new ChildRunIdentityDTO(
            parentRunId: $batch->parentRunId,
            childRunId: $child->childRunId,
            artifactId: $child->artifactId,
            displayName: $child->agentName,
            taskSummary: $child->task,
            artifactKind: AgentArtifactKindEnum::Subagent,
            batchIndex: $child->batchIndex,
        );
    }

    public function buildNaturalArtifactOutcome(
        ChildRunIdentityDTO $identity,
        DeferredChildRunLifecycleProjectionDTO $projection,
    ): ChildRunTerminalOutcomeDTO {
        // Failed/cancelled children replay canonical child events so handoff can
        // include bounded partial context without another persistence path.
        $childState = match ($projection->childStatus) {
            RunStatus::Failed, RunStatus::Cancelled, RunStatus::Cancelling => $this->loadDurableChildStateForFailedOrCancelled($identity),
            default => null,
        };

        return match ($projection->childStatus) {
            RunStatus::Completed => new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Completed,
                summary: $this->completedSummaryText($projection),
            ),
            RunStatus::Failed => new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Failed,
                failureReason: $projection->errorMessage ?? 'Run failed without error message.',
                summary: $projection->errorMessage ?? 'Run failed without error message.',
                childState: $childState,
            ),
            RunStatus::Cancelled, RunStatus::Cancelling => new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Cancelled,
                summary: 'Child run was cancelled.',
                childState: $childState,
            ),
            default => throw new \RuntimeException('Terminal completion reached non-terminal child status.'),
        };
    }

    public function completedSummaryText(DeferredChildRunLifecycleProjectionDTO $projection): string
    {
        $text = trim($projection->assistantResultText ?? '');
        if ('' !== $text) {
            return $text;
        }

        return 'Completed with status completed.';
    }

    /**
     * Rebuild canonical child events for failed/cancelled handoffs.
     * Shared by natural terminal completion and interruption paths.
     */
    public function loadDurableChildStateForFailedOrCancelled(ChildRunIdentityDTO $identity): ?RunState
    {
        try {
            return $this->runStateRebuilder
                ->rebuildIfStale(RunState::queued($identity->childRunId), $identity->childRunId)
                ->rebuiltState;
        } catch (\Throwable $e) {
            // Intentional local degradation: handoff still writes failure/cancel summary;
            // partial context is best-effort from canonical child events.
            $this->logger->warning('deferred_subagent.child_state_load_failed', [
                'event_type' => 'deferred_subagent.child_state_load_failed',
                'component' => 'deferred_subagent_batch_child_outcome_factory',
                'parent_run_id' => $identity->parentRunId,
                'child_run_id' => $identity->childRunId,
                'artifact_id' => $identity->artifactId,
                'exception_class' => $e::class,
            ]);

            return null;
        }
    }
}
