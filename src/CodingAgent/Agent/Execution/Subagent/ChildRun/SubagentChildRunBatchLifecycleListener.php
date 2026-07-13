<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunProgressUpdateDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLifecycleListenerInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentChildRunProgressEmitter;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunArtifactFinalizer;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer;

final class SubagentChildRunBatchLifecycleListener implements ChildRunBatchLifecycleListenerInterface
{
    public function __construct(
        private readonly SubagentChildRunProgressEmitter $progressEmitter,
        private readonly SubagentChildRunArtifactFinalizer $artifactFinalizer,
        private readonly SubagentChildRunHandoffRenderer $handoffRenderer,
    ) {
    }

    public function progressSignature(ChildRunProgressUpdateDTO $update): string
    {
        if ($update->isSingleChild) {
            $ctx = $update->singleContext;

            return $this->progressEmitter->singleProgressSignature(
                $update->parentRunId,
                $ctx->identity->childRunId,
                $ctx->identity->artifactId,
                $ctx->state,
                $ctx->identity->definitionModel,
            );
        }

        return $this->progressEmitter->parallelProgressSignature(
            $update->parentRunId,
            $this->toReportMap($update->items),
            $update->activeTurns,
        );
    }

    public function emitProgress(ChildRunProgressUpdateDTO $update): void
    {
        if ($update->isSingleChild) {
            $ctx = $update->singleContext;
            $id = $ctx->identity;
            $state = $ctx->state;
            if (\in_array($ctx->progressStatus, ['completed', 'failed', 'cancelled', 'done'], true)) {
                $this->progressEmitter->emitTerminalSingle(
                    $update->parentRunId,
                    $id->childRunId,
                    $id->displayName,
                    $id->artifactId,
                    $id->taskSummary,
                    $id->definitionModel,
                    $state,
                    $ctx->progressStatus,
                    $update->seq,
                    $update->progressStartedMicros,
                );

                return;
            }

            $this->progressEmitter->emitRunningOrWaiting(
                $update->parentRunId,
                $id->childRunId,
                $id->displayName,
                $id->artifactId,
                $id->taskSummary,
                $id->definitionModel,
                $state,
                $update->seq,
                $update->progressStartedMicros,
                $ctx->progressStatus,
            );

            return;
        }

        $this->progressEmitter->emitParallel(
            $update->parentRunId,
            $this->toReportMap($update->items),
            $update->activeTurns,
            $update->seq,
            $update->progressStartedMicros,
            $update->aggregateStatus,
        );
    }

    public function mapTerminalProgressStatus(RunState $state): string
    {
        return $this->progressEmitter->mapChildTerminalProgressStatus($state->status);
    }

    public function summarizeCompletedSummary(RunState $state): string
    {
        return $this->handoffRenderer->extractLastMessage($state);
    }

    public function applyTerminalOutcome(ChildRunTerminalOutcomeDTO $outcome): void
    {
        if (AgentArtifactStatusEnum::Cancelled === $outcome->status) {
            $this->artifactFinalizer->logChildCancelled($outcome->identity);
        }

        $this->artifactFinalizer->apply($outcome);
    }

    public function completeToolResult(ChildRunIdentityDTO $identity, RunState $state): string
    {
        $finalMessages = $this->handoffRenderer->extractLastMessage($state);
        $this->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Completed,
            summary: $finalMessages,
        ));

        return $this->handoffRenderer->formatCompletedResult($identity->displayName, $identity->artifactId, $finalMessages);
    }

    public function failToolResult(ChildRunIdentityDTO $identity, RunState $state): string
    {
        $errorMsg = $state->errorMessage ?? 'Run failed without error message.';
        $this->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Failed,
            failureReason: $errorMsg,
            summary: $errorMsg,
        ));

        return $this->handoffRenderer->formatFailedResult($identity->displayName, $identity->artifactId, $errorMsg);
    }

    public function cancelChildToolResult(ChildRunIdentityDTO $identity, RunState $state): string
    {
        $this->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Cancelled,
            summary: 'Child run was cancelled.',
            childState: $state,
        ));

        return $this->handoffRenderer->formatChildCancelledMessage($identity->displayName, $identity->artifactId);
    }

    public function timeoutToolResult(ChildRunIdentityDTO $identity, int $timeoutSeconds): string
    {
        return $this->handoffRenderer->formatTimeoutResult(
            $identity->displayName,
            $timeoutSeconds,
            $identity->taskSummary,
            $identity->artifactId,
        );
    }

    /**
     * @param list<ChildRunBatchItemSnapshotDTO> $items
     *
     * @return array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string,model?:string}>
     */
    private function toReportMap(array $items): array
    {
        $reports = [];
        foreach ($items as $item) {
            $id = $item->identity;
            $reports[$id->childRunId] = [
                'index' => $id->batchIndex,
                'agentName' => $id->displayName,
                'task' => $id->taskSummary,
                'artifactId' => $id->artifactId,
                'agentRunId' => $id->childRunId,
                'model' => $id->definitionModel,
                'terminal' => $item->terminal,
                'status' => $item->artifactStatus,
                'message' => $item->message,
            ];
        }

        return $reports;
    }
}
