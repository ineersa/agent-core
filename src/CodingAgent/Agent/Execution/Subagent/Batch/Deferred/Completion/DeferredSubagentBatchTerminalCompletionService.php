<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchCompletionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchSupervisionResultDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLifecycleListenerInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Result\AgentChildRunHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter;

/**
 * Natural all-terminal batch completion from durable child projections.
 */
final readonly class DeferredSubagentBatchTerminalCompletionService
{
    public function __construct(
        private ChildRunBatchLifecycleListenerInterface $lifecycleListener,
        private SubagentParallelAggregateResultFormatter $parallelFormatter,
        private AgentChildRunHandoffRenderer $handoffRenderer,
        private DeferredSubagentBatchCompletionDispatcher $completionDispatcher,
        private DeferredSubagentBatchChildOutcomeFactory $outcomeFactory,
    ) {
    }

    public function completeIfAllTerminal(DeferredSubagentBatchProjectionDTO $batch): void
    {
        if (null !== $batch->terminalCompletionEnqueuedAt) {
            return;
        }

        if (ChildRunBatchExecutionModeEnum::Single === $batch->executionMode) {
            $this->completeSingleIfTerminal($batch);

            return;
        }

        $this->completeParallelIfAllTerminal($batch);
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
        $this->lifecycleListener->finalizeTerminalOutcome(
            ChildRunTerminalFinalizationRequestDTO::persistOnly($artifactOutcome),
        );

        $presentation = $this->buildSingleNaturalPresentation($identity, $projection, $artifactOutcome);
        $this->completionDispatcher->dispatchCompletion(
            lifecycleId: $batch->lifecycleId,
            parentRunId: $batch->parentRunId,
            parentToolCallId: $batch->parentToolCallId,
            expectedProjectionVersion: $batch->projectionVersion,
            presentation: $presentation,
            isError: false,
            errorEnvelope: null,
        );
    }

    private function completeParallelIfAllTerminal(DeferredSubagentBatchProjectionDTO $batch): void
    {
        $items = [];
        foreach ($batch->children as $child) {
            $projection = $child->childLifecycleProjection;
            if (null === $projection || !$projection->childStatus->isTerminal()) {
                return;
            }

            $identity = $this->outcomeFactory->identityFromChild($batch, $child);
            $artifactOutcome = $this->outcomeFactory->buildNaturalArtifactOutcome($identity, $projection);
            $this->lifecycleListener->finalizeTerminalOutcome(
                ChildRunTerminalFinalizationRequestDTO::persistOnly($artifactOutcome),
            );

            $items[] = new ChildRunBatchItemSnapshotDTO(
                identity: $identity,
                terminal: true,
                artifactStatus: $artifactOutcome->status,
                message: $this->messageFromProjection($projection, $artifactOutcome),
            );
        }

        if (\count($items) !== $batch->totalChildCount) {
            return;
        }

        $allCompleted = true;
        foreach ($items as $item) {
            if (AgentArtifactStatusEnum::Completed !== $item->artifactStatus) {
                $allCompleted = false;
                break;
            }
        }

        $result = new ChildRunBatchSupervisionResultDTO(
            parentRunId: $batch->parentRunId,
            items: $items,
            completionKind: $allCompleted
                ? ChildRunBatchCompletionKindEnum::AllSucceeded
                : ChildRunBatchCompletionKindEnum::PartialFailure,
        );

        if ($allCompleted) {
            $presentation = $this->parallelFormatter->formatSuccess($result);
            $this->completionDispatcher->dispatchCompletion(
                lifecycleId: $batch->lifecycleId,
                parentRunId: $batch->parentRunId,
                parentToolCallId: $batch->parentToolCallId,
                expectedProjectionVersion: $batch->projectionVersion,
                presentation: $presentation,
                isError: false,
                errorEnvelope: null,
            );
        } else {
            $report = $this->parallelFormatter->formatReport($result);
            $message = 'Parallel subagent execution failed for one or more children.'."\n\n".$report;
            $errorEnvelope = [
                'error' => [
                    'type' => ToolCallException::class,
                    'message' => $message,
                    'retryable' => false,
                    'hint' => null,
                ],
                'details' => [
                    'error_type' => ToolCallException::class,
                    'retryable' => false,
                    'hint' => null,
                ],
            ];
            $this->completionDispatcher->dispatchCompletion(
                lifecycleId: $batch->lifecycleId,
                parentRunId: $batch->parentRunId,
                parentToolCallId: $batch->parentToolCallId,
                expectedProjectionVersion: $batch->projectionVersion,
                presentation: $message,
                isError: true,
                errorEnvelope: $errorEnvelope,
            );
        }
    }

    private function buildSingleNaturalPresentation(
        ChildRunIdentityDTO $identity,
        DeferredChildRunLifecycleProjectionDTO $childProjection,
        ChildRunTerminalOutcomeDTO $artifactOutcome,
    ): string {
        return match ($childProjection->childStatus) {
            RunStatus::Completed => $this->handoffRenderer->formatCompletedResult(
                $identity,
                $this->outcomeFactory->completedSummaryText($childProjection),
            ),
            RunStatus::Failed => $this->handoffRenderer->formatFailedResult(
                $identity,
                $artifactOutcome->failureReason ?? 'Run failed without error message.',
            ),
            RunStatus::Cancelled, RunStatus::Cancelling => $this->handoffRenderer->formatChildCancelledMessage(
                $identity,
            ),
            default => throw new \RuntimeException('Deferred single batch delivery cannot build presentation for non-terminal child status.'),
        };
    }

    private function messageFromProjection(
        DeferredChildRunLifecycleProjectionDTO $projection,
        ChildRunTerminalOutcomeDTO $artifactOutcome,
    ): string {
        if (RunStatus::Failed === $projection->childStatus) {
            return $artifactOutcome->failureReason ?? 'Run failed without error message.';
        }
        if (RunStatus::Cancelled === $projection->childStatus || RunStatus::Cancelling === $projection->childStatus) {
            return 'Child run was cancelled.';
        }

        return $this->outcomeFactory->completedSummaryText($projection);
    }

    private function requireSingleChild(DeferredSubagentBatchProjectionDTO $batch): DeferredSubagentChildProjectionDTO
    {
        if (1 !== $batch->totalChildCount || 1 !== \count($batch->children)) {
            throw new \RuntimeException('Single batch natural completion requires exactly one child row.');
        }

        return $batch->children[0];
    }
}
