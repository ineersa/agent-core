<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;

/**
 * Canonical parent subagent_progress append using explicit stored parent tool correlation.
 */
class SubagentProgressEventAppender
{
    public function __construct(
        private CommittedRunEventAppender $committedRunEventAppender,
    ) {
    }

    /**
     * @param array<string, mixed> $progress
     */
    public function append(
        string $parentRunId,
        int $parentTurnNo,
        string $parentToolCallId,
        int $parentOrderIndex,
        string $toolName,
        array $progress,
    ): RunEvent {
        $event = new RunEvent(
            runId: $parentRunId,
            seq: 0,
            turnNo: $parentTurnNo,
            type: RunEventTypeEnum::ToolExecutionUpdate->value,
            payload: [
                'tool_call_id' => $parentToolCallId,
                'tool_name' => $toolName,
                'delta' => '',
                'subagent_progress' => $progress,
                'order_index' => $parentOrderIndex,
            ],
        );

        // seq 0 is deliberately unallocated; the committed store atomically assigns persisted seq
        // and CommittedRunEventAppender synchronizes parent RunState.lastSeq.
        return $this->committedRunEventAppender->append($event);
    }
}
