<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Runtime\ActivityStateMachine;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for ActivityStateMachine transitions.
 *
 * @covers \Ineersa\Tui\Runtime\ActivityStateMachine
 */
final class ActivityStateMachineTest extends TestCase
{
    /** @return iterable<array{string, RunActivityStateEnum, RunActivityStateEnum}> */
    public static function provideRunningTransitions(): iterable
    {
        $eventTypes = [
            RuntimeEventTypeEnum::RunStarted,
            RuntimeEventTypeEnum::TurnStarted,
            RuntimeEventTypeEnum::TurnCompleted,
            RuntimeEventTypeEnum::AssistantMessageStarted,
            RuntimeEventTypeEnum::AssistantTextStarted,
            RuntimeEventTypeEnum::AssistantTextDelta,
            RuntimeEventTypeEnum::AssistantTextCompleted,
            RuntimeEventTypeEnum::AssistantThinkingStarted,
            RuntimeEventTypeEnum::AssistantThinkingDelta,
            RuntimeEventTypeEnum::AssistantThinkingCompleted,
            RuntimeEventTypeEnum::AssistantMessageCompleted,
            RuntimeEventTypeEnum::ToolCallStarted,
            RuntimeEventTypeEnum::ToolCallArgumentsDelta,
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted,
            RuntimeEventTypeEnum::ToolExecutionStarted,
            RuntimeEventTypeEnum::ToolExecutionOutputDelta,
            RuntimeEventTypeEnum::ToolExecutionCompleted,
            RuntimeEventTypeEnum::ToolExecutionFailed,
            RuntimeEventTypeEnum::UserMessageSubmitted,
            RuntimeEventTypeEnum::HumanInputAnswered,
            RuntimeEventTypeEnum::ApprovalApproved,
            RuntimeEventTypeEnum::ApprovalRejected,
            RuntimeEventTypeEnum::HumanInputRejected,
        ];

        foreach ($eventTypes as $eventType) {
            yield $eventType->value => [
                $eventType->value,
                RunActivityStateEnum::Running,
                RunActivityStateEnum::Running,
            ];
        }
    }

    /** @return iterable<array{string, RunActivityStateEnum, RunActivityStateEnum}> */
    public static function provideWaitingHumanTransitions(): iterable
    {
        yield 'HumanInputRequested' => [
            RuntimeEventTypeEnum::HumanInputRequested->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::WaitingHuman,
        ];
        yield 'ApprovalRequested' => [
            RuntimeEventTypeEnum::ApprovalRequested->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::WaitingHuman,
        ];
    }

    /** @return iterable<array{string, RunActivityStateEnum, RunActivityStateEnum}> */
    public static function provideCancellingTransitions(): iterable
    {
        yield 'CancellationRequested' => [
            RuntimeEventTypeEnum::CancellationRequested->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Cancelling,
        ];
        yield 'OperationCancelled' => [
            RuntimeEventTypeEnum::OperationCancelled->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Cancelling,
        ];
        yield 'ToolExecutionCancelled' => [
            RuntimeEventTypeEnum::ToolExecutionCancelled->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Cancelling,
        ];
    }

    /** @return iterable<array{string, RunActivityStateEnum, RunActivityStateEnum}> */
    public static function provideTerminalTransitions(): iterable
    {
        yield 'RunCompleted' => [
            RuntimeEventTypeEnum::RunCompleted->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Completed,
        ];
        yield 'RunFailed' => [
            RuntimeEventTypeEnum::RunFailed->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Failed,
        ];
        yield 'TurnFailed' => [
            RuntimeEventTypeEnum::TurnFailed->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Failed,
        ];
        yield 'AssistantMessageFailed' => [
            RuntimeEventTypeEnum::AssistantMessageFailed->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Failed,
        ];
        yield 'RunCancelled' => [
            RuntimeEventTypeEnum::RunCancelled->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Cancelled,
        ];
        yield 'TurnCancelled' => [
            RuntimeEventTypeEnum::TurnCancelled->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Cancelled,
        ];
    }

    /** @return iterable<array{string, RunActivityStateEnum, RunActivityStateEnum}> */
    public static function provideDefaultTransitions(): iterable
    {
        yield 'unknown event type' => [
            'some_random_event',
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Running,
        ];
        yield 'streaming event (seq=0 passthrough)' => [
            RuntimeEventTypeEnum::AssistantTextDelta->value,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Running,
        ];
    }

    // ── Tests ──

