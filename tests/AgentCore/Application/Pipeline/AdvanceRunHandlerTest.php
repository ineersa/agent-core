<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
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
use PHPUnit\Framework\TestCase;

final class AdvanceRunHandlerTest extends TestCase
{
    public function testHandleProducesTurnAdvanceEventAndLlmExecutionEffect(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
        );
        $metrics = new RunMetrics();

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            metrics: $metrics,
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
            ->withStepId('turn-3-step')
            ->withIdempotencyKey('advance-idempotency-1')
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status);
        $this->assertSame(8, $result->nextState->version);
        $this->assertSame(3, $result->nextState->turnNo);
        $this->assertSame(13, $result->nextState->lastSeq);
        $this->assertSame('turn-3-step', $result->nextState->activeStepId);

        $this->assertCount(2, $result->events);
        $this->assertSame('turn_advanced', $result->events[0]->type);
        $this->assertSame('leaf_set', $result->events[1]->type);
        $this->assertSame(3, $result->events[1]->payload['turn_no']);
        $this->assertSame('continue', $result->events[1]->payload['reason']);
        $this->assertSame(3, $result->events[0]->payload['turn_no']);
        $this->assertSame(2, $result->events[0]->payload['parent_turn_no']);

        $this->assertCount(1, $result->effects);
        $this->assertInstanceOf(ExecuteLlmStep::class, $result->effects[0]);
        $this->assertSame(3, $result->effects[0]->turnNo());
        $this->assertSame('turn-3-step', $result->effects[0]->stepId());

        $this->assertSame([], $result->postCommitEffects);
        $this->assertCount(1, $result->postCommit);
        $this->assertTrue($result->markHandled);

        ($result->postCommit[0])();

        $this->assertIsArray($metrics->snapshot());
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
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
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

        // AdvanceRun should transition Cancelled → Running and advance the turn
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status, 'Cancelled run with pending FollowUp should transition to Running');
        $this->assertSame(2, $result->nextState->turnNo, 'Turn should advance from 1 to 2');
        $this->assertNull($result->nextState->errorMessage, 'errorMessage should be cleared when transitioning Cancelled → Running');

        // Should produce events including agent_command_applied, turn_advanced, and leaf_set
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_command_applied', $eventTypes, 'Expected agent_command_applied event');
        $this->assertContains('turn_advanced', $eventTypes, 'Expected turn_advanced event');
        $this->assertContains('leaf_set', $eventTypes, 'Expected leaf_set event');

        // Should produce an LLM step effect (the agent will process the follow-up)
        $this->assertCount(1, $result->effects);
        $this->assertInstanceOf(ExecuteLlmStep::class, $result->effects[0]);
    }

    public function testHandleFirstTurnHasNullParentTurnNo(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
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
        $this->assertSame(1, $result->nextState->turnNo);

        // leaf_set must be present
        $this->assertCount(2, $result->events);
        $this->assertSame('turn_advanced', $result->events[0]->type);
        $this->assertSame('leaf_set', $result->events[1]->type);

        // parent_turn_no must be null for the root turn (turnNo 1)
        $this->assertArrayHasKey('parent_turn_no', $result->events[0]->payload);
        $this->assertNull($result->events[0]->payload['parent_turn_no']);
        $this->assertSame(1, $result->events[0]->payload['turn_no']);
        $this->assertSame(1, $result->events[1]->payload['turn_no']);
        $this->assertNull($result->events[1]->payload['parent_turn_no']);
        $this->assertNull($result->events[1]->payload['previous_turn_no']);
    }

    public function testCancelledRunWithNoPendingCommandsIsNoOp(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
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
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
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
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
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
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
        );
        $metrics = new RunMetrics();

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            metrics: $metrics,
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
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
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

        // Must NOT produce turn_advanced or leaf_set — compact doesn't advance
        $this->assertNotContains('turn_advanced', $eventTypes,
            'Compact drain must NOT produce turn_advanced event.',
        );
        $this->assertNotContains('leaf_set', $eventTypes,
            'Compact drain must NOT produce leaf_set event.',
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
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
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

        // Should advance the turn
        $this->assertSame(2, $result->nextState->turnNo, 'Steer should advance the turn.');
    }
}
