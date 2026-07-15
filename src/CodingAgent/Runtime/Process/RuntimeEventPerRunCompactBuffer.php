<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Per-run compact tail for cross-run JSONL demux buffering.
 *
 * Committed events with seq > 0 are replayable from canonical events.jsonl. Unobserved runs retain only
 * checkpoints' pruning effects plus seq = 0 compact tail and protected controls — not durable backlog.
 * Observed runs (explicit child observation or session primary run) may retain replayable durable events.
 * Only the current non-durable stream tail (seq = 0 deltas) and protected
 * interactive/control events are kept until {@see drain()} for that run id.
 *
 * Stream subscribers emit incremental chunks (assistant text/thinking, tool partial_json, tool
 * output delta). Coalescing appends those chunks — never latest-wins replacement.
 *
 * Durable and transient stream completion boundaries prune superseded transient tail so
 * completed-child live-view entry does not re-apply stale seq = 0 deltas after canonical
 * snapshot replay.
 */
final class RuntimeEventPerRunCompactBuffer
{
    /** @var array<string, list<RuntimeEvent>> */
    private array $tailByRunId = [];

    public function ingest(RuntimeEvent $event, bool $observedRun = false): void
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
                if ($observedRun) {
                    $this->appendTail($runId, $event);
                }

                return;
            }

            if ($this->isRunTerminal($event)) {
                $this->clearTransientTail($runId);
                if ($observedRun) {
                    $this->appendTail($runId, $event);
                }

                return;
            }

            if ($observedRun) {
                $this->appendTail($runId, $event);
            }

            return;
        }

        if ($this->isStreamCheckpoint($event)) {
            $this->pruneTransientTailForCheckpoint($runId, $event);

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

    /**
     * Drop replayable durable backlog after live-view observation ends.
     *
     * Retains seq = 0 compact stream tail and protected interactive events only.
     */
    public function releaseObservationRetention(string $runId): void
    {
        if (!isset($this->tailByRunId[$runId])) {
            return;
        }

        $this->tailByRunId[$runId] = array_values(array_filter(
            $this->tailByRunId[$runId],
            static fn (RuntimeEvent $event): bool => 0 === $event->seq || self::isProtectedControlEventStatic($event),
        ));

        if ([] === $this->tailByRunId[$runId]) {
            unset($this->tailByRunId[$runId]);
        }
    }

    public function totalTailCount(): int
    {
        $count = 0;
        foreach ($this->tailByRunId as $tail) {
            $count += \count($tail);
        }

        return $count;
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

        $this->tailByRunId[$runId] = array_values(array_filter(
            $this->tailByRunId[$runId],
            fn (RuntimeEvent $candidate): bool => !$this->isTransientSupersededByCheckpoint($candidate, $checkpoint),
        ));

        if ([] === $this->tailByRunId[$runId]) {
            unset($this->tailByRunId[$runId]);
        }
    }

    private function isTransientSupersededByCheckpoint(RuntimeEvent $candidate, RuntimeEvent $checkpoint): bool
    {
        if ($candidate->seq > 0) {
            return false;
        }

        if (self::isProtectedControlEventStatic($candidate)) {
            return false;
        }

        return match ($checkpoint->type) {
            RuntimeEventTypeEnum::AssistantTextCompleted->value => $this->matchesBlockCorrelation($candidate, $checkpoint),
            RuntimeEventTypeEnum::AssistantThinkingCompleted->value => $this->matchesBlockCorrelation($candidate, $checkpoint),
            RuntimeEventTypeEnum::AssistantMessageCompleted->value => $this->matchesMessageStreamCorrelation($candidate, $checkpoint),
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value => $this->matchesToolCallArgumentsCorrelation($candidate, $checkpoint),
            RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            RuntimeEventTypeEnum::ToolExecutionFailed->value,
            RuntimeEventTypeEnum::ToolExecutionCancelled->value => $this->matchesToolExecutionOutputCorrelation($candidate, $checkpoint),
            default => false,
        };
    }

    private function matchesBlockCorrelation(RuntimeEvent $candidate, RuntimeEvent $checkpoint): bool
    {
        $blockId = self::payloadString($checkpoint->payload, 'block_id');
        if ('' === $blockId) {
            return false;
        }

        if (!\in_array($candidate->type, [
            RuntimeEventTypeEnum::AssistantTextStarted->value,
            RuntimeEventTypeEnum::AssistantTextDelta->value,
            RuntimeEventTypeEnum::AssistantThinkingStarted->value,
            RuntimeEventTypeEnum::AssistantThinkingDelta->value,
        ], true)) {
            return false;
        }

        return self::payloadString($candidate->payload, 'block_id') === $blockId;
    }

    private function matchesMessageStreamCorrelation(RuntimeEvent $candidate, RuntimeEvent $checkpoint): bool
    {
        $messageId = self::payloadString($checkpoint->payload, 'message_id');
        if ('' === $messageId) {
            return false;
        }

        if (!\in_array($candidate->type, [
            RuntimeEventTypeEnum::AssistantMessageStarted->value,
            RuntimeEventTypeEnum::AssistantTextStarted->value,
            RuntimeEventTypeEnum::AssistantTextDelta->value,
            RuntimeEventTypeEnum::AssistantTextCompleted->value,
            RuntimeEventTypeEnum::AssistantThinkingStarted->value,
            RuntimeEventTypeEnum::AssistantThinkingDelta->value,
            RuntimeEventTypeEnum::AssistantThinkingCompleted->value,
        ], true)) {
            return false;
        }

        $candidateMessageId = self::payloadString($candidate->payload, 'message_id');
        if ('' !== $candidateMessageId && $candidateMessageId === $messageId) {
            return true;
        }

        $stepId = self::payloadString($candidate->payload, 'step_id');
        if ('' !== $stepId && $stepId === $messageId) {
            return true;
        }

        $blockId = self::payloadString($candidate->payload, 'block_id');
        if ('' !== $blockId) {
            $runPrefix = $checkpoint->runId.'_'.$messageId.'_';

            return str_starts_with($blockId, $runPrefix);
        }

        return false;
    }

    private function matchesToolCallArgumentsCorrelation(RuntimeEvent $candidate, RuntimeEvent $checkpoint): bool
    {
        $toolCallId = self::payloadString($checkpoint->payload, 'tool_call_id');
        if ('' === $toolCallId) {
            return false;
        }

        if (!\in_array($candidate->type, [
            RuntimeEventTypeEnum::ToolCallStarted->value,
            RuntimeEventTypeEnum::ToolCallArgumentsDelta->value,
        ], true)) {
            return false;
        }

        return self::payloadString($candidate->payload, 'tool_call_id') === $toolCallId;
    }

    private function matchesToolExecutionOutputCorrelation(RuntimeEvent $candidate, RuntimeEvent $checkpoint): bool
    {
        if (RuntimeEventTypeEnum::ToolExecutionOutputDelta->value !== $candidate->type) {
            return false;
        }

        $toolCallId = self::payloadString($checkpoint->payload, 'tool_call_id');

        return '' !== $toolCallId
            && self::payloadString($candidate->payload, 'tool_call_id') === $toolCallId;
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

        if ($this->isCoalescableStreamEvent($event)) {
            $key = self::streamCoalesceKey($event);
            foreach ($this->tailByRunId[$runId] as $index => $existing) {
                if (0 !== $existing->seq
                    || $existing->type !== $event->type
                    || self::streamCoalesceKey($existing) !== $key) {
                    continue;
                }

                $this->tailByRunId[$runId][$index] = $this->mergeCoalescedStreamEvent($existing, $event);

                return;
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

    private function isCoalescableStreamEvent(RuntimeEvent $event): bool
    {
        return \in_array($event->type, [
            RuntimeEventTypeEnum::AssistantTextStarted->value,
            RuntimeEventTypeEnum::AssistantTextDelta->value,
            RuntimeEventTypeEnum::AssistantThinkingStarted->value,
            RuntimeEventTypeEnum::AssistantThinkingDelta->value,
            RuntimeEventTypeEnum::ToolCallArgumentsDelta->value,
            RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
        ], true);
    }

    private function mergeCoalescedStreamEvent(RuntimeEvent $existing, RuntimeEvent $incoming): RuntimeEvent
    {
        $payload = $existing->payload;

        return match ($incoming->type) {
            RuntimeEventTypeEnum::AssistantTextStarted->value,
            RuntimeEventTypeEnum::AssistantTextDelta->value => new RuntimeEvent(
                type: $incoming->type,
                runId: $incoming->runId,
                seq: 0,
                payload: self::mergePayloadChunk($payload, $incoming->payload, 'text'),
            ),
            RuntimeEventTypeEnum::AssistantThinkingStarted->value,
            RuntimeEventTypeEnum::AssistantThinkingDelta->value => new RuntimeEvent(
                type: $incoming->type,
                runId: $incoming->runId,
                seq: 0,
                payload: self::mergePayloadChunk($payload, $incoming->payload, 'thinking'),
            ),
            RuntimeEventTypeEnum::ToolCallArgumentsDelta->value => new RuntimeEvent(
                type: $incoming->type,
                runId: $incoming->runId,
                seq: 0,
                payload: self::mergePayloadChunk($payload, $incoming->payload, 'partial_json'),
            ),
            RuntimeEventTypeEnum::ToolExecutionOutputDelta->value => $this->mergeToolExecutionOutputDelta($existing, $incoming),
            default => $incoming,
        };
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $chunk
     *
     * @return array<string, mixed>
     */
    private static function mergePayloadChunk(array $base, array $chunk, string $field): array
    {
        $merged = array_merge($base, $chunk);
        $merged[$field] = (string) ($base[$field] ?? '').(string) ($chunk[$field] ?? '');

        return $merged;
    }

    private function mergeToolExecutionOutputDelta(RuntimeEvent $existing, RuntimeEvent $incoming): RuntimeEvent
    {
        $base = $existing->payload;
        $chunk = $incoming->payload;

        if (isset($chunk['subagent_progress']) && \is_array($chunk['subagent_progress'])) {
            return $incoming;
        }

        if (isset($base['subagent_progress']) && \is_array($base['subagent_progress'])) {
            return $existing;
        }

        return new RuntimeEvent(
            type: $incoming->type,
            runId: $incoming->runId,
            seq: 0,
            payload: self::mergePayloadChunk($base, $chunk, 'delta'),
        );
    }

    private static function streamCoalesceKey(RuntimeEvent $event): string
    {
        $blockId = self::payloadString($event->payload, 'block_id');
        if ('' !== $blockId) {
            return 'block:'.$blockId;
        }

        $toolCallId = self::payloadString($event->payload, 'tool_call_id');
        if ('' !== $toolCallId) {
            return 'tool:'.$toolCallId;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function payloadString(array $payload, string $key): string
    {
        if (!isset($payload[$key])) {
            return '';
        }

        return (string) $payload[$key];
    }
}
