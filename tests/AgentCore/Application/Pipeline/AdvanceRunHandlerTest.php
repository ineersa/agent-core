<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\RunMessageStateTools;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
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
            stateTools: new RunMessageStateTools(new EventFactory(), new ToolCallExtractor()),
            metrics: $metrics,
        );

        $state = new RunState(
            runId: 'run-advance-handler-1',
            status: RunStatus::Running,
            version: 7,
            turnNo: 2,
            lastSeq: 11,
            activeStepId: 'turn-2-step',
        );

        $message = new AdvanceRun(
            runId: 'run-advance-handler-1',
            turnNo: 2,
            stepId: 'turn-3-step',
            attempt: 1,
            idempotencyKey: 'advance-idempotency-1',
        );

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status);
        $this->assertSame(8, $result->nextState->version);
        $this->assertSame(3, $result->nextState->turnNo);
        $this->assertSame(12, $result->nextState->lastSeq);
        $this->assertSame('turn-3-step', $result->nextState->activeStepId);

        $this->assertCount(1, $result->events);
        $this->assertSame('turn_advanced', $result->events[0]->type);

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
            stateTools: new RunMessageStateTools(new EventFactory(), new ToolCallExtractor()),
        );

        $state = new RunState(
            runId: 'run-cancel-advance',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
        );

        $message = new AdvanceRun(
            runId: 'run-cancel-advance',
            turnNo: 1,
            stepId: 'turn-2-step',
            attempt: 1,
            idempotencyKey: 'advance-cancel-1',
        );

        $result = $handler->handle($message, $state);

        // AdvanceRun should transition Cancelled → Running and advance the turn
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status, 'Cancelled run with pending FollowUp should transition to Running');
        $this->assertSame(2, $result->nextState->turnNo, 'Turn should advance from 1 to 2');
        $this->assertNull($result->nextState->errorMessage, 'errorMessage should be cleared when transitioning Cancelled → Running');

        // Should produce events including agent_command_applied and turn_advanced
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_command_applied', $eventTypes, 'Expected agent_command_applied event');
        $this->assertContains('turn_advanced', $eventTypes, 'Expected turn_advanced event');

        // Should produce an LLM step effect (the agent will process the follow-up)
        $this->assertCount(1, $result->effects);
        $this->assertInstanceOf(ExecuteLlmStep::class, $result->effects[0]);
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
            stateTools: new RunMessageStateTools(new EventFactory(), new ToolCallExtractor()),
        );

        $state = new RunState(
            runId: 'run-cancel-noop',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
        );

        $message = new AdvanceRun(
            runId: 'run-cancel-noop',
            turnNo: 1,
            stepId: 'noop-step',
            attempt: 1,
            idempotencyKey: 'advance-noop-1',
        );

        $result = $handler->handle($message, $state);

        // When Cancelled with no pending commands, AdvanceRun should be a no-op
        $this->assertNull($result->nextState, 'No state change when Cancelled with no pending commands');
        $this->assertSame([], $result->events);
        $this->assertSame([], $result->effects);
    }
}
