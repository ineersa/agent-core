<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummary;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/**
 * Canonical subagent_progress construction, dedup signatures, and committed parent append.
 */
final class SubagentChildRunProgressEmitter
{
    public function __construct(
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly CommittedRunEventAppender $committedRunEventAppender,
        private readonly SubagentProgressSnapshotBuilder $progressSnapshotBuilder,
        private readonly SubagentChildProgressSummaryBuilder $childProgressSummaryBuilder,
        private readonly RunStoreInterface $childRunStore,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function mapChildTerminalProgressStatus(RunStatus $status): string
    {
        return match ($status) {
            RunStatus::Completed => 'completed',
            RunStatus::Failed => 'failed',
            RunStatus::Cancelled, RunStatus::Cancelling => 'cancelled',
            default => 'done',
        };
    }

    public function singleProgressSignature(
        string $parentRunId,
        string $agentRunId,
        string $artifactId,
        RunState $state,
        ?string $definitionModel,
    ): string {
        $enrichment = $this->childProgressSummaryBuilder->summarize(
            $parentRunId,
            $agentRunId,
            $artifactId,
            $state,
            $definitionModel,
        );

        return implode('|', [
            (string) $state->turnNo,
            $state->status->value,
            (string) $enrichment->toolCount,
            (string) $enrichment->totalTokens,
            (string) $enrichment->inputTokens,
            (string) $enrichment->outputTokens,
            $enrichment->activeToolLine ?? '',
            implode(',', $enrichment->recentTools),
            $enrichment->assistantExcerpt ?? '',
        ]);
    }

    public function emitRunningOrWaiting(
        string $parentRunId,
        string $childRunId,
        string $displayName,
        string $artifactId,
        string $taskSummary,
        ?string $definitionModel,
        RunState $state,
        int $seq,
        int $progressStartedMicros,
        string $progressStatus = 'running',
    ): void {
        $context = $this->contextAccessor->current();
        if (null === $context) {
            return;
        }

        $elapsedMs = $this->elapsedMsSince($progressStartedMicros);
        $enrichment = $this->childProgressSummaryBuilder->summarize(
            $parentRunId,
            $childRunId,
            $artifactId,
            $state,
            $definitionModel,
        );
        $progress = $this->progressSnapshotBuilder->singleRunning(
            $displayName,
            $artifactId,
            $childRunId,
            $taskSummary,
            $state,
            $elapsedMs,
            $enrichment,
            $progressStatus,
        );
        $this->appendSubagentProgressEvent($parentRunId, $context, $seq, $progress);
    }

    public function emitTerminalSingle(
        string $parentRunId,
        string $childRunId,
        string $displayName,
        string $artifactId,
        string $taskSummary,
        ?string $definitionModel,
        RunState $state,
        string $terminalStatus,
        int $seq,
        int $progressStartedMicros,
    ): void {
        $context = $this->contextAccessor->current();
        if (null === $context) {
            return;
        }

        $elapsedMs = $this->elapsedMsSince($progressStartedMicros);
        $enrichment = $this->childProgressSummaryBuilder->summarize(
            $parentRunId,
            $childRunId,
            $artifactId,
            $state,
            $definitionModel,
        );
        $progress = $this->progressSnapshotBuilder->singleTerminal(
            $terminalStatus,
            $displayName,
            $artifactId,
            $childRunId,
            $taskSummary,
            $state,
            $elapsedMs,
            $enrichment,
        );
        $this->appendSubagentProgressEvent($parentRunId, $context, $seq, $progress);
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string,model?:string}> $reports
     * @param array<string, int>                                                                                                                                                          $activeTurns
     */
    public function parallelProgressSignature(string $parentRunId, array $reports, array $activeTurns): string
    {
        $enrichmentByRun = $this->buildParallelEnrichmentByRun($parentRunId, $reports);
        $parts = [];
        foreach ($reports as $agentRunId => $report) {
            if ($report['terminal']) {
                $parts[] = $agentRunId.':terminal:'.(null !== $report['status'] ? $report['status']->value : 'unknown');

                continue;
            }

            if (AgentArtifactStatusEnum::NeedsClarification === $report['status']) {
                $parts[] = $agentRunId.':waiting_human:'.(string) ($activeTurns[$agentRunId] ?? 0);

                continue;
            }

            $enrichment = $enrichmentByRun[$agentRunId] ?? null;
            if (null === $enrichment) {
                $parts[] = $agentRunId.':active:'.($activeTurns[$agentRunId] ?? 0);

                continue;
            }

            $parts[] = implode(':', [
                $agentRunId,
                'active',
                (string) ($activeTurns[$agentRunId] ?? 0),
                (string) $enrichment->toolCount,
                (string) $enrichment->totalTokens,
                (string) $enrichment->inputTokens,
                (string) $enrichment->outputTokens,
                $enrichment->activeToolLine ?? '',
                implode(',', $enrichment->recentTools),
                $enrichment->assistantExcerpt ?? '',
            ]);
        }

        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string,model?:string}> $reports
     * @param array<string, int>                                                                                                                                                          $activeTurns
     */
    public function emitParallel(
        string $parentRunId,
        array $reports,
        array $activeTurns,
        int $seq,
        int $progressStartedMicros,
        string $aggregateStatus = 'running',
    ): void {
        $context = $this->contextAccessor->current();
        if (null === $context) {
            return;
        }

        $elapsedMs = $this->elapsedMsSince($progressStartedMicros);
        $enrichmentByRun = $this->buildParallelEnrichmentByRun($parentRunId, $reports);

        $progress = $this->progressSnapshotBuilder->parallelSnapshot(
            $reports,
            $activeTurns,
            $elapsedMs,
            $enrichmentByRun,
            $aggregateStatus,
        );
        $event = new RunEvent(
            runId: $parentRunId,
            seq: $seq,
            turnNo: $context->turnNo(),
            type: RunEventTypeEnum::ToolExecutionUpdate->value,
            payload: [
                'tool_call_id' => $context->toolCallId(),
                'tool_name' => $context->toolName(),
                'delta' => '',
                'subagent_progress' => $progress,
                'order_index' => $context->orderIndex(),
            ],
        );

        $this->committedRunEventAppender->append($event);
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string,model?:string}> $reports
     *
     * @return array<string, SubagentChildProgressSummary>
     */
    private function buildParallelEnrichmentByRun(string $parentRunId, array $reports): array
    {
        $enrichmentByRun = [];
        foreach ($reports as $agentRunId => $report) {
            $state = $this->childRunStore->get($agentRunId);
            if (null === $state) {
                continue;
            }

            $model = \is_string($report['model'] ?? null) ? $report['model'] : null;
            $enrichmentByRun[$agentRunId] = $this->childProgressSummaryBuilder->summarize(
                $parentRunId,
                $agentRunId,
                $report['artifactId'],
                $state,
                $model,
            );
        }

        return $enrichmentByRun;
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function appendSubagentProgressEvent(
        string $parentRunId,
        ToolContext $context,
        int $seq,
        array $progress,
    ): void {
        $event = new RunEvent(
            runId: $parentRunId,
            seq: $seq,
            turnNo: $context->turnNo(),
            type: RunEventTypeEnum::ToolExecutionUpdate->value,
            payload: [
                'tool_call_id' => $context->toolCallId(),
                'tool_name' => $context->toolName(),
                'delta' => '',
                'subagent_progress' => $progress,
                'order_index' => $context->orderIndex(),
            ],
        );

        $this->committedRunEventAppender->append($event);
    }

    private function elapsedMsSince(int $startedMicros): int
    {
        $instant = $this->clock->now();
        $seconds = (int) $instant->format('U');
        $micro = (int) $instant->format('u');
        $now = ($seconds * 1_000_000) + $micro;

        return (int) (($now - $startedMicros) / 1000);
    }
}
