<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\ApplyShellCommandHandler;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ApplyShellCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\CurrentOperationKindEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: direct bang shells become canonical turn-owned command+effect
 * events under RunMessageProcessor semantics. Pre-conversation shells stay
 * turn 0, active shells attach to the current turn, and terminal conversational
 * shells seed a new turn so retained-history discard can abandon them.
 */
#[CoversClass(ApplyShellCommandHandler::class)]
final class ApplyShellCommandHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     status: RunStatus,
     *     turnNo: int,
     *     lastSeq: int,
     *     expectedOwningTurn: int,
     *     expectedCommandTurn: int,
     *     expectedStandalone: bool,
     *     expectedEventTypes: list<string>,
     *     expectedStatus: RunStatus
     * }>
     */
    public static function ownershipCases(): iterable
    {
        yield 'pre_conversation_queued' => [
            'status' => RunStatus::Queued,
            'turnNo' => 0,
            'lastSeq' => 0,
            'expectedOwningTurn' => 0,
            'expectedCommandTurn' => 0,
            'expectedStandalone' => true,
            'expectedEventTypes' => [RunEventTypeEnum::AgentCommandApplied->value],
            'expectedStatus' => RunStatus::Running,
        ];

        yield 'active_current_turn' => [
            'status' => RunStatus::Running,
            'turnNo' => 2,
            'lastSeq' => 9,
            'expectedOwningTurn' => 2,
            'expectedCommandTurn' => 2,
            'expectedStandalone' => false,
            'expectedEventTypes' => [RunEventTypeEnum::AgentCommandApplied->value],
            'expectedStatus' => RunStatus::Running,
        ];

        yield 'terminal_child_turn' => [
            'status' => RunStatus::Completed,
            'turnNo' => 1,
            'lastSeq' => 5,
            'expectedOwningTurn' => 6,
            'expectedCommandTurn' => 1,
            'expectedStandalone' => true,
            'expectedEventTypes' => [
                RunEventTypeEnum::AgentCommandApplied->value,
                RunEventTypeEnum::TurnAdvanced->value,
                RunEventTypeEnum::HistoryPositionSet->value,
            ],
            'expectedStatus' => RunStatus::Running,
        ];
    }

    /**
     * @param list<string> $expectedEventTypes
     */
    #[DataProvider('ownershipCases')]
    public function testOwnershipAndCanonicalEvents(
        RunStatus $status,
        int $turnNo,
        int $lastSeq,
        int $expectedOwningTurn,
        int $expectedCommandTurn,
        bool $expectedStandalone,
        array $expectedEventTypes,
        RunStatus $expectedStatus,
    ): void {
        $handler = new ApplyShellCommandHandler(new EventFactory(), new InMemoryEventStore());
        $rawInput = '!printf BANG_OWNERSHIP';
        $message = new ApplyShellCommand(
            runId: 'run-shell-1',
            turnNo: 0,
            stepId: 'shell-step-1',
            attempt: 1,
            idempotencyKey: 'shell-idem-1',
            rawInput: $rawInput,
        );
        $state = new RunState(
            runId: 'run-shell-1',
            status: $status,
            version: 3,
            turnNo: $turnNo,
            lastSeq: $lastSeq,
            messages: [],
            activeStepId: 'existing-step',
            model: 'test-model');

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame($expectedStatus, $result->nextState->status);
        $this->assertSame($expectedOwningTurn, $result->nextState->turnNo);
        $this->assertSame($lastSeq + \count($expectedEventTypes), $result->nextState->lastSeq);
        $this->assertSame($state->messages, $result->nextState->messages, 'Shell must not pollute model messages');

        $this->assertCount(\count($expectedEventTypes), $result->events);
        foreach ($expectedEventTypes as $index => $type) {
            $this->assertSame($type, $result->events[$index]->type);
        }

        $commandEvent = $result->events[0];
        $this->assertSame($expectedCommandTurn, $commandEvent->turnNo);
        $this->assertSame('shell_command', $commandEvent->payload['kind'] ?? null);
        $this->assertSame($rawInput, $commandEvent->payload['text'] ?? null);
        $this->assertSame('shell-idem-1', $commandEvent->payload['idempotency_key'] ?? null);
        $this->assertSame($expectedStandalone, $commandEvent->payload['standalone'] ?? null);
        $this->assertSame($expectedOwningTurn, $commandEvent->payload['operation_turn_no'] ?? null);
        $this->assertSame('shell-step-1', $commandEvent->payload['operation_step_id'] ?? null);
        $this->assertSame(1, $commandEvent->payload['operation_attempt'] ?? null);
        $this->assertSame('shell-idem-1', $commandEvent->payload['operation_idempotency_key'] ?? null);

        if (\count($expectedEventTypes) > 1) {
            $this->assertSame($expectedOwningTurn, $result->events[1]->payload['turn_no'] ?? null);
            $this->assertSame(CurrentOperationKindEnum::Shell->value, $result->events[1]->payload['operation_kind'] ?? null);
            $this->assertSame(1, $result->events[1]->payload['operation_attempt'] ?? null);
            $this->assertSame('shell-idem-1', $result->events[1]->payload['operation_idempotency_key'] ?? null);
            $this->assertSame($expectedOwningTurn, $result->events[2]->payload['position_turn_no'] ?? null);
            $this->assertSame('shell_command', $result->events[2]->payload['reason'] ?? null);
        }

        $this->assertCount(1, $result->effects);
        $this->assertInstanceOf(ExecuteShellToolCall::class, $result->effects[0]);
        $effect = $result->effects[0];
        $this->assertSame($expectedOwningTurn, $effect->turnNo());
        $this->assertSame('printf BANG_OWNERSHIP', $effect->commandText);
        $this->assertSame($expectedStandalone, $effect->standalone);
        $this->assertSame('sh_'.hash('sha256', 'shell-idem-1'), $effect->toolCallId);

        if ($expectedStandalone) {
            $replayed = (new RunStateReducer())->replay(RunState::queued('run-shell-1'), $result->events);
            $this->assertNotNull($replayed->currentOperation);
            $this->assertTrue($replayed->currentOperation->matches(
                CurrentOperationKindEnum::Shell,
                $expectedOwningTurn,
                'shell-step-1',
                1,
                'shell-idem-1',
            ));
        }
    }

    public function testCommittedStandaloneShellRedeliveryIsANoOp(): void
    {
        $handler = new ApplyShellCommandHandler(new EventFactory(), new InMemoryEventStore());
        $message = new ApplyShellCommand(
            runId: 'run-shell-duplicate',
            turnNo: 0,
            stepId: 'shell-step-duplicate',
            attempt: 1,
            idempotencyKey: 'shell-idem-duplicate',
            rawInput: '!printf once',
        );
        $committed = $handler->handle(
            $message,
            new RunState(runId: 'run-shell-duplicate', status: RunStatus::Queued, model: 'test-model'),
        )->nextState;

        $this->assertNotNull($committed);
        $redelivery = $handler->handle($message, $committed);

        $this->assertNull($redelivery->nextState);
        $this->assertSame([], $redelivery->events);
        $this->assertSame([], $redelivery->effects);
    }

    public function testCommittedAttachedShellRedeliveryIsANoOpWithoutReplacingActiveLlm(): void
    {
        $handler = new ApplyShellCommandHandler(new EventFactory(), new InMemoryEventStore());
        $message = new ApplyShellCommand(
            runId: 'run-attached-shell-duplicate',
            turnNo: 4,
            stepId: 'shell-step-attached',
            attempt: 1,
            idempotencyKey: 'shell-attached-key',
            rawInput: '!printf once',
        );
        $llm = new CurrentOperationDTO(CurrentOperationKindEnum::Llm, 4, 'llm-step', 1, 'llm-key');
        $state = new RunState(
            runId: 'run-attached-shell-duplicate',
            status: RunStatus::Running,
            turnNo: 4,
            activeStepId: 'llm-step',
            currentOperation: $llm,
        );

        $committed = $handler->handle($message, $state)->nextState;
        $this->assertNotNull($committed);
        $this->assertSame($llm, $committed->currentOperation);
        $this->assertArrayHasKey('sh_'.hash('sha256', 'shell-attached-key'), $committed->pendingShellToolCalls);

        $redelivery = $handler->handle($message, $committed);
        $this->assertNull($redelivery->nextState);
        $this->assertSame([], $redelivery->events);
        $this->assertSame([], $redelivery->effects);
    }

    public function testCompletedShellRedeliveryIsANoOpAfterLifecycleEnds(): void
    {
        $events = new InMemoryEventStore();
        $events->seed(new RunEvent(
            runId: 'run-completed-shell',
            seq: 7,
            turnNo: 4,
            type: RunEventTypeEnum::AgentCommandApplied->value,
            payload: ['kind' => 'shell_command', 'idempotency_key' => 'completed-shell-key', 'text' => '!printf once'],
        ));
        $handler = new ApplyShellCommandHandler(new EventFactory(), $events);
        $message = new ApplyShellCommand('run-completed-shell', 4, 'shell-step', 1, 'completed-shell-key', '!printf once');
        $state = new RunState(runId: 'run-completed-shell', status: RunStatus::Running, turnNo: 4, lastSeq: 9, activeStepId: 'llm-step');

        $result = $handler->handle($message, $state);

        $this->assertNull($result->nextState);
        $this->assertSame([], $result->events);
        $this->assertSame([], $result->effects);
        $this->assertSame(1, $events->reverseForCalls);
    }

    public function testRejectsInvalidRawInput(): void
    {
        $handler = new ApplyShellCommandHandler(new EventFactory(), new InMemoryEventStore());
        $state = new RunState(runId: 'run-shell-2', status: RunStatus::Queued, turnNo: 0, lastSeq: 0, model: 'test-model');

        $this->expectException(\InvalidArgumentException::class);
        $handler->handle(new ApplyShellCommand(
            runId: 'run-shell-2',
            turnNo: 0,
            stepId: 'step',
            attempt: 1,
            idempotencyKey: 'bad',
            rawInput: 'ls',
        ), $state);
    }
}
