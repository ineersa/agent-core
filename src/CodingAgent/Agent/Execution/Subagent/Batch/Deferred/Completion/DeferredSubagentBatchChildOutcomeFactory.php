<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;

/**
 * Shared child identity and natural-outcome builders reused by both natural
 * and interruption completion services (Piece 4C1a architecture refactor).
 */
final readonly class DeferredSubagentBatchChildOutcomeFactory
{
    public function identityFromChild(
        DeferredSubagentBatchProjectionDTO $batch,
        DeferredSubagentChildProjectionDTO $child,
    ): ChildRunIdentityDTO {
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
            ),
            RunStatus::Cancelled, RunStatus::Cancelling => new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Cancelled,
                summary: 'Child run was cancelled.',
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
}
