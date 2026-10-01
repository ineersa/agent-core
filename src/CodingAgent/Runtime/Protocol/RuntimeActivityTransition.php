<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Pure activity transition shared by TUI and cold-resume projections.
 *
 * String activity values match {@see \Ineersa\Tui\Runtime\RunActivityStateEnum}.
 * CodingAgent owns the transition table so TUI can wrap enums without
 * CodingAgent depending on Tui types.
 */
final class RuntimeActivityTransition
{
    public static function next(string $current, RuntimeEvent $event): string
    {
        if (self::isTerminal($current)
            && !('completed' === $current && RuntimeEventTypeEnum::CompactionStarted->value === $event->type)
            && !self::allowsContinuationAfterTerminal($event)) {
            return $current;
        }

        if ('cancelling' === $current) {
            return match ($event->type) {
                RuntimeEventTypeEnum::CancellationRequested->value,
                RuntimeEventTypeEnum::OperationCancelled->value => 'cancelling',
                RuntimeEventTypeEnum::ToolExecutionCancelled->value,
                RuntimeEventTypeEnum::ToolExecutionFailed->value,
                RuntimeEventTypeEnum::ToolExecutionCompleted->value,
                RuntimeEventTypeEnum::RunCancelled->value,
                RuntimeEventTypeEnum::TurnCancelled->value => 'cancelled',
                RuntimeEventTypeEnum::RunCompleted->value => 'completed',
                RuntimeEventTypeEnum::RunFailed->value,
                RuntimeEventTypeEnum::TurnFailed->value,
                RuntimeEventTypeEnum::AssistantMessageFailed->value => 'failed',
                RuntimeEventTypeEnum::CompactionCompleted->value,
                RuntimeEventTypeEnum::CompactionFailed->value => 'cancelling',
                default => 'cancelling',
            };
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
            RuntimeEventTypeEnum::HumanInputRejected->value => 'running',

            RuntimeEventTypeEnum::HumanInputRequested->value,
            RuntimeEventTypeEnum::ApprovalRequested->value => 'waiting_human',

            RuntimeEventTypeEnum::CancellationRequested->value,
            RuntimeEventTypeEnum::OperationCancelled->value,
            RuntimeEventTypeEnum::ToolExecutionCancelled->value => 'cancelling',

            RuntimeEventTypeEnum::RunCompleted->value => 'completed',

            RuntimeEventTypeEnum::RunFailed->value,
            RuntimeEventTypeEnum::TurnFailed->value,
            RuntimeEventTypeEnum::AssistantMessageFailed->value => 'failed',

            RuntimeEventTypeEnum::RunCancelled->value,
            RuntimeEventTypeEnum::TurnCancelled->value => 'cancelled',

            RuntimeEventTypeEnum::CompactionStarted->value => 'compacting',
            RuntimeEventTypeEnum::CompactionCompleted->value,
            RuntimeEventTypeEnum::CompactionFailed->value => 'completed',

            default => $current,
        };
    }

    public static function isTerminal(string $activity): bool
    {
        return \in_array($activity, ['completed', 'failed', 'cancelled'], true);
    }

    private static function allowsContinuationAfterTerminal(RuntimeEvent $event): bool
    {
        if ($event->seq <= 0) {
            return false;
        }

        return match ($event->type) {
            RuntimeEventTypeEnum::RunStarted->value,
            RuntimeEventTypeEnum::TurnStarted->value,
            RuntimeEventTypeEnum::UserMessageSubmitted->value,
            RuntimeEventTypeEnum::HumanInputRequested->value,
            RuntimeEventTypeEnum::ApprovalRequested->value,
            RuntimeEventTypeEnum::ToolCallStarted->value,
            RuntimeEventTypeEnum::ToolCallArgumentsDelta->value,
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value,
            RuntimeEventTypeEnum::ToolExecutionStarted->value,
            RuntimeEventTypeEnum::ToolExecutionOutputDelta->value => true,
            default => false,
        };
    }
}
