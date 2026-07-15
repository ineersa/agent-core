<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Per-run compact tail for cross-run JSONL demux buffering.
 *
 * Committed events with seq > 0 are replayable from canonical events.jsonl and are not retained
 * as raw queue entries. Only the current non-durable stream tail (seq = 0 deltas) and protected
 * interactive/control events are kept until {@see drain()} for that run id.
 *
 * Durable completion boundaries prune superseded transient tail so completed-child live-view entry
 * does not re-apply stale seq = 0 deltas after canonical snapshot replay.
 */
final class RuntimeEventPerRunCompactBuffer
{
    /** @var array<string, list<RuntimeEvent>> */
    private array $tailByRunId = [];

    public function ingest(RuntimeEvent $event): void
    {
        $runId = $event->runId;
        if ('' === $runId) {
            return;
        }

        if ($this->isProtectedControlEvent($event)) {
            $this->appendTail($runId, $event);

            return;
        }

        if ($event->seq > 0) {
            if ($this->isStreamCheckpoint($event)) {
                $this->pruneTransientTailForCheckpoint($runId, $event);

                return;
            }

            if ($this->isRunTerminal($event)) {
                $this->clearTransientTail($runId);
                $this->appendTail($runId, $event);

                return;
            }

            $this->appendTail($runId, $event);

            return;
        }

        $this->compactAppendTransient($runId, $event);
    }

    /** @return iterable<RuntimeEvent> */
    public function drain(string $runId): iterable
    {
        if (!isset($this->tailByRunId[$runId])) {
            return;
        }

        foreach ($this->tailByRunId[$runId] as $event) {
            yield $event;
        }

        unset($this->tailByRunId[$runId]);
    }

    public function totalTailCount(): int
    {
        $count = 0;
        foreach ($this->tailByRunId as $tail) {
            $count += \count($tail);
        }

        return $count;
    }

    /** @return array<string, list<RuntimeEvent>> */
    public function tailSnapshotForTests(): array
    {
        return $this->tailByRunId;
    }

    private function isProtectedControlEvent(RuntimeEvent $event): bool
    {
        return self::isProtectedControlEventStatic($event);
    }

    private static function isProtectedControlEventStatic(RuntimeEvent $event): bool
    {
        return \in_array($event->type, [
            RuntimeEventTypeEnum::HumanInputRequested->value,
            RuntimeEventTypeEnum::HumanInputAnswered->value,
            RuntimeEventTypeEnum::HumanInputRejected->value,
            RuntimeEventTypeEnum::ApprovalRequested->value,
            RuntimeEventTypeEnum::ApprovalApproved->value,
            RuntimeEventTypeEnum::ApprovalRejected->value,
            RuntimeEventTypeEnum::ToolQuestionRequested->value,
        ], true);
    }

    private function isStreamCheckpoint(RuntimeEvent $event): bool
    {
        return \in_array($event->type, [
            RuntimeEventTypeEnum::AssistantTextCompleted->value,
            RuntimeEventTypeEnum::AssistantThinkingCompleted->value,
            RuntimeEventTypeEnum::AssistantMessageCompleted->value,
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value,
            RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            RuntimeEventTypeEnum::ToolExecutionFailed->value,
            RuntimeEventTypeEnum::ToolExecutionCancelled->value,
            RuntimeEventTypeEnum::TurnCompleted->value,
        ], true);
    }

    private function isRunTerminal(RuntimeEvent $event): bool
    {
        return \in_array($event->type, [
            RuntimeEventTypeEnum::RunCompleted->value,
            RuntimeEventTypeEnum::RunFailed->value,
            RuntimeEventTypeEnum::RunCancelled->value,
        ], true);
    }

