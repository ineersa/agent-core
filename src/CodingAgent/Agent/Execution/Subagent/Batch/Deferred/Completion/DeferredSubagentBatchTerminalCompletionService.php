<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer;

/**
 * Natural all-terminal batch completion from durable child projections.
 */
final readonly class DeferredSubagentBatchTerminalCompletionService
{
    public function __construct(
        private SubagentChildRunHandoffRenderer $handoffRenderer,
        private DeferredSubagentBatchCompletionDispatcher $completionDispatcher,
        private DeferredSubagentBatchChildOutcomeFactory $outcomeFactory,
    ) {
    }

    public function completeIfAllTerminal(DeferredSubagentBatchProjectionDTO $batch): void
    {
        if (null !== $batch->terminalCompletionEnqueuedAt) {
            return;
        }

        if ($this->completionDispatcher->retryFrozenCompletion($batch)) {
            return;
        }

        $this->completeSingleIfTerminal($batch);
    }

    private function completeSingleIfTerminal(DeferredSubagentBatchProjectionDTO $batch): void
    {
        if (null !== $batch->interruptionKind) {
            return;
        }

        $child = $this->requireSingleChild($batch);
        $projection = $child->childLifecycleProjection;
        if (null === $projection || !$projection->childStatus->isTerminal()) {
            return;
        }

        $identity = $this->outcomeFactory->identityFromChild($batch, $child);
        $artifactOutcome = $this->outcomeFactory->buildNaturalArtifactOutcome($identity, $projection);

        $presentation = $this->buildSingleNaturalPresentation($identity, $projection, $artifactOutcome);
        $this->completionDispatcher->dispatchSingleCompletion(
            batch: $batch,
            artifactOutcome: $artifactOutcome,
            presentation: $presentation,
            isError: false,
            errorEnvelope: null,
        );
    }

    private function buildSingleNaturalPresentation(
        ChildRunIdentityDTO $identity,
        DeferredChildRunLifecycleProjectionDTO $childProjection,
        ChildRunTerminalOutcomeDTO $artifactOutcome,
    ): string {
        return match ($childProjection->childStatus) {
            RunStatus::Completed => $this->handoffRenderer->formatCompletedResult(
                $identity->displayName,
                $identity->artifactId,
                $this->outcomeFactory->completedSummaryText($childProjection),
            ),
            RunStatus::Failed => $this->handoffRenderer->formatFailedResult(
                $identity->displayName,
                $identity->artifactId,
                $artifactOutcome->failureReason ?? 'Run failed without error message.',
            ),
            RunStatus::Cancelled, RunStatus::Cancelling => $this->handoffRenderer->formatChildCancelledMessage(
                $identity->displayName,
                $identity->artifactId,
            ),
            default => throw new \RuntimeException('Deferred single batch delivery cannot build presentation for non-terminal child status.'),
        };
    }

    private function requireSingleChild(DeferredSubagentBatchProjectionDTO $batch): DeferredSubagentChildProjectionDTO
    {
        if (1 !== \count($batch->children)) {
            throw new \RuntimeException('Single batch natural completion requires exactly one child row.');
        }

        return $batch->children[0];
    }
}
