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

        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Running, $result->nextState->status);
        self::assertSame(8, $result->nextState->version);
        self::assertSame(3, $result->nextState->turnNo);
        self::assertSame(13, $result->nextState->lastSeq);
        self::assertSame('turn-3-step', $result->nextState->activeStepId);

        self::assertCount(2, $result->events);
        self::assertSame('turn_advanced', $result->events[0]->type);
        self::assertSame('leaf_set', $result->events[1]->type);
        self::assertSame(3, $result->events[1]->payload['turn_no']);
        self::assertSame('continue', $result->events[1]->payload['reason']);
        self::assertSame(3, $result->events[0]->payload['turn_no']);
        self::assertSame(2, $result->events[0]->payload['parent_turn_no']);

        self::assertCount(1, $result->effects);
        self::assertInstanceOf(ExecuteLlmStep::class, $result->effects[0]);
        self::assertSame(3, $result->effects[0]->turnNo());
        self::assertSame('turn-3-step', $result->effects[0]->stepId());

        self::assertSame([], $result->postCommitEffects);
        self::assertCount(1, $result->postCommit);
        self::assertTrue($result->markHandled);

        ($result->postCommit[0])();

        self::assertIsArray($metrics->snapshot());
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
        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Running, $result->nextState->status, 'Cancelled run with pending FollowUp should transition to Running');
        self::assertSame(2, $result->nextState->turnNo, 'Turn should advance from 1 to 2');
        self::assertNull($result->nextState->errorMessage, 'errorMessage should be cleared when transitioning Cancelled → Running');

        // Should produce events including agent_command_applied, turn_advanced, and leaf_set
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        self::assertContains('agent_command_applied', $eventTypes, 'Expected agent_command_applied event');
        self::assertContains('turn_advanced', $eventTypes, 'Expected turn_advanced event');
        self::assertContains('leaf_set', $eventTypes, 'Expected leaf_set event');

        // Should produce an LLM step effect (the agent will process the follow-up)
        self::assertCount(1, $result->effects);
        self::assertInstanceOf(ExecuteLlmStep::class, $result->effects[0]);
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

        self::assertNotNull($result->nextState);
        self::assertSame(1, $result->nextState->turnNo);

        // leaf_set must be present
        self::assertCount(2, $result->events);
        self::assertSame('turn_advanced', $result->events[0]->type);
        self::assertSame('leaf_set', $result->events[1]->type);

        // parent_turn_no must be null for the root turn (turnNo 1)
        self::assertArrayHasKey('parent_turn_no', $result->events[0]->payload);
        self::assertNull($result->events[0]->payload['parent_turn_no']);
        self::assertSame(1, $result->events[0]->payload['turn_no']);
        self::assertSame(1, $result->events[1]->payload['turn_no']);
        self::assertNull($result->events[1]->payload['parent_turn_no']);
        self::assertNull($result->events[1]->payload['previous_turn_no']);
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
        self::assertNull($result->nextState, 'No state change when Cancelled with no pending commands');
        self::assertSame([], $result->events);
        self::assertSame([], $result->effects);
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

        self::assertFalse($state->pendingToolCalls['tool-call-1'], 'Precondition: tool call not completed');

        $message = AdvanceRunMessageBuilder::create('run-pending-tools')
            ->withTurnNo(2)
            ->withStepId('turn-3-step')
            ->withIdempotencyKey('advance-pending-tools-1')
            ->build();

        $result = $handler->handle($message, $state);

        // AdvanceRun must be a no-op when there are unresolved tool calls
        self::assertNull($result->nextState, 'No state change when tool calls are still pending');
        self::assertSame([], $result->events, 'No events when tool calls are still pending');
        self::assertSame([], $result->effects, 'No effects when tool calls are still pending');
        self::assertSame([], $result->postCommit, 'No post-commit callbacks when tool calls are still pending');
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

        self::assertTrue($state->pendingToolCalls['tool-call-1'], 'Precondition: tool-call-1 completed');
        self::assertFalse($state->pendingToolCalls['tool-call-2'], 'Precondition: tool-call-2 not completed');

        $message = AdvanceRunMessageBuilder::create('run-mixed-tools')
            ->withTurnNo(2)
            ->withStepId('turn-3-step')
            ->withIdempotencyKey('advance-mixed-1')
            ->build();

        $result = $handler->handle($message, $state);

        // Must be no-op when ANY tool call is unresolved
        self::assertNull($result->nextState, 'No state change when some tool calls are still pending');
        self::assertSame([], $result->events, 'No events when some tool calls are still pending');
        self::assertSame([], $result->effects, 'No effects when some tool calls are still pending');
        self::assertSame([], $result->postCommit, 'No post-commit callbacks when some tool calls are still pending');
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

        self::assertTrue($state->pendingToolCalls['tool-call-1'], 'Precondition: tool-call-1 completed');
        self::assertTrue($state->pendingToolCalls['tool-call-2'], 'Precondition: tool-call-2 completed');

        $message = AdvanceRunMessageBuilder::create('run-all-resolved')
            ->withTurnNo(2)
            ->withStepId('turn-3-step')
            ->withIdempotencyKey('advance-all-resolved-1')
            ->build();

        $result = $handler->handle($message, $state);

        // Should proceed normally when all pending tool calls are resolved
        self::assertNotNull($result->nextState, 'State should change when all tool calls resolved');
        self::assertNotNull($result->nextState->status);
        self::assertNotEmpty($result->events, 'Events should be produced when tool calls resolved');
        self::assertContains(
            'turn_advanced',
            array_map(static fn ($e) => $e->type, $result->events),
            'Expected turn_advanced event',
        );
    }
}
