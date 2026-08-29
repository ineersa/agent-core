<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\Builder\AdvanceRunMessageBuilder;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;

final class AdvanceRunHandlerTest extends TestCase
{
    public function testHandleProducesTurnAdvanceEventAndLlmExecutionEffect(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );
        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        $state = RunStateBuilder::create('run-advance-handler-1')
            ->withStatus(RunStatus::Running)
            ->withVersion(7)
            ->withTurnNo(2)
            ->withLastSeq(11)
            ->withActiveStepId('turn-2-step')
            ->build();

        $message = AdvanceRunMessageBuilder::create('run-advance-handler-1')
            ->withTurnNo(2)
            ->withStepId('turn-12-step')
            ->withIdempotencyKey('advance-idempotency-1')
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status);
        $this->assertSame(8, $result->nextState->version);
        $this->assertSame(12, $result->nextState->turnNo);
        $this->assertSame(13, $result->nextState->lastSeq);
        $this->assertSame('turn-12-step', $result->nextState->activeStepId);

        $this->assertCount(2, $result->events);
        $this->assertSame('turn_advanced', $result->events[0]->type);
        $this->assertSame('history_position_set', $result->events[1]->type);
        $this->assertSame(12, $result->events[1]->payload['position_turn_no']);
        $this->assertSame('continue', $result->events[1]->payload['reason']);
        $this->assertSame(12, $result->events[0]->payload['turn_no']);

        $this->assertCount(1, $result->effects);
        $this->assertInstanceOf(ExecuteLlmStep::class, $result->effects[0]);
        $this->assertSame(12, $result->effects[0]->turnNo());
        $this->assertSame('turn-12-step', $result->effects[0]->stepId());
        $this->assertFalse(property_exists($result->effects[0], 'model'), 'Scheduling must not snapshot a model onto ExecuteLlmStep.');

        $this->assertSame([], $result->postCommitEffects);
        $this->assertSame([], $result->postCommit);
    }

    public function testCommittedAdvanceDoesNotDrainLaterMailboxCommandAndNextAdvanceCanApplyIt(): void
    {
        $runId = 'run-advance-redelivery';
        $commandStore = new InMemoryCommandStore();
        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: new CommandMailboxPolicy($commandStore, new CommandRouter([])),
            eventFactory: new EventFactory(),
        );
        $first = AdvanceRunMessageBuilder::create($runId)
            ->withTurnNo(2)
            ->withStepId('advance-first')
            ->withIdempotencyKey('advance-first-key')
            ->build();

        $committed = $handler->handle($first, RunStateBuilder::create($runId)
            ->withStatus(RunStatus::Running)
            ->withTurnNo(2)
            ->withLastSeq(10)
            ->build());
        $this->assertNotNull($committed->nextState);

        $commandStore->enqueue(new PendingCommand(
            runId: $runId,
            kind: CoreCommandKind::FollowUp,
            idempotencyKey: 'queued-after-advance',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'later']]]],
            options: new CommandCancellationOptions(safe: false),
        ));

        $duplicate = $handler->handle($first, $committed->nextState);
        $this->assertNull($duplicate->nextState);
        $this->assertSame([], $duplicate->events);
        $this->assertSame([], $duplicate->effects);
        $this->assertCount(1, $commandStore->pending($runId), 'Duplicate AdvanceRun must stop before draining later mailbox work.');

        $completedState = $committed->nextState->with([
            'status' => RunStatus::Completed,
            'currentOperation' => null,
        ]);
        $next = $handler->handle(AdvanceRunMessageBuilder::create($runId)
            ->withTurnNo($completedState->turnNo)
            ->withStepId('advance-next')
            ->withIdempotencyKey('advance-next-key')
            ->build(), $completedState);

        $this->assertNotNull($next->nextState);
        $this->assertCount(1, $next->nextState->messages, 'A successor token may still drain the queued command.');
        $this->assertCount(1, $next->effects);
    }

    public function testCancelledRunWithFollowUpTransitionsToRunning(): void
    {
        $commandStore = new InMemoryCommandStore();

        // Pre-queue a FollowUp command so applyPendingTurnStartCommands produces boundary events
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-cancel-advance',
            kind: CoreCommandKind::FollowUp,
            idempotencyKey: 'followup-ik-1',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Next message']]]],
            options: new CommandCancellationOptions(safe: false),
        ));

        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        $state = RunStateBuilder::create('run-cancel-advance')
            ->withStatus(RunStatus::Cancelled)
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(10)
            ->withMessages([new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])])
            ->build();

        $message = AdvanceRunMessageBuilder::create('run-cancel-advance')
            ->withTurnNo(1)
            ->withStepId('turn-2-step')
            ->withIdempotencyKey('advance-cancel-1')
            ->build();

        $result = $handler->handle($message, $state);

        // AdvanceRun should transition Cancelled → Running and allocate from the canonical sequence high-water.
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status, 'Cancelled run with pending FollowUp should transition to Running');
        $this->assertSame(11, $result->nextState->turnNo, 'Turn should advance to max(lastSeq, turnNo)+1');
        $this->assertNull($result->nextState->errorMessage, 'errorMessage should be cleared when transitioning Cancelled → Running');

        // Should produce events including agent_command_applied, turn_advanced, and history_position_set
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_command_applied', $eventTypes, 'Expected agent_command_applied event');
        $this->assertContains('turn_advanced', $eventTypes, 'Expected turn_advanced event');
        $this->assertContains('history_position_set', $eventTypes, 'Expected history_position_set event');

        // Should produce an LLM step effect (the agent will process the follow-up)
        $this->assertCount(1, $result->effects);
        $this->assertInstanceOf(ExecuteLlmStep::class, $result->effects[0]);
    }

    public function testWaitingHumanRunWithFollowUpTransitionsToRunning(): void
    {
        $commandStore = new InMemoryCommandStore();

        // Pre-queue a FollowUp command so applyPendingTurnStartCommands produces boundary events
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-waiting-human-advance',
            kind: CoreCommandKind::FollowUp,
            idempotencyKey: 'followup-waiting-human-ik-1',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'yes']]]],
            options: new CommandCancellationOptions(safe: false),
        ));

        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        $state = RunStateBuilder::create('run-waiting-human-advance')
            ->withStatus(RunStatus::WaitingHuman)
            ->withVersion(5)
            ->withTurnNo(22)
            ->withLastSeq(278)
            ->withMessages([new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Please confirm']])])
            ->build();

        $message = AdvanceRunMessageBuilder::create('run-waiting-human-advance')
            ->withTurnNo(22)
            ->withStepId('turn-23-step')
            ->withIdempotencyKey('advance-waiting-human-1')
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status, 'WaitingHuman run with pending FollowUp should transition to Running');
        $this->assertSame(279, $result->nextState->turnNo, 'Turn should advance to max(lastSeq, turnNo)+1');
        $this->assertNull($result->nextState->errorMessage, 'errorMessage should be cleared when transitioning WaitingHuman → Running');

        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_command_applied', $eventTypes, 'Expected agent_command_applied event');
        $this->assertContains('turn_advanced', $eventTypes, 'Expected turn_advanced event');
        $this->assertContains('history_position_set', $eventTypes, 'Expected history_position_set event');

        $this->assertCount(1, $result->effects);
        $this->assertInstanceOf(ExecuteLlmStep::class, $result->effects[0]);
    }

    public function testHandleFirstTurnHasNullParentTurnNo(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        // Initial state: turnNo=0 (as after StartRun)
        $state = RunStateBuilder::create('run-root-turn')
            ->withStatus(RunStatus::Running)
            ->withVersion(1)
            ->withTurnNo(0)
            ->withLastSeq(3)
            ->build();

        $message = AdvanceRunMessageBuilder::create('run-root-turn')
            ->withTurnNo(0)
            ->withStepId('turn-1-step')
            ->withIdempotencyKey('root-advance-1')
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(4, $result->nextState->turnNo);

        // history_position_set must be present
        $this->assertCount(2, $result->events);
        $this->assertSame('turn_advanced', $result->events[0]->type);
        $this->assertSame('history_position_set', $result->events[1]->type);

        $this->assertSame(4, $result->events[0]->payload['turn_no']);
        $this->assertSame(4, $result->events[1]->payload['position_turn_no']);
        $this->assertNull($result->events[1]->payload['previous_position_turn_no']);
    }

    public function testCancelledRunWithNoPendingCommandsIsNoOp(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        $state = RunStateBuilder::create('run-cancel-noop')
            ->withStatus(RunStatus::Cancelled)
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(10)
            ->withMessages([new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])])
            ->build();

        $message = AdvanceRunMessageBuilder::create('run-cancel-noop')
            ->withTurnNo(1)
            ->withStepId('noop-step')
            ->withIdempotencyKey('advance-noop-1')
            ->build();

        $result = $handler->handle($message, $state);

        // When Cancelled with no pending commands, AdvanceRun should be a no-op
        $this->assertNull($result->nextState, 'No state change when Cancelled with no pending commands');
        $this->assertSame([], $result->events);
        $this->assertSame([], $result->effects);
    }

    public function testAdvanceWithUnresolvedPendingToolCallsIsNoOp(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        $state = RunStateBuilder::create('run-pending-tools')
            ->withStatus(RunStatus::Running)
            ->withVersion(5)
            ->withTurnNo(2)
            ->withLastSeq(10)
            ->withActiveStepId('turn-2-step')
            ->withPendingToolCalls(['tool-call-1' => false])
            ->build();

        $this->assertFalse($state->pendingToolCalls['tool-call-1'], 'Precondition: tool call not completed');

        $message = AdvanceRunMessageBuilder::create('run-pending-tools')
            ->withTurnNo(2)
            ->withStepId('turn-3-step')
            ->withIdempotencyKey('advance-pending-tools-1')
            ->build();

        $result = $handler->handle($message, $state);

        // AdvanceRun must be a no-op when there are unresolved tool calls
        $this->assertNull($result->nextState, 'No state change when tool calls are still pending');
        $this->assertSame([], $result->events, 'No events when tool calls are still pending');
        $this->assertSame([], $result->effects, 'No effects when tool calls are still pending');
        $this->assertSame([], $result->postCommit, 'No post-commit callbacks when tool calls are still pending');
    }

    public function testAdvanceWithMixedUnresolvedPendingToolCallsIsNoOp(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        $state = RunStateBuilder::create('run-mixed-tools')
            ->withStatus(RunStatus::Running)
            ->withVersion(6)
            ->withTurnNo(2)
            ->withLastSeq(12)
            ->withActiveStepId('turn-2-step')
            ->withPendingToolCalls([
                'tool-call-1' => true,
                'tool-call-2' => false,
            ])
            ->build();

        $this->assertTrue($state->pendingToolCalls['tool-call-1'], 'Precondition: tool-call-1 completed');
        $this->assertFalse($state->pendingToolCalls['tool-call-2'], 'Precondition: tool-call-2 not completed');

        $message = AdvanceRunMessageBuilder::create('run-mixed-tools')
            ->withTurnNo(2)
            ->withStepId('turn-3-step')
            ->withIdempotencyKey('advance-mixed-1')
            ->build();

        $result = $handler->handle($message, $state);

        // Must be no-op when ANY tool call is unresolved
        $this->assertNull($result->nextState, 'No state change when some tool calls are still pending');
        $this->assertSame([], $result->events, 'No events when some tool calls are still pending');
        $this->assertSame([], $result->effects, 'No effects when some tool calls are still pending');
        $this->assertSame([], $result->postCommit, 'No post-commit callbacks when some tool calls are still pending');
    }

    public function testAdvanceWithAllResolvedPendingToolCallsProceeds(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );
        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        $state = RunStateBuilder::create('run-all-resolved')
            ->withStatus(RunStatus::Running)
            ->withVersion(7)
            ->withTurnNo(2)
            ->withLastSeq(14)
            ->withActiveStepId('turn-2-step')
            ->withPendingToolCalls([
                'tool-call-1' => true,
                'tool-call-2' => true,
            ])
            ->build();

        $this->assertTrue($state->pendingToolCalls['tool-call-1'], 'Precondition: tool-call-1 completed');
        $this->assertTrue($state->pendingToolCalls['tool-call-2'], 'Precondition: tool-call-2 completed');

        $message = AdvanceRunMessageBuilder::create('run-all-resolved')
            ->withTurnNo(2)
            ->withStepId('turn-3-step')
            ->withIdempotencyKey('advance-all-resolved-1')
            ->build();

        $result = $handler->handle($message, $state);

        // Should proceed normally when all pending tool calls are resolved
        $this->assertNotNull($result->nextState, 'State should change when all tool calls resolved');
        $this->assertNotNull($result->nextState->status);
        $this->assertNotEmpty($result->events, 'Events should be produced when tool calls resolved');
        $this->assertContains(
            'turn_advanced',
            array_map(static fn ($e) => $e->type, $result->events),
            'Expected turn_advanced event',
        );
    }

    /**
     * A compact drained on a Completed run must NOT transition the run
     * to Running.  Compaction replaces messages in place and should not
     * advance the turn.  The CompactRun effect is still passed through.
     */
    public function testCompactOnCompletedRunDoesNotTransitionToRunning(): void
    {
        $commandStore = new InMemoryCommandStore();

        // Pre-queue a compact command
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-compact-completed',
            kind: CoreCommandKind::Compact,
            idempotencyKey: 'compact-completed-ik',
            payload: [],
            options: new CommandCancellationOptions(safe: false),
        ));

        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        $state = RunStateBuilder::create('run-compact-completed')
            ->withStatus(RunStatus::Completed)
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(10)
            ->build();

        $message = AdvanceRunMessageBuilder::create('run-compact-completed')
            ->withTurnNo(1)
            ->withStepId('turn-2-step')
            ->withIdempotencyKey('advance-compact-1')
            ->build();

        $result = $handler->handle($message, $state);

        // Must NOT transition to Running — compact does not advance the turn
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Completed, $result->nextState->status,
            'Compact on Completed run must NOT transition to Running.',
        );

        // Should produce agent_command_applied events (compact drained)
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_command_applied', $eventTypes,
            'Compact drain must emit agent_command_applied event.',
        );

        // Must NOT produce turn_advanced or history_position_set — compact doesn't advance
        $this->assertNotContains('turn_advanced', $eventTypes,
            'Compact drain must NOT produce turn_advanced event.',
        );
        $this->assertNotContains('history_position_set', $eventTypes,
            'Compact drain must NOT produce history_position_set event.',
        );

        // The CompactRun effect must be passed through
        $this->assertNotEmpty($result->effects,
            'CompactRun effect must be included in the result.',
        );
        $hasCompactEffect = false;
        foreach ($result->effects as $effect) {
            if ($effect instanceof CompactRun) {
                $hasCompactEffect = true;
                $this->assertSame('run-compact-completed', $effect->runId());
                break;
            }
        }
        $this->assertTrue($hasCompactEffect,
            'Effects must include a CompactRun message.',
        );

        // Compact command should be drained from the store
        $this->assertCount(0, $commandStore->pending('run-compact-completed'),
            'Compact command must be drained (marked applied).',
        );
    }

    /**
     * A steer on a Completed run SHOULD transition to Running and advance
     * the turn (regression guard: compact guard must not block steer).
     */
    public function testSteerOnCompletedRunStillTransitionsToRunning(): void
    {
        $commandStore = new InMemoryCommandStore();

        // Pre-queue a steer command (message-producing)
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-steer-completed',
            kind: CoreCommandKind::Steer,
            idempotencyKey: 'steer-completed-ik',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Continue please.']]]],
            options: new CommandCancellationOptions(safe: false),
        ));

        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        $state = RunStateBuilder::create('run-steer-completed')
            ->withStatus(RunStatus::Completed)
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(10)
            ->build();

        $message = AdvanceRunMessageBuilder::create('run-steer-completed')
            ->withTurnNo(1)
            ->withStepId('turn-2-step')
            ->withIdempotencyKey('advance-steer-1')
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status,
            'Steer on Completed run MUST transition to Running.',
        );

        // Should allocate the next sparse turn identity from the canonical sequence high-water.
        $this->assertSame(11, $result->nextState->turnNo, 'Steer should advance to max(lastSeq, turnNo)+1.');
    }

    public function testCancellingWithPendingAppendMessageTerminalizesBeforeMailboxDrain(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-cancel-append-advance',
            kind: CoreCommandKind::AppendMessage,
            idempotencyKey: 'append-pending-1',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'After cancel']]]],
            options: new CommandCancellationOptions(safe: false),
        ));

        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );
        $commandBus = new TestMessageBus();

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            commandBus: $commandBus,
        );

        $state = RunStateBuilder::create('run-cancel-append-advance')
            ->withStatus(RunStatus::Cancelling)
            ->withVersion(4)
            ->withTurnNo(1)
            ->withLastSeq(20)
            ->withActiveStepId('stale-cancel-step')
            ->build()
            ->with([
                'currentOperation' => new \Ineersa\AgentCore\Domain\Run\CurrentOperationDTO(
                    1,
                    'stale-cancel-step',
                    1,
                    'advance-cancel-1',
                ),
            ]);

        $message = AdvanceRunMessageBuilder::create('run-cancel-append-advance')
            ->withTurnNo(1)
            ->withStepId('cancel-terminalize')
            ->withIdempotencyKey('advance-cancel-1')
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertNull($result->nextState->activeStepId);
        $this->assertNull($result->nextState->currentOperation);
        $this->assertSame([], $result->nextState->messages, 'AppendMessage must not be applied before cancel terminalizes');
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_end', $result->events[0]->type);
        $this->assertSame('cancelled', $result->events[0]->payload['reason'] ?? null);
        $this->assertSame('advance-cancel-1', $result->events[0]->payload['advance_idempotency_key'] ?? null);

        $duplicate = $handler->handle($message, $result->nextState);
        $this->assertNull($duplicate->nextState);
        $this->assertCount(1, $commandStore->pending('run-cancel-append-advance'), 'Committed cancellation must not drain its pending append on redelivery.');

        $this->assertCount(1, $result->postCommit);
        ($result->postCommit[0])();
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
        $this->assertStringStartsWith('post-cancel-advance-', $commandBus->messages[0]->stepId());
    }

    public function testPostCancelAdvanceDrainsPendingAppendMessageAndContinues(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-post-cancel-append',
            kind: CoreCommandKind::AppendMessage,
            idempotencyKey: 'append-pending-2',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'After cancel']]]],
            options: new CommandCancellationOptions(safe: false),
        ));

        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        $state = RunStateBuilder::create('run-post-cancel-append')
            ->withStatus(RunStatus::Cancelled)
            ->withVersion(5)
            ->withTurnNo(1)
            ->withLastSeq(21)
            ->build();

        $message = AdvanceRunMessageBuilder::create('run-post-cancel-append')
            ->withTurnNo(1)
            ->withStepId('post-cancel-advance-step')
            ->withIdempotencyKey('advance-post-cancel-1')
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertSame(RunStatus::Running, $result->nextState->status);
        $this->assertCount(1, $result->nextState->messages);
        $this->assertSame('After cancel', $result->nextState->messages[0]->content[0]['text'] ?? '');
        $this->assertCount(1, $result->effects);
        $this->assertInstanceOf(ExecuteLlmStep::class, $result->effects[0]);
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_command_applied', $eventTypes);
        $this->assertContains('turn_advanced', $eventTypes);
    }

    public function testCancellingWithUnresolvedToolCallsDoesNotTerminalize(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-cancel-tools-pending',
            kind: CoreCommandKind::AppendMessage,
            idempotencyKey: 'append-pending-3',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'After cancel']]]],
            options: new CommandCancellationOptions(safe: false),
        ));

        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );
        $commandBus = new TestMessageBus();

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            commandBus: $commandBus,
        );

        $state = RunStateBuilder::create('run-cancel-tools-pending')
            ->withStatus(RunStatus::Cancelling)
            ->withVersion(4)
            ->withTurnNo(1)
            ->withLastSeq(20)
            ->withPendingToolCalls(['tool-call-1' => false])
            ->build();

        $message = AdvanceRunMessageBuilder::create('run-cancel-tools-pending')
            ->withTurnNo(1)
            ->withStepId('cancel-wait-tools')
            ->withIdempotencyKey('advance-cancel-tools-1')
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertNull($result->nextState);
        $this->assertSame([], $result->events);
        $this->assertSame([], $result->postCommit);
        $this->assertSame([], $commandBus->messages);
    }

    // ── Retained-history turn allocation ──────────────────────────────────

    public function testTurnAllocationAfterHistorySelectUsesCanonicalSequenceHighWater(): void
    {
        // Thesis: after history-select positions retained history at turn N,
        // the next turn uses max(state.lastSeq, state.turnNo)+1 rather than
        // scanning EventStore. With lastSeq=10 and turnNo=1, next turn is 11.

        $runId = 'run-history-alloc-test';
        $commandStore = new InMemoryCommandStore();
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter([]),
        );

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
        );

        $state = RunStateBuilder::create($runId)
            ->withStatus(RunStatus::Running)
            ->withVersion(8)
            ->withTurnNo(1)
            ->withLastSeq(10)
            ->withActiveStepId('history-select-step')
            ->build();

        $message = AdvanceRunMessageBuilder::create($runId)
            ->withTurnNo(1)
            ->withStepId('continue-after-history-select')
            ->withIdempotencyKey('advance-after-history-select-1')
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(11, $result->nextState->turnNo,
            'After history select, next turn must be max(lastSeq, turnNo)+1.'
        );
        $this->assertSame(11, $result->events[0]->payload['turn_no']);
    }
}
