<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildProgressEmitter;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunProgressUpdateDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProgressSinkPort;

final class SubagentChildRunProgressSinkAdapter implements ChildRunProgressSinkPort
{
    public function __construct(
        private readonly AgentChildProgressEmitter $progressEmitter,
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

    public function emit(ChildRunProgressUpdateDTO $update): void
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