    private function pruneTransientTailForCheckpoint(string $runId, RuntimeEvent $checkpoint): void
    {
        if (!isset($this->tailByRunId[$runId])) {
            return;
        }

        $correlation = self::correlationKey($checkpoint);
        if ('' === $correlation) {
            return;
        }

        $supersededTypeKeys = $this->supersededTransientTypeKeys($checkpoint->type, $correlation);

        $this->tailByRunId[$runId] = array_values(array_filter(
            $this->tailByRunId[$runId],
            static function (RuntimeEvent $candidate) use ($supersededTypeKeys): bool {
                if ($candidate->seq > 0) {
                    return true;
                }

                $candidateKey = $candidate->type.'|'.self::correlationKey($candidate);

                return !\in_array($candidateKey, $supersededTypeKeys, true);
            },
        ));

        if ([] === $this->tailByRunId[$runId]) {
            unset($this->tailByRunId[$runId]);
        }
    }

    private function clearTransientTail(string $runId): void
    {
        if (!isset($this->tailByRunId[$runId])) {
            return;
        }

        $this->tailByRunId[$runId] = array_values(array_filter(
            $this->tailByRunId[$runId],
            static fn (RuntimeEvent $event): bool => $event->seq > 0 || self::isProtectedControlEventStatic($event),
        ));

        if ([] === $this->tailByRunId[$runId]) {
            unset($this->tailByRunId[$runId]);
        }
    }

    private function compactAppendTransient(string $runId, RuntimeEvent $event): void
    {
        if (!isset($this->tailByRunId[$runId])) {
            $this->tailByRunId[$runId] = [];
        }

        if ($this->isCoalescableDelta($event)) {
            $key = self::correlationKey($event);
            foreach ($this->tailByRunId[$runId] as $index => $existing) {
                if (0 === $existing->seq
                    && $existing->type === $event->type
                    && self::correlationKey($existing) === $key) {
                    $this->tailByRunId[$runId][$index] = $event;

                    return;
                }
            }
        }

        $this->tailByRunId[$runId][] = $event;
    }

    private function appendTail(string $runId, RuntimeEvent $event): void
    {
        if (!isset($this->tailByRunId[$runId])) {
            $this->tailByRunId[$runId] = [];
        }

        $this->tailByRunId[$runId][] = $event;
    }

    /** @return list<string> */
    private function supersededTransientTypeKeys(string $checkpointType, string $correlation): array
    {
        $prefix = static fn (string $type): string => $type.'|'.$correlation;

        return match ($checkpointType) {
            RuntimeEventTypeEnum::AssistantTextCompleted->value => [
                $prefix(RuntimeEventTypeEnum::AssistantTextStarted->value),
                $prefix(RuntimeEventTypeEnum::AssistantTextDelta->value),
            ],
            RuntimeEventTypeEnum::AssistantThinkingCompleted->value => [
                $prefix(RuntimeEventTypeEnum::AssistantThinkingStarted->value),
                $prefix(RuntimeEventTypeEnum::AssistantThinkingDelta->value),
            ],
            RuntimeEventTypeEnum::AssistantMessageCompleted->value => [
                $prefix(RuntimeEventTypeEnum::AssistantMessageStarted->value),
            ],
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value,
            RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            RuntimeEventTypeEnum::ToolExecutionFailed->value,
            RuntimeEventTypeEnum::ToolExecutionCancelled->value => [
                $prefix(RuntimeEventTypeEnum::ToolCallStarted->value),
                $prefix(RuntimeEventTypeEnum::ToolCallArgumentsDelta->value),
                $prefix(RuntimeEventTypeEnum::ToolExecutionOutputDelta->value),
            ],
            default => [],
        };
    }

    private function isCoalescableDelta(RuntimeEvent $event): bool
    {
        return \in_array($event->type, [
            RuntimeEventTypeEnum::AssistantTextDelta->value,
            RuntimeEventTypeEnum::AssistantThinkingDelta->value,
            RuntimeEventTypeEnum::ToolCallArgumentsDelta->value,
            RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
        ], true);
    }

    private static function correlationKey(RuntimeEvent $event): string
    {
        $payload = $event->payload;

        if (isset($payload['block_id']) && '' !== (string) $payload['block_id']) {
            return (string) $payload['block_id'];
        }

        if (isset($payload['tool_call_id']) && '' !== (string) $payload['tool_call_id']) {
            return (string) $payload['tool_call_id'];
        }

        if (isset($payload['message_id']) && '' !== (string) $payload['message_id']) {
            return (string) $payload['message_id'];
        }

        return '';
    }
}
