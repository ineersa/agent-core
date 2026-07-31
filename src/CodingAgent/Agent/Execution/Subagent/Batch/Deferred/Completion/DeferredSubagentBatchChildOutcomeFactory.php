<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunStoreFactory;
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
        private ?AgentChildRunStoreFactory $childRunStoreFactory = null,
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
            definitionModel: $child->definitionModel,
            artifactKind: AgentArtifactKindEnum::Subagent,
            batchIndex: $child->batchIndex,
        );
    }

    public function buildNaturalArtifactOutcome(
        ChildRunIdentityDTO $identity,
        DeferredChildRunLifecycleProjectionDTO $projection,
    ): ChildRunTerminalOutcomeDTO {
        // Failed/cancelled children already have durable state.json; load it so handoff
        // can include bounded partial context without inventing another persistence path.
        $childState = match ($projection->childStatus) {
            RunStatus::Failed, RunStatus::Cancelled, RunStatus::Cancelling => $this->loadDurableChildState($identity),
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

    private function loadDurableChildState(ChildRunIdentityDTO $identity): ?RunState
    {
        if (null === $this->childRunStoreFactory) {
            return null;
        }

        try {
            return $this->childRunStoreFactory
                ->create($identity->parentRunId, $identity->childRunId, $identity->artifactId)
                ->get($identity->childRunId);
        } catch (\Throwable $e) {
            // Intentional local degradation: handoff still writes failure/cancel summary;
            // partial context is best-effort from already-durable child state.
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
