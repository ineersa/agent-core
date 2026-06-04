<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Pure activity state transition for TUI run activity.
 *
 * Given the current activity state and a runtime event, computes
 * the next activity state. Terminal states (Completed, Failed, Cancelled)
 * are never overridden.
 *
 * Extracted from RuntimeEventPoller::updateActivity().
 */
final class ActivityStateMachine
{
    /**
     * Compute the next activity state based on the runtime event type.
     *
     * @param RunActivityStateEnum $current Current activity state
     * @param RuntimeEvent         $event   Incoming runtime event
     *
     * @return RunActivityStateEnum Next activity state (unchanged if terminal or unknown event)
     */
    public static function transition(RunActivityStateEnum $current, RuntimeEvent $event): RunActivityStateEnum
    {
        // Terminal states are never overridden by later events.
        if ($current->isTerminal()) {
            return $current;
        }

        return match ($event->type) {
            RuntimeEventTypeEnum::RunStarted->value,
            RuntimeEventTypeEnum::TurnStarted->value,
            RuntimeEventTypeEnum::TurnCompleted->value,
            RuntimeEventTypeEnum::AssistantMessageStarted->value,
            RuntimeEventTypeEnum::AssistantTextStarted->value,
            RuntimeEventTypeEnum::AssistantTextDelta->value,
            RuntimeEventTypeEnum::AssistantTextCompleted->value,
            RuntimeEventTypeEnum::AssistantThinkingStarted->value,
            RuntimeEventTypeEnum::AssistantThinkingDelta->value,
            RuntimeEventTypeEnum::AssistantThinkingCompleted->value,
            RuntimeEventTypeEnum::AssistantMessageCompleted->value,
            RuntimeEventTypeEnum::ToolCallStarted->value,
            RuntimeEventTypeEnum::ToolCallArgumentsDelta->value,
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value,
            RuntimeEventTypeEnum::ToolExecutionStarted->value,
            RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            RuntimeEventTypeEnum::ToolExecutionFailed->value,
            RuntimeEventTypeEnum::UserMessageSubmitted->value,
            RuntimeEventTypeEnum::HumanInputAnswered->value,
            RuntimeEventTypeEnum::ApprovalApproved->value,
            RuntimeEventTypeEnum::ApprovalRejected->value,
            RuntimeEventTypeEnum::HumanInputRejected->value => RunActivityStateEnum::Running,

            RuntimeEventTypeEnum::HumanInputRequested->value,
            RuntimeEventTypeEnum::ApprovalRequested->value => RunActivityStateEnum::WaitingHuman,

            RuntimeEventTypeEnum::CancellationRequested->value,
            RuntimeEventTypeEnum::OperationCancelled->value,
            RuntimeEventTypeEnum::ToolExecutionCancelled->value => RunActivityStateEnum::Cancelling,

            RuntimeEventTypeEnum::RunCompleted->value => RunActivityStateEnum::Completed,

            RuntimeEventTypeEnum::RunFailed->value,
            RuntimeEventTypeEnum::TurnFailed->value,
            RuntimeEventTypeEnum::AssistantMessageFailed->value => RunActivityStateEnum::Failed,

            RuntimeEventTypeEnum::RunCancelled->value,
            RuntimeEventTypeEnum::TurnCancelled->value => RunActivityStateEnum::Cancelled,

            default => $current, // No transition for unknown/streaming/internal events
        };
    }
}
