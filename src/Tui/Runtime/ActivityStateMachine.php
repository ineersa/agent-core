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
        //
        // EXCEPTION: Completed → Compacting for after-turn maintenance
        // compaction so Escape can cancel (session 13).  The after-turn
        // hook dispatches auto-compaction after the turn commits; the
        // compaction.started event arrives while activity is still
        // Completed.
        //
        // Failed and Cancelled remain fully terminal — CompactionStarted
        // must not reopen a cancelled or failed run.
        if ($current->isTerminal()
            && !(RunActivityStateEnum::Completed === $current
                 && RuntimeEventTypeEnum::CompactionStarted->value === $event->type)) {
            return $current;
        }

        // Cancelling is sticky: mid-turn streaming deltas belong to the run
        // we are aborting and must not regress activity back to Running.
        // Only cancel-class and terminal events move out of Cancelling.
        //
        // The new-run path is safe because the TUI explicitly sets activity
        // to Starting when dispatching a fresh run after cancellation completes
        // (see RuntimeEventPoller::poll() lines 102-117). The stickiness gate
        // only blocks mid-turn deltas on the dying run — a clean RunStarted
        // or TurnStarted for a genuinely new run arrives with current=Starting,
        // not Cancelling, and transitions normally.
        if (RunActivityStateEnum::Cancelling === $current) {
            return match ($event->type) {
                // Cancel-request events: remain in Cancelling (confirming state)
                RuntimeEventTypeEnum::CancellationRequested->value,
                RuntimeEventTypeEnum::OperationCancelled->value => RunActivityStateEnum::Cancelling,
                // Terminal tool end (including user-cancelled tool result mapped by RuntimeEventTranslator)
                RuntimeEventTypeEnum::ToolExecutionCancelled->value,
                RuntimeEventTypeEnum::ToolExecutionFailed->value,
                RuntimeEventTypeEnum::ToolExecutionCompleted->value => RunActivityStateEnum::Cancelled,
                // Terminal events: cancel completes or run fails
                RuntimeEventTypeEnum::RunCancelled->value,
                RuntimeEventTypeEnum::TurnCancelled->value => RunActivityStateEnum::Cancelled,
                RuntimeEventTypeEnum::RunCompleted->value => RunActivityStateEnum::Completed,
                RuntimeEventTypeEnum::RunFailed->value,
                RuntimeEventTypeEnum::TurnFailed->value,
                RuntimeEventTypeEnum::AssistantMessageFailed->value => RunActivityStateEnum::Failed,
                // Compaction events during cancellation: the
                // sticky-gate treats them like mid-turn deltas
                // and stays Cancelling.  The runtime handler
                // resolves Cancelling→Cancelled when the result
                // arrives.
                RuntimeEventTypeEnum::CompactionCompleted->value,
                RuntimeEventTypeEnum::CompactionFailed->value => RunActivityStateEnum::Cancelling,
                // All other events (mid-turn streaming deltas, TurnStarted,
                // tool-call deltas, etc.) belong to the dying run and must
                // not regress to Running.
                default => RunActivityStateEnum::Cancelling,
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

            // Compaction events: transition from Completed/idle to
            // Compacting so CancelListener can send cancel.  After
            // compaction resolves, return to Completed.
            RuntimeEventTypeEnum::CompactionStarted->value => RunActivityStateEnum::Compacting,
            RuntimeEventTypeEnum::CompactionCompleted->value,
            RuntimeEventTypeEnum::CompactionFailed->value => RunActivityStateEnum::Completed,

            default => $current, // No transition for unknown/streaming/internal events
        };
    }
}
