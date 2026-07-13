<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunProgressUpdateDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationResultDTO;
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
                $update->progressStartedMicros,
                $ctx->progressStatus,
            );

            return;
        }

        $this->progressEmitter->emitParallel(
            $update->parentRunId,
            $this->toReportMap($update->items),
            $update->activeTurns,
            $update->progressStartedMicros,
            $update->aggregateStatus,
        );
    }

    public function finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        return match ($request->kind) {
            ChildRunTerminalFinalizationKindEnum::PersistOnly => $this->finalizePersistOnly($request->artifactOutcome),
            ChildRunTerminalFinalizationKindEnum::SingleCompleted => $this->finalizeSingleCompleted($request),
            ChildRunTerminalFinalizationKindEnum::SingleFailed => $this->finalizeSingleFailed($request),
            ChildRunTerminalFinalizationKindEnum::SingleChildCancelled => $this->finalizeSingleChildCancelled($request),
            ChildRunTerminalFinalizationKindEnum::SingleTimeout => $this->finalizeSingleTimeout($request),
            ChildRunTerminalFinalizationKindEnum::ParallelRunTerminal => $this->finalizeParallelRunTerminal($request),
        };
    }

    private function finalizeParallelRunTerminal(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        $identity = $request->artifactOutcome->identity;
        $state = $request->childRunState ?? throw new \InvalidArgumentException('Parallel run terminal finalization requires child run state.');
        $summary = $this->handoffRenderer->extractLastMessage($state);
        $outcome = new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Completed,
            summary: $summary,
        );
        $this->persistArtifactOutcome($outcome);

        return ChildRunTerminalFinalizationResultDTO::persistOnly($summary);
    }

    private function finalizePersistOnly(ChildRunTerminalOutcomeDTO $outcome): ChildRunTerminalFinalizationResultDTO
    {
        $this->persistArtifactOutcome($outcome);

        return ChildRunTerminalFinalizationResultDTO::persistOnly();
    }

    private function finalizeSingleCompleted(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        $identity = $request->artifactOutcome->identity;
        $state = $request->childRunState ?? throw new \InvalidArgumentException('Single completed finalization requires child run state.');
        $finalMessages = $this->handoffRenderer->extractLastMessage($state);
        $outcome = new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Completed,
            summary: $finalMessages,
        );
        $this->persistArtifactOutcome($outcome);

        return ChildRunTerminalFinalizationResultDTO::withPresentation(
            $this->handoffRenderer->formatCompletedResult($identity->displayName, $identity->artifactId, $finalMessages),
        );
    }

    private function finalizeSingleFailed(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        $outcome = $request->artifactOutcome;
        $this->persistArtifactOutcome($outcome);
        $errorMsg = $outcome->failureReason ?? $outcome->summary ?? 'Run failed without error message.';

        return ChildRunTerminalFinalizationResultDTO::withPresentation(
            $this->handoffRenderer->formatFailedResult($outcome->identity->displayName, $outcome->identity->artifactId, $errorMsg),
        );
    }

    private function finalizeSingleChildCancelled(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        $outcome = $request->artifactOutcome;
        $this->persistArtifactOutcome($outcome);

        return ChildRunTerminalFinalizationResultDTO::withPresentation(
            $this->handoffRenderer->formatChildCancelledMessage($outcome->identity->displayName, $outcome->identity->artifactId),
        );
    }

    private function finalizeSingleTimeout(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        $identity = $request->artifactOutcome->identity;
        $timeoutSeconds = $request->timeoutSeconds ?? throw new \InvalidArgumentException('Single timeout finalization requires timeoutSeconds.');
        $this->persistArtifactOutcome($request->artifactOutcome);

        return ChildRunTerminalFinalizationResultDTO::withPresentation(
            $this->handoffRenderer->formatTimeoutResult(
                $identity->displayName,
                $timeoutSeconds,
                $identity->taskSummary,
                $identity->artifactId,
            ),
        );
    }

    private function persistArtifactOutcome(ChildRunTerminalOutcomeDTO $outcome): void
    {
        if (AgentArtifactStatusEnum::Cancelled === $outcome->status) {
            $this->artifactFinalizer->logChildCancelled($outcome->identity);
        }

        $this->artifactFinalizer->apply($outcome);
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
