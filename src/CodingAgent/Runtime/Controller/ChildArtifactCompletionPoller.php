<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildArtifactLaunchContextStore;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/**
 * Supervises non-blocking child artifacts (fork) after the parent tool returns.
 *
 * Polls registry + child RunStore, emits parent subagent_progress for live view,
 * finalizes artifacts on terminal state, and appends a parent-session completion note.
 */
final class ChildArtifactCompletionPoller
{
    private const float POLL_INTERVAL = 0.25;

    private readonly ?string $sessionId;

    /** @var array<string, string> artifactId => last running signature */
    private array $lastRunningSignatures = [];

    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildArtifactLaunchContextStore $launchContextStore,
        private readonly RunStoreInterface $runStore,
        private readonly RunStoreInterface $parentRunStore,
        private readonly EventStoreInterface $eventStore,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly AgentSessionClient $sessionClient,
        private readonly SubagentProgressSnapshotBuilder $progressSnapshotBuilder,
        private readonly SubagentChildProgressSummaryBuilder $childProgressSummaryBuilder,
        private readonly AgentsConfig $agentsConfig,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
        $sessionId = $_SERVER['HATFIELD_SESSION_ID'] ?? $_ENV['HATFIELD_SESSION_ID'] ?? null;
        $this->sessionId = (null !== $sessionId && '' !== $sessionId) ? $sessionId : null;
    }

    public function startPollLoop(float $interval = self::POLL_INTERVAL): void
    {
        EventLoop::repeat($interval, function (): void {
            $this->pollOnce();
        });
    }

    /**
     * Run one supervision pass for active child artifacts (controller poll loop tick).
     */
    public function pollOnce(): void
    {
        $this->poll();
    }

    private function poll(): void
    {
        if (null === $this->sessionId) {
            return;
        }

        try {
            $entries = $this->artifactRegistry->list($this->sessionId);
        } catch (\Throwable $e) {
            $this->logger->warning('child_artifact_completion.registry_list_failed', [
                'component' => 'child_artifact_completion.poller',
                'event_type' => 'child_artifact_completion.registry_list_failed',
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $deadlineByArtifact = [];
        $timeoutSeconds = $this->agentsConfig->subagentToolTimeoutSeconds;

        foreach ($entries as $entry) {
            if (!\in_array($entry->status, [AgentArtifactStatusEnum::Running, AgentArtifactStatusEnum::NeedsClarification], true)) {
                continue;
            }

            $launch = $this->launchContextStore->read($entry->parentRunId, $entry->artifactId);
            if (null === $launch) {
                continue;
            }

            $state = $this->runStore->get($entry->agentRunId);
            if (null === $state) {
                continue;
            }

            $startedMicros = $launch['progress_started_micros'];
            if ($this->nowMicros() > $startedMicros + ($timeoutSeconds * 1_000_000)) {
                $this->agentRunner->cancel($entry->agentRunId, 'Child artifact timed out.');
                $state = $this->runStore->get($entry->agentRunId) ?? $state;
            }

            $this->superviseEntry($entry, $launch, $state);
        }
    }

    /**
     * @param array{
     *     parent_tool_call_id: string,
     *     parent_turn_no: int,
     *     parent_tool_name: string,
     *     task_summary: string,
     *     agent_name: string,
     *     resolved_model: ?string,
     *     progress_started_micros: int,
     * } $launch
     */
    private function superviseEntry(AgentArtifactEntryDTO $entry, array $launch, RunState $state): void
    {
        $status = $state->status;

        if (RunStatus::Running === $status || RunStatus::Queued === $status || RunStatus::Compacting === $status) {
            $signature = $state->lastSeq.'|'.$state->turnNo;
            if ($signature !== ($this->lastRunningSignatures[$entry->artifactId] ?? null)) {
                $this->emitRunningProgress($entry, $launch, $state, 'running');
                $this->lastRunningSignatures[$entry->artifactId] = $signature;
            }

            return;
        }

        if (RunStatus::WaitingHuman === $status) {
            if (AgentArtifactStatusEnum::NeedsClarification !== $entry->status) {
                $this->artifactRegistry->update(
                    parentRunId: $entry->parentRunId,
                    artifactId: $entry->artifactId,
                    status: AgentArtifactStatusEnum::NeedsClarification,
                );
            }
            $signature = $state->lastSeq.'|waiting';
            if ($signature !== ($this->lastRunningSignatures[$entry->artifactId] ?? null)) {
                $this->emitRunningProgress($entry, $launch, $state, 'waiting_human');
                $this->lastRunningSignatures[$entry->artifactId] = $signature;
            }

            return;
        }

        $terminal = match ($status) {
            RunStatus::Completed => 'completed',
            RunStatus::Failed => 'failed',
            RunStatus::Cancelled => 'cancelled',
            default => null,
        };

        if (null === $terminal) {
            return;
        }

        $this->finalizeTerminal($entry, $launch, $state, $terminal);
        unset($this->lastRunningSignatures[$entry->artifactId]);
    }

    /**
     * @param array{
     *     parent_tool_call_id: string,
     *     parent_turn_no: int,
     *     parent_tool_name: string,
     *     task_summary: string,
     *     agent_name: string,
     *     resolved_model: ?string,
     *     progress_started_micros: int,
     * } $launch
     */
    private function finalizeTerminal(AgentArtifactEntryDTO $entry, array $launch, RunState $state, string $terminalStatus): void
    {
        $artifactStatus = match ($terminalStatus) {
            'completed' => AgentArtifactStatusEnum::Completed,
            'failed' => AgentArtifactStatusEnum::Failed,
            default => AgentArtifactStatusEnum::Cancelled,
        };

        $summary = null;
        $failureReason = null;
        if (RunStatus::Completed === $state->status) {
            $summary = $this->extractLastMessage($state);
        } elseif (RunStatus::Failed === $state->status) {
            $failureReason = $state->errorMessage ?? 'Run failed without error message.';
            $summary = $failureReason;
        } else {
            $summary = 'Child run cancelled.';
        }

        $this->artifactRegistry->update(
            parentRunId: $entry->parentRunId,
            artifactId: $entry->artifactId,
            status: $artifactStatus,
            completedAt: new \DateTimeImmutable(),
            summary: $summary,
            failureReason: $failureReason,
        );

        $handoff = "# Fork handoff\n\nStatus: ".$artifactStatus->value."\n\n";
        if ('' !== trim($summary)) {
            $handoff .= "## Result\n\n".$summary."\n";
        }
        if ($failureReason !== null && $failureReason !== '') {
            $handoff .= "\n## Failure reason\n\n".$failureReason."\n";
        }
        $this->artifactRegistry->writeHandoff($entry->parentRunId, $entry->artifactId, $handoff);

        $this->emitTerminalProgress($entry, $launch, $state, $terminalStatus);
        $this->sendCompletionNotification($entry, $artifactStatus);
    }

    /**
     * @param array{
     *     parent_tool_call_id: string,
     *     parent_turn_no: int,
     *     parent_tool_name: string,
     *     task_summary: string,
     *     agent_name: string,
     *     resolved_model: ?string,
     *     progress_started_micros: int,
     * } $launch
     */
    private function emitRunningProgress(AgentArtifactEntryDTO $entry, array $launch, RunState $state, string $progressStatus): void
    {
        $elapsedMs = (int) (($this->nowMicros() - $launch['progress_started_micros']) / 1000);
        $enrichment = $this->childProgressSummaryBuilder->summarize(
            $entry->parentRunId,
            $entry->agentRunId,
            $entry->artifactId,
            $state,
            $launch['resolved_model'],
        );
        $progress = $this->progressSnapshotBuilder->singleRunning(
            $launch['agent_name'],
            $entry->artifactId,
            $entry->agentRunId,
            $launch['task_summary'],
            $state,
            $elapsedMs,
            $enrichment,
            $progressStatus,
        );
        $this->appendProgressEvent($entry->parentRunId, $launch, $progress);
    }

    /**
     * @param array{
     *     parent_tool_call_id: string,
     *     parent_turn_no: int,
     *     parent_tool_name: string,
     *     task_summary: string,
     *     agent_name: string,
     *     resolved_model: ?string,
     *     progress_started_micros: int,
     * } $launch
     */
    private function emitTerminalProgress(AgentArtifactEntryDTO $entry, array $launch, RunState $state, string $terminalStatus): void
    {
        $elapsedMs = (int) (($this->nowMicros() - $launch['progress_started_micros']) / 1000);
        $enrichment = $this->childProgressSummaryBuilder->summarize(
            $entry->parentRunId,
            $entry->agentRunId,
            $entry->artifactId,
            $state,
            $launch['resolved_model'],
        );
        $progress = $this->progressSnapshotBuilder->singleTerminal(
            $terminalStatus,
            $launch['agent_name'],
            $entry->artifactId,
            $entry->agentRunId,
            $launch['task_summary'],
            $state,
            $elapsedMs,
            $enrichment,
        );
        $this->appendProgressEvent($entry->parentRunId, $launch, $progress);
    }

    /**
     * @param array<string, mixed> $progress
     * @param array{
     *     parent_tool_call_id: string,
     *     parent_turn_no: int,
     *     parent_tool_name: string,
     * } $launch
     */
    private function appendProgressEvent(string $parentRunId, array $launch, array $progress): void
    {
        $seq = $this->resolveNextProgressSeq($parentRunId);
        $event = new RunEvent(
            runId: $parentRunId,
            seq: $seq,
            turnNo: $launch['parent_turn_no'],
            type: RunEventTypeEnum::ToolExecutionUpdate->value,
            payload: [
                'tool_call_id' => $launch['parent_tool_call_id'],
                'tool_name' => $launch['parent_tool_name'],
                'delta' => '',
                'subagent_progress' => $progress,
                'order_index' => 0,
            ],
        );
        $this->eventStore->append($event);
        $this->advanceParentSequence($parentRunId, $seq);
    }

    private function sendCompletionNotification(AgentArtifactEntryDTO $entry, AgentArtifactStatusEnum $status): void
    {
        if (null === $this->sessionId) {
            return;
        }

        $prefix = AgentArtifactKindEnum::Fork === $entry->kind ? 'FORK_DONE' : 'CHILD_DONE';
        $notification = \sprintf(
            "[%s] %s %s finished with status %s.\nartifact_id: %s\nagent_run_id: %s\nUse agent_retrieve(agent_run_id=\"%s\") to inspect the handoff.",
            $prefix,
            $entry->agentName,
            $entry->agentRunId,
            $status->value,
            $entry->artifactId,
            $entry->agentRunId,
            $entry->agentRunId,
        );

        try {
            $this->sessionClient->send($this->sessionId, new UserCommand(
                type: 'append_message',
                text: $notification,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('child_artifact_completion.notification_failed', [
                'component' => 'child_artifact_completion.poller',
                'event_type' => 'child_artifact_completion.notification_failed',
                'artifact_id' => $entry->artifactId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function resolveNextProgressSeq(string $parentRunId): int
    {
        $parentState = $this->parentRunStore->get($parentRunId);
        $stateLastSeq = null !== $parentState ? $parentState->lastSeq : 0;
        $maxEventSeq = 0;
        foreach ($this->eventStore->allFor($parentRunId) as $event) {
            if ($event->seq > $maxEventSeq) {
                $maxEventSeq = $event->seq;
            }
        }

        return max($stateLastSeq, $maxEventSeq) + 1;
    }

    private function advanceParentSequence(string $parentRunId, int $seq): void
    {
        $parentState = $this->parentRunStore->get($parentRunId);
        if (null === $parentState || $parentState->lastSeq >= $seq) {
            return;
        }

        $nextState = new RunState(
            runId: $parentState->runId,
            status: $parentState->status,
            version: $parentState->version + 1,
            turnNo: $parentState->turnNo,
            lastSeq: $seq,
            isStreaming: $parentState->isStreaming,
            streamingMessage: $parentState->streamingMessage,
            pendingToolCalls: $parentState->pendingToolCalls,
            errorMessage: $parentState->errorMessage,
            messages: $parentState->messages,
            activeStepId: $parentState->activeStepId,
            retryableFailure: $parentState->retryableFailure,
        );

        $this->parentRunStore->compareAndSwap($nextState, $parentState->version);
    }

    private function extractLastMessage(RunState $state): string
    {
        foreach (array_reverse($state->messages) as $message) {
            if ('assistant' !== $message->role) {
                continue;
            }
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                    return (string) $block['text'];
                }
            }
        }

        return 'Child completed with status '.$state->status->value.'.';
    }

    private function nowMicros(): int
    {
        $instant = $this->clock->now();
        $seconds = (int) $instant->format('U');
        $micro = (int) $instant->format('u');

        return ($seconds * 1_000_000) + $micro;
    }
}