    #[DataProvider('provideRunningTransitions')]
    public function testRunningTransitions(string $eventType, RunActivityStateEnum $current, RunActivityStateEnum $expected): void
    {
        $event = new RuntimeEvent(type: $eventType, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition($current, $event);
        $this->assertSame($expected, $result);
    }

    #[DataProvider('provideWaitingHumanTransitions')]
    public function testWaitingHumanTransitions(string $eventType, RunActivityStateEnum $current, RunActivityStateEnum $expected): void
    {
        $event = new RuntimeEvent(type: $eventType, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition($current, $event);
        $this->assertSame($expected, $result);
    }

    #[DataProvider('provideCancellingTransitions')]
    public function testCancellingTransitions(string $eventType, RunActivityStateEnum $current, RunActivityStateEnum $expected): void
    {
        $event = new RuntimeEvent(type: $eventType, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition($current, $event);
        $this->assertSame($expected, $result);
    }

    #[DataProvider('provideTerminalTransitions')]
    public function testTerminalTransitions(string $eventType, RunActivityStateEnum $current, RunActivityStateEnum $expected): void
    {
        $event = new RuntimeEvent(type: $eventType, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition($current, $event);
        $this->assertSame($expected, $result);
    }

    #[DataProvider('provideDefaultTransitions')]
    public function testDefaultTransitions(string $eventType, RunActivityStateEnum $current, RunActivityStateEnum $expected): void
    {
        $event = new RuntimeEvent(type: $eventType, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition($current, $event);
        $this->assertSame($expected, $result);
    }

    public function testTerminalGuardPreventsOverride(): void
    {
        // Starting from a terminal state, any event should return the same terminal state
        $terminalStates = [
            RunActivityStateEnum::Completed,
            RunActivityStateEnum::Failed,
            RunActivityStateEnum::Cancelled,
        ];

        $event = new RuntimeEvent(type: RuntimeEventTypeEnum::RunStarted->value, runId: 'test', seq: 1);

        foreach ($terminalStates as $terminal) {
            $result = ActivityStateMachine::transition($terminal, $event);
            $this->assertSame($terminal, $result, 'Terminal state must not be overridden');
        }
    }

    public function testIdleToRunning(): void
    {
        $event = new RuntimeEvent(type: RuntimeEventTypeEnum::RunStarted->value, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Idle, $event);
        $this->assertSame(RunActivityStateEnum::Running, $result);
    }

    public function testStartingToRunning(): void
    {
        $event = new RuntimeEvent(type: RuntimeEventTypeEnum::TurnStarted->value, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Starting, $event);
        $this->assertSame(RunActivityStateEnum::Running, $result);
    }

    public function testCompletedIsStickyEvenForRunStarted(): void
    {
        $event = new RuntimeEvent(type: RuntimeEventTypeEnum::RunStarted->value, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Completed, $event);
        $this->assertSame(RunActivityStateEnum::Completed, $result);
    }

    public function testCancelledIsSticky(): void
    {
        $event = new RuntimeEvent(type: RuntimeEventTypeEnum::RunStarted->value, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Cancelled, $event);
        $this->assertSame(RunActivityStateEnum::Cancelled, $result);
    }

    // ── Cancelling stickiness tests (issue #151 cosmetic flicker fix) ──

    /** @return iterable<array{string, RunActivityStateEnum, RunActivityStateEnum}> */
    public static function provideCancellingSticksOnMidTurnDeltas(): iterable
    {
        // Mid-turn streaming deltas must NOT regress Cancelling to Running.
        $deltaTypes = [
            'AssistantTextDelta' => RuntimeEventTypeEnum::AssistantTextDelta->value,
            'AssistantThinkingDelta' => RuntimeEventTypeEnum::AssistantThinkingDelta->value,
            'ToolCallStarted' => RuntimeEventTypeEnum::ToolCallStarted->value,
            'ToolExecutionOutputDelta' => RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            'TurnStarted' => RuntimeEventTypeEnum::TurnStarted->value,
            'TurnCompleted' => RuntimeEventTypeEnum::TurnCompleted->value,
            'AssistantTextCompleted' => RuntimeEventTypeEnum::AssistantTextCompleted->value,
        ];

        foreach ($deltaTypes as $label => $type) {
            yield $label => [$type, RunActivityStateEnum::Cancelling, RunActivityStateEnum::Cancelling];
        }
    }

    /**
     * While Cancelling is sticky, a clean new-run transition from Starting
     * must still work normally — the stickiness must not block fresh runs.
     * The TUI sets activity = Starting when dispatching a follow-up after
     * RunCancelled (RuntimeEventPoller lines 102-117).
     */
    #[DataProvider('provideCancellingSticksOnMidTurnDeltas')]
    public function testCancellingSticksOnMidTurnDeltas(string $eventType, RunActivityStateEnum $current, RunActivityStateEnum $expected): void
    {
        $event = new RuntimeEvent(type: $eventType, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition($current, $event);
        $this->assertSame($expected, $result, "Cancelling should stay Cancelling on $eventType");
    }

    /** @return iterable<array{string, RunActivityStateEnum, RunActivityStateEnum}> */
    public static function provideCancellingTerminalTransitions(): iterable
    {
        yield 'RunCancelled' => [
            RuntimeEventTypeEnum::RunCancelled->value,
            RunActivityStateEnum::Cancelling,
            RunActivityStateEnum::Cancelled,
        ];
        yield 'TurnCancelled' => [
            RuntimeEventTypeEnum::TurnCancelled->value,
            RunActivityStateEnum::Cancelling,
            RunActivityStateEnum::Cancelled,
        ];
        yield 'RunCompleted' => [
            RuntimeEventTypeEnum::RunCompleted->value,
            RunActivityStateEnum::Cancelling,
            RunActivityStateEnum::Completed,
        ];
        yield 'RunFailed' => [
            RuntimeEventTypeEnum::RunFailed->value,
            RunActivityStateEnum::Cancelling,
            RunActivityStateEnum::Failed,
        ];
        yield 'TurnFailed' => [
            RuntimeEventTypeEnum::TurnFailed->value,
            RunActivityStateEnum::Cancelling,
            RunActivityStateEnum::Failed,
        ];
        yield 'AssistantMessageFailed' => [
            RuntimeEventTypeEnum::AssistantMessageFailed->value,
            RunActivityStateEnum::Cancelling,
            RunActivityStateEnum::Failed,
        ];
    }

    #[DataProvider('provideCancellingTerminalTransitions')]
    public function testCancellingAllowsTerminalTransitions(string $eventType, RunActivityStateEnum $current, RunActivityStateEnum $expected): void
    {
        $event = new RuntimeEvent(type: $eventType, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition($current, $event);
        $this->assertSame($expected, $result, "Cancelling should allow $eventType transition");
    }

    /**
     * Cancel-class events while already Cancelling stay Cancelling
     * (they confirm the state, don't escalate).
     */
    public function testCancellingRepeatedCancelEventsStayCancelling(): void
    {
        foreach ([
            RuntimeEventTypeEnum::CancellationRequested->value,
            RuntimeEventTypeEnum::OperationCancelled->value,
            RuntimeEventTypeEnum::ToolExecutionCancelled->value,
        ] as $type) {
            $event = new RuntimeEvent(type: $type, runId: 'test', seq: 1);
            $result = ActivityStateMachine::transition(RunActivityStateEnum::Cancelling, $event);
            $this->assertSame(RunActivityStateEnum::Cancelling, $result, "Repeat $type should stay Cancelling");
        }
    }

    /**
     * Unknown events while Cancelling stay Cancelling (default arm).
     */
    public function testCancellingUnknownEventStaysCancelling(): void
    {
        $event = new RuntimeEvent(type: 'some_random_internal_event', runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Cancelling, $event);
        $this->assertSame(RunActivityStateEnum::Cancelling, $result);
    }


    public function testCancellingToolExecutionFailedTransitionsToCancelled(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionFailed->value,
            runId: 'test',
            seq: 128,
            payload: ['tool_call_id' => 'call_1', 'is_error' => true, 'result' => 'Tool execution cancelled by user.'],
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Cancelling, $event);
        $this->assertSame(RunActivityStateEnum::Cancelled, $result);
    }

    public function testCancellingToolExecutionCompletedTransitionsToCancelledWhenRunAlreadyEnded(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            runId: 'test',
            seq: 132,
            payload: ['tool_call_id' => 'call_1'],
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Cancelling, $event);
        $this->assertSame(RunActivityStateEnum::Cancelled, $result);
    }

    // ── Compaction event transitions (session 13: Escape can't cancel) ──

    /**
     * Thesis: when the TUI activity is Completed (after a turn) and an
     * auto-compaction starts, the activity must transition to Compacting
     * so that CancelListener recognizes the run is still cancellable.
     *
     * On HEAD (RED): CompactionStarted is not in the transition table —
     * ActivityStateMachine returns the current state (Completed).
     * Completed.isActive() is false, so CancelListener clears the editor
     * instead of sending cancel.  The user cannot abort a stuck compaction.
     */
    public function testCompactionStartedFromCompletedTransitionsToCompacting(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionStarted->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Completed, $event);
        $this->assertSame(
            RunActivityStateEnum::Compacting,
            $result,
            'CompactionStarted from Completed must become Compacting so Escape can cancel.',
        );
    }

    /**
     * CompactionStarted from Idle (no active run yet) transitions to Compacting.
     */
    public function testCompactionStartedFromIdleTransitionsToCompacting(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionStarted->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Idle, $event);
        $this->assertSame(RunActivityStateEnum::Compacting, $result);
    }

    /**
     * CompactionStarted from Running transitions to Compacting.
     * Pre-LLM guard compaction starts while the run is Running —
     * activity transitions to Compacting so Escape can cancel if
     * the compaction hangs.  Compacting.isActive() is false so
     * SubmitListener queues user messages instead of sending steer.
     */
    public function testCompactionStartedFromRunningTransitionsToCompacting(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionStarted->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Running, $event);
        $this->assertSame(RunActivityStateEnum::Compacting, $result);
    }

    /**
     * CompactionCompleted from Compacting returns to Completed (default
     * for after-turn maintenance compaction).
     */
    public function testCompactionCompletedFromCompactingGoesToCompleted(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionCompleted->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Compacting, $event);
        $this->assertSame(RunActivityStateEnum::Completed, $result);
    }

    /**
     * CompactionFailed from Compacting returns to Completed.
     */
    public function testCompactionFailedFromCompactingGoesToCompleted(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionFailed->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Compacting, $event);
        $this->assertSame(RunActivityStateEnum::Completed, $result);
    }

