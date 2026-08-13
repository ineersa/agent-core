<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Canonical parent subagent_progress append using explicit stored parent tool correlation.
 *
 * Accepts a typed snapshot and normalizes to the canonical snake_case array only
 * when writing the RunEvent payload (persisted/public boundary).
 */
class SubagentProgressEventAppender
{
    public function __construct(
        private CommittedRunEventAppender $committedRunEventAppender,
        private NormalizerInterface $normalizer,
    ) {
    }

    public function append(
        string $parentRunId,
        int $parentTurnNo,
        string $parentToolCallId,
        int $parentOrderIndex,
        string $toolName,
        SubagentProgressSnapshotInterface $progress,
    ): RunEvent {
        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalizer->normalize(
            $progress,
            null,
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );

        $event = new RunEvent(
            runId: $parentRunId,
            seq: 0,
            turnNo: $parentTurnNo,
            type: RunEventTypeEnum::ToolExecutionUpdate->value,
            payload: [
                'tool_call_id' => $parentToolCallId,
                'tool_name' => $toolName,
                'delta' => '',
                'subagent_progress' => $normalized,
                'order_index' => $parentOrderIndex,
            ],
        );

        // seq 0 is deliberately unallocated; the committed store atomically assigns persisted seq
        // and CommittedRunEventAppender synchronizes parent RunState.lastSeq.
        return $this->committedRunEventAppender->append($event);
    }
}
