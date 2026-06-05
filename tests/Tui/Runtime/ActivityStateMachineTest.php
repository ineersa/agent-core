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
        self::assertSame($expected, $result);
    }

    #[DataProvider('provideWaitingHumanTransitions')]
    public function testWaitingHumanTransitions(string $eventType, RunActivityStateEnum $current, RunActivityStateEnum $expected): void
    {
        $event = new RuntimeEvent(type: $eventType, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition($current, $event);
        self::assertSame($expected, $result);
    }

    #[DataProvider('provideCancellingTransitions')]
    public function testCancellingTransitions(string $eventType, RunActivityStateEnum $current, RunActivityStateEnum $expected): void
    {
        $event = new RuntimeEvent(type: $eventType, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition($current, $event);
        self::assertSame($expected, $result);
    }

    #[DataProvider('provideTerminalTransitions')]
    public function testTerminalTransitions(string $eventType, RunActivityStateEnum $current, RunActivityStateEnum $expected): void
    {
        $event = new RuntimeEvent(type: $eventType, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition($current, $event);
        self::assertSame($expected, $result);
    }

    #[DataProvider('provideDefaultTransitions')]
    public function testDefaultTransitions(string $eventType, RunActivityStateEnum $current, RunActivityStateEnum $expected): void
    {
        $event = new RuntimeEvent(type: $eventType, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition($current, $event);
        self::assertSame($expected, $result);
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
            self::assertSame($terminal, $result, 'Terminal state must not be overridden');
        }
    }

    public function testIdleToRunning(): void
    {
        $event = new RuntimeEvent(type: RuntimeEventTypeEnum::RunStarted->value, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Idle, $event);
        self::assertSame(RunActivityStateEnum::Running, $result);
    }

    public function testStartingToRunning(): void
    {
        $event = new RuntimeEvent(type: RuntimeEventTypeEnum::TurnStarted->value, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Starting, $event);
        self::assertSame(RunActivityStateEnum::Running, $result);
    }

    public function testCompletedIsStickyEvenForRunStarted(): void
    {
        $event = new RuntimeEvent(type: RuntimeEventTypeEnum::RunStarted->value, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Completed, $event);
        self::assertSame(RunActivityStateEnum::Completed, $result);
    }

    public function testCancelledIsSticky(): void
    {
        $event = new RuntimeEvent(type: RuntimeEventTypeEnum::RunStarted->value, runId: 'test', seq: 1);
        $result = ActivityStateMachine::transition(RunActivityStateEnum::Cancelled, $event);
        self::assertSame(RunActivityStateEnum::Cancelled, $result);
    }
}