    /**
     * CompactionStarted from Cancelled stays Cancelled — terminal
     * must not be reopened by auto-compaction.
     */
    public function testCompactionStartedOnCancelledStaysCancelled(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionStarted->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Cancelled, $event);
        $this->assertSame(RunActivityStateEnum::Cancelled, $result,
            'Terminal Cancelled must survive CompactionStarted.'
        );
    }

    /**
     * CompactionStarted from Failed stays Failed — terminal
     * must not be reopened by auto-compaction.
     */
    public function testCompactionStartedOnFailedStaysFailed(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionStarted->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Failed, $event);
        $this->assertSame(RunActivityStateEnum::Failed, $result,
            'Terminal Failed must survive CompactionStarted.'
        );
    }

    /**
     * CompactionCompleted is a no-op on terminal states (Completed
     * already).  CompactionCompleted should not undo Cancelled/Failed.
     */
    public function testCompactionCompletedOnCancelledStaysCancelled(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionCompleted->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Cancelled, $event);
        $this->assertSame(RunActivityStateEnum::Cancelled, $result,
            'Terminal Cancelled must survive CompactionCompleted.');
    }

    /**
     * CompactionCompleted from Failed stays Failed — terminal
     * must not be undone.
     */
    public function testCompactionCompletedOnFailedStaysFailed(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionCompleted->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Failed, $event);
        $this->assertSame(RunActivityStateEnum::Failed, $result,
            'Terminal Failed must survive CompactionCompleted.'
        );
    }

    /**
     * CompactionCompleted from Cancelling stays Cancelling (cancelling
     * is sticky — compaction completion does not move out of it).
     */
    public function testCompactionCompletedFromCancellingStaysCancelling(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionCompleted->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Cancelling, $event);
        $this->assertSame(RunActivityStateEnum::Cancelling, $result,
            'Cancelling must survive CompactionCompleted — stick gate blocks mid-run events.');
    }

    /**
     * CompactionCompleted from Running goes to Completed (compaction
     * finished while run was active — the run may still continue but
     * compaction maintenance is done).
     */
    public function testCompactionCompletedFromRunningGoesToCompleted(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionCompleted->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Running, $event);
        $this->assertSame(RunActivityStateEnum::Completed, $result);
    }

    /**
     * CompactionFailed from Running goes to Completed.
     */
    public function testCompactionFailedFromRunningGoesToCompleted(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionFailed->value,
            runId: 'test',
            seq: 1,
        );
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Running, $event);
        $this->assertSame(RunActivityStateEnum::Completed, $result);
    }
}
