<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;

final class ApplyCommandHandlerTest extends TestCase
{
    public function testContinueCommandProducesPostCommitFollowUpAdvance(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $commandBus,
        );

        $state = new RunState(
            runId: 'run-apply-handler-1',
            status: RunStatus::Failed,
            version: 2,
            turnNo: 4,
            lastSeq: 5,
            messages: [new AgentMessage(role: 'tool', content: [])],
            retryableFailure: true,
        );

        $message = new ApplyCommand(
            runId: 'run-apply-handler-1',
            turnNo: 4,
            stepId: 'continue-step-1',
            attempt: 1,
            idempotencyKey: 'continue-idempotency-1',
            kind: CoreCommandKind::Continue,
            payload: [],
        );

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status);
        $this->assertSame(3, $result->nextState->version);
        $this->assertSame(6, $result->nextState->lastSeq);
        $this->assertNull($result->nextState->errorMessage);
        $this->assertFalse($result->nextState->retryableFailure);

        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_applied', $result->events[0]->type);
        $this->assertSame(CoreCommandKind::Continue, $result->events[0]->payload['kind']);

        $this->assertSame([], $result->effects);
        $this->assertSame([], $result->postCommitEffects);
        $this->assertCount(1, $result->postCommit);
        $this->assertTrue($result->markHandled);

        ($result->postCommit[0])();

        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
        $this->assertTrue($commandStore->has('run-apply-handler-1', 'continue-idempotency-1'));
    }

    public function testFollowUpAllowedAfterCancelledRun(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $commandBus,
        );

        $state = new RunState(
            runId: 'run-cancel-followup',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
            retryableFailure: false,
        );

        $message = new ApplyCommand(
            runId: 'run-cancel-followup',
            turnNo: 1,
            stepId: 'followup-step-1',
            attempt: 1,
            idempotencyKey: 'followup-idempotency-1',
            kind: CoreCommandKind::FollowUp,
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Next message']]]],
        );

        $result = $handler->handle($message, $state);

        // FollowUp should be accepted (queued, not rejected)
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status, 'RunState stays Cancelled until AdvanceRun transitions it');
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_queued', $result->events[0]->type);
        $this->assertSame(CoreCommandKind::FollowUp, $result->events[0]->payload['kind']);

        // FollowUp dispatches AdvanceRun to pick up the queued message
        $this->assertCount(1, $result->postCommit);
        ($result->postCommit[0])();
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
    }


    public function testAppendMessageAllowedAfterCancelledRun(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );
        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $commandBus,
        );

        $state = new RunState(
            runId: 'run-cancel-append',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [],
            retryableFailure: false,
        );

        $message = new ApplyCommand(
            runId: 'run-cancel-append',
            turnNo: 1,
            stepId: 'append-step-1',
            attempt: 1,
            idempotencyKey: 'append-idempotency-1',
            kind: CoreCommandKind::AppendMessage,
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Runtime input']]]],
        );

        $result = $handler->handle($message, $state);

        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame('agent_command_queued', $result->events[0]->type);
        $this->assertSame(CoreCommandKind::AppendMessage, $result->events[0]->payload['kind']);
        $this->assertCount(1, $result->postCommit);
        ($result->postCommit[0])();
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
    }

    public function testNonFollowUpCommandRejectedAfterCancelledRun(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-steer',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [],
            retryableFailure: false,
        );

        $message = new ApplyCommand(
            runId: 'run-cancel-steer',
            turnNo: 1,
            stepId: 'steer-step-1',
            attempt: 1,
            idempotencyKey: 'steer-idempotency-1',
            kind: CoreCommandKind::Steer,
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Steer']]]],
        );

        $result = $handler->handle($message, $state);

        // Steer should still be rejected when run is Cancelled
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame('Run is already cancelled.', $result->nextState->errorMessage);
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_rejected', $result->events[0]->type);
    }

    public function testFollowUpRejectedDuringCancelling(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancelling-followup',
            status: RunStatus::Cancelling,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
            retryableFailure: false,
        );

        $message = new ApplyCommand(
            runId: 'run-cancelling-followup',
            turnNo: 1,
            stepId: 'followup-step-1',
            attempt: 1,
            idempotencyKey: 'followup-idempotency-1',
            kind: CoreCommandKind::FollowUp,
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Next message']]]],
        );

        $result = $handler->handle($message, $state);

        // FollowUp should be rejected while Cancelling is in progress
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelling, $result->nextState->status);
        $this->assertStringContainsString('rejected because cancellation is in progress', $result->nextState->errorMessage ?? '');
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_rejected', $result->events[0]->type);
    }

    public function testContinueRejectedAfterCancelledRun(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-continue',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [new AgentMessage(role: 'tool', content: [])],
            retryableFailure: false,
        );

        $message = new ApplyCommand(
            runId: 'run-cancel-continue',
            turnNo: 1,
            stepId: 'continue-step-1',
            attempt: 1,
            idempotencyKey: 'continue-idempotency-1',
            kind: CoreCommandKind::Continue,
            payload: [],
        );

        $result = $handler->handle($message, $state);

        // Continue should be rejected when run is Cancelled
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame('Run is already cancelled.', $result->nextState->errorMessage);
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_rejected', $result->events[0]->type);
    }

    public function testHumanResponseRejectedAfterCancelledRun(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-human',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [],
            retryableFailure: false,
        );

        $message = new ApplyCommand(
            runId: 'run-cancel-human',
            turnNo: 1,
            stepId: 'human-step-1',
            attempt: 1,
            idempotencyKey: 'human-idempotency-1',
            kind: CoreCommandKind::HumanResponse,
            payload: ['answer' => 'yes'],
        );

        $result = $handler->handle($message, $state);

        // HumanResponse should be rejected when run is Cancelled
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame('Run is already cancelled.', $result->nextState->errorMessage);
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_rejected', $result->events[0]->type);
    }

    public function testSteerWhileRunningQueuesButDoesNotDispatchAdvanceRun(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $commandBus,
        );

        $state = new RunState(
            runId: 'run-steer-while-running',
            status: RunStatus::Running,
            version: 3,
            turnNo: 1,
            lastSeq: 5,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
        );

        $message = new ApplyCommand(
            runId: 'run-steer-while-running',
            turnNo: 1,
            stepId: 'steer-step-1',
            attempt: 1,
            idempotencyKey: 'steer-running-1',
            kind: CoreCommandKind::Steer,
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Steer message']]]],
        );

        $result = $handler->handle($message, $state);

        // Steer should be queued (not rejected)
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status);
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_queued', $result->events[0]->type);

        // NO postCommit AdvanceRun callback — the run is active,
        // so the queued command will be drained at the next safe
        // boundary (stop boundary or after tool batch).
        $this->assertCount(0, $result->postCommit,
            'Steer while Running must not dispatch AdvanceRun — only drain at safe boundary.',
        );

        // Verify the command is in the store for later drain
        $this->assertTrue($commandStore->has('run-steer-while-running', 'steer-running-1'));
        $this->assertCount(1, $commandStore->pending('run-steer-while-running'));
    }

    public function testFollowUpWhileRunningQueuesButDoesNotDispatchAdvanceRun(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $commandBus,
        );

        $state = new RunState(
            runId: 'run-followup-while-running',
            status: RunStatus::Running,
            version: 3,
            turnNo: 1,
            lastSeq: 5,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
        );

        $message = new ApplyCommand(
            runId: 'run-followup-while-running',
            turnNo: 1,
            stepId: 'followup-step-1',
            attempt: 1,
            idempotencyKey: 'followup-running-1',
            kind: CoreCommandKind::FollowUp,
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Follow up']]]],
        );

        $result = $handler->handle($message, $state);

        // FollowUp should be queued (not rejected)
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status);
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_queued', $result->events[0]->type);

        // NO postCommit AdvanceRun — active run
        $this->assertCount(0, $result->postCommit,
            'FollowUp while Running must not dispatch AdvanceRun.',
        );
    }

    public function testFollowUpAfterCancelledStillDispatchesAdvanceRun(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $commandBus,
        );

        $state = new RunState(
            runId: 'run-cancel-followup-advance',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
            retryableFailure: false,
        );

        $message = new ApplyCommand(
            runId: 'run-cancel-followup-advance',
            turnNo: 1,
            stepId: 'followup-step-2',
            attempt: 1,
            idempotencyKey: 'followup-cancelled-1',
            kind: CoreCommandKind::FollowUp,
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Resume']]]],
        );

        $result = $handler->handle($message, $state);

        // FollowUp after Cancelled should still queue
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_queued', $result->events[0]->type);

        // FollowUp after Cancelled SHOULD dispatch AdvanceRun to resume
        $this->assertCount(1, $result->postCommit,
            'FollowUp after Cancelled must still dispatch AdvanceRun.',
        );

        ($result->postCommit[0])();
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
    }

    public function testCancelRejectsPendingSteerAndFollowUp(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        // Pre-queue a steer and a follow_up command
        $commandStore->enqueue(new \Ineersa\AgentCore\Domain\Command\PendingCommand(
            runId: 'run-cancel-stale',
            kind: CoreCommandKind::Steer,
            idempotencyKey: 'steer-stale-1',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Stale steer']]]],
            options: new \Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions(safe: false),
        ));

        $commandStore->enqueue(new \Ineersa\AgentCore\Domain\Command\PendingCommand(
            runId: 'run-cancel-stale',
            kind: CoreCommandKind::FollowUp,
            idempotencyKey: 'followup-stale-1',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Stale follow up']]]],
            options: new \Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions(safe: false),
        ));

        $state = new RunState(
            runId: 'run-cancel-stale',
            status: RunStatus::Running,
            version: 5,
            turnNo: 2,
            lastSeq: 8,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Committed user message']]),
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Working...']]),
            ],
        );

        $cancelMessage = new ApplyCommand(
            runId: 'run-cancel-stale',
            turnNo: 2,
            stepId: 'cancel-step-1',
            attempt: 1,
            idempotencyKey: 'cancel-stale-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        // Cancel should be applied and terminalizes directly to Cancelled
        // because there is no active work (no activeStepId, no streaming,
        // no unresolved pendingToolCalls).
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame(RunStatus::Running, $state->status, 'Original state unchanged');

        // Already-applied user messages must remain intact
        $this->assertCount(2, $result->nextState->messages, 'Already-applied messages must survive cancel.');
        $this->assertSame('user', $result->nextState->messages[0]->role);
        $this->assertSame('assistant', $result->nextState->messages[1]->role);

        // Must include agent_command_applied + agent_end (cancelled) + rejections
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_command_applied', $eventTypes);
        $this->assertContains('agent_end', $eventTypes);
        $this->assertContains('agent_command_rejected', $eventTypes);

        // Verify agent_end reason
        $agentEndEvent = array_values(array_filter(
            $result->events,
            static fn ($e) => 'agent_end' === $e->type,
        ))[0] ?? null;
        $this->assertNotNull($agentEndEvent, 'Must emit agent_end.');
        $this->assertSame('cancelled', $agentEndEvent->payload['reason']);

        // Count rejection events: steer + follow_up
        $rejectedEvents = array_filter($result->events, static fn ($e) => 'agent_command_rejected' === $e->type);
        $this->assertCount(2, $rejectedEvents, 'Expected 2 rejection events (steer, follow_up).');

        $rejectedKinds = array_map(static fn ($e) => $e->payload['kind'], $rejectedEvents);
        $this->assertContains('steer', $rejectedKinds);
        $this->assertContains('follow_up', $rejectedKinds);

        // Verify the queued commands are no longer pending
        $this->assertCount(0, $commandStore->pending('run-cancel-stale'),
            'All stale queued commands should be rejected after cancel.',
        );
    }

    public function testCancelPreservesQueuedAppendMessageInMessages(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );
        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $commandBus,
        );

        $runtimeText = 'Runtime notification line one';
        $commandStore->enqueue(new \Ineersa\AgentCore\Domain\Command\PendingCommand(
            runId: 'run-append-cancel',
            kind: CoreCommandKind::AppendMessage,
            idempotencyKey: 'append-1',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $runtimeText]]]],
            options: new \Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions(safe: false),
        ));

        $state = new RunState(
            runId: 'run-append-cancel',
            status: RunStatus::Running,
            version: 2,
            turnNo: 1,
            lastSeq: 5,
            activeStepId: 'step-active',
            messages: [],
        );

        $cancelMessage = new ApplyCommand(
            runId: 'run-append-cancel',
            turnNo: 1,
            stepId: 'cancel-append',
            attempt: 1,
            idempotencyKey: 'cancel-append-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertCount(1, $result->nextState->messages);
        $this->assertSame($runtimeText, $result->nextState->messages[0]->content[0]['text'] ?? '');

        $appliedAppend = array_values(array_filter(
            $result->events,
            static fn ($e) => 'agent_command_applied' === $e->type && CoreCommandKind::AppendMessage === ($e->payload['kind'] ?? null),
        ));
        $this->assertCount(1, $appliedAppend);
        $this->assertSame($runtimeText, $appliedAppend[0]->payload['text'] ?? '');
        $this->assertCount(1, $result->postCommit);
        ($result->postCommit[0])();
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
    }

    public function testAppendMessageQueuedWhileCancelling(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $runtimeText = 'Queued while cancelling';

        $state = new RunState(
            runId: 'run-append-cancelling',
            status: RunStatus::Cancelling,
            version: 4,
            turnNo: 1,
            lastSeq: 58,
            activeStepId: 'step-active',
            messages: [new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'prior']])],
        );

        $message = new ApplyCommand(
            runId: 'run-append-cancelling',
            turnNo: 1,
            stepId: 'append-while-cancel',
            attempt: 1,
            idempotencyKey: 'append-cancelling-1',
            kind: CoreCommandKind::AppendMessage,
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $runtimeText]]]],
        );

        $result = $handler->handle($message, $state);

        $this->assertSame(RunStatus::Cancelling, $result->nextState->status);
        $this->assertCount(1, $result->nextState->messages);
        $this->assertSame(['agent_command_queued'], array_map(static fn ($e) => $e->type, $result->events));
        $this->assertTrue($commandStore->has('run-append-cancelling', 'append-cancelling-1'));
    }

    public function testOrdinaryFollowUpRejectedWhileCancelling(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-user-cancelling',
            status: RunStatus::Cancelling,
            version: 2,
            turnNo: 1,
            lastSeq: 5,
            messages: [],
        );

        $message = new ApplyCommand(
            runId: 'run-user-cancelling',
            turnNo: 1,
            stepId: 'followup-user-1',
            attempt: 1,
            idempotencyKey: 'user-followup-1',
            kind: CoreCommandKind::FollowUp,
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Please continue']]]],
        );

        $result = $handler->handle($message, $state);

        $this->assertSame(RunStatus::Cancelling, $result->nextState->status);
        $this->assertCount(0, $result->nextState->messages);
        $this->assertSame('agent_command_rejected', $result->events[0]->type);
        $this->assertStringContainsString('cancellation is in progress', $result->events[0]->payload['reason'] ?? '');
    }

    /**
     * Terminal compact must mark applied and dispatch CompactRun
     * immediately — no enqueue.  This prevents duplicate compact
     * when the pending command is drained by a future mailbox cycle.
     */
    public function testCompactOnTerminalRunMarksAppliedNotQueued(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $commandBus,
        );

        $state = new RunState(
            runId: 'run-terminal-compact',
            status: RunStatus::Completed,
            version: 3,
            turnNo: 5,
            lastSeq: 10,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hi']]),
            ],
        );

        $message = new ApplyCommand(
            runId: 'run-terminal-compact',
            turnNo: 5,
            stepId: 'compact-step-1',
            attempt: 1,
            idempotencyKey: 'compact-terminal-ik-1',
            kind: CoreCommandKind::Compact,
            payload: ['custom_instructions' => 'Be brief.'],
        );

        $result = $handler->handle($message, $state);

        // Terminal path: mark applied immediately, not queued
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Completed, $result->nextState->status,
            'Terminal compact must not change run status.',
        );

        // Event must be agent_command_applied (not agent_command_queued)
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_applied', $result->events[0]->type,
            'Terminal compact must emit agent_command_applied, not agent_command_queued.',
        );
        $this->assertSame('compact', $result->events[0]->payload['kind']);

        // CompactRun must be dispatched via post-commit callback
        $this->assertCount(1, $result->postCommit,
            'Terminal compact must include a post-commit CompactRun dispatch.',
        );
        ($result->postCommit[0])();
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(CompactRun::class, $commandBus->messages[0]);
        $this->assertSame('run-terminal-compact', $commandBus->messages[0]->runId());

        // No pending command left in store (would cause duplicate drain)
        $this->assertCount(0, $commandStore->pending('run-terminal-compact'),
            'Terminal compact must not leave a pending command in the store.',
        );
    }

    /**
     * Regression: cancel-on-completed-run must not stick in Cancelling (issue #183).
     *
     * Before the fix, applyCancelCommand() unconditionally set RunStatus::Cancelling
     * even when the run was already Completed or Failed.  With no subsequent
     * RunCancelled transition (nothing was in flight to abort), the
     * ActivityStateMachine permanently stuck at Cancelling.
     *
     * Thesis: cancel command applied to a Completed run must be rejected,
     * leaving the state at Completed.
     */
    public function testCancelOnCompletedRunIsRejected(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-completed',
            status: RunStatus::Completed,
            version: 5,
            turnNo: 3,
            lastSeq: 15,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hi there!']]),
            ],
        );

        $cancelMessage = new ApplyCommand(
            runId: 'run-cancel-completed',
            turnNo: 3,
            stepId: 'cancel-completed-step',
            attempt: 1,
            idempotencyKey: 'cancel-completed-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        // The cancel must be REJECTED — the run is already completed.
        $this->assertNotNull($result->nextState, 'State must be present (rejection still increments version).');
        $this->assertSame(RunStatus::Completed, $result->nextState->status,
            'Cancel on Completed run must NOT transition to Cancelling.',
        );
        $this->assertCount(1, $result->events, 'Single rejection event expected.');
        $this->assertSame('agent_command_rejected', $result->events[0]->type,
            'Cancel on Completed run must emit agent_command_rejected.',
        );
        $this->assertStringContainsString(
            'terminal state',
            $result->nextState->errorMessage ?? '',
            'Rejection must mention terminal state.',
        );

        // No pending command left in store.
        $this->assertCount(0, $commandStore->pending('run-cancel-completed'));
    }

    /**
     * Regression: cancel-on-failed-run must not stick in Cancelling (issue #183).
     *
     * Mirror of cancel-on-completed — Failed is also a terminal state.
     */
    public function testCancelOnFailedRunIsRejected(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-failed',
            status: RunStatus::Failed,
            version: 3,
            turnNo: 1,
            lastSeq: 8,
            errorMessage: 'Something went wrong.',
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Task']]),
            ],
        );

        $cancelMessage = new ApplyCommand(
            runId: 'run-cancel-failed',
            turnNo: 1,
            stepId: 'cancel-failed-step',
            attempt: 1,
            idempotencyKey: 'cancel-failed-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Failed, $result->nextState->status,
            'Cancel on Failed run must NOT transition to Cancelling.',
        );
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_rejected', $result->events[0]->type);

        // Original error message must be preserved (not overwritten by cancel rejection).
        $this->assertStringContainsString(
            'terminal state',
            $result->nextState->errorMessage ?? '',
        );
    }

    /**
     * Idempotency: compact applied call with same idempotencyKey is no-op.
     */
    public function testCompactTerminalIdempotency(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-compact-idempotent',
            status: RunStatus::Completed,
            version: 3,
            turnNo: 5,
            lastSeq: 10,
            messages: [],
        );

        $message = new ApplyCommand(
            runId: 'run-compact-idempotent',
            turnNo: 5,
            stepId: 'compact-step-2',
            attempt: 1,
            idempotencyKey: 'compact-idem-1',
            kind: CoreCommandKind::Compact,
            payload: [],
        );

        // First call — applied
        $result1 = $handler->handle($message, $state);
        $this->assertNotNull($result1->nextState);

        // Second call — idempotent no-op
        $result2 = $handler->handle($message, $result1->nextState ?? $state);
        $this->assertNull($result2->nextState,
            'Second compact with same idempotency key must be a no-op.',
        );
    }

    /**
     * Thesis: cancel from idle Running (no activeStepId, not streaming,
     * no pendingToolCalls) must terminalize immediately to Cancelled with
     * AgentEnd rather than getting stuck in Cancelling.
     */
    public function testCancelFromIdleRunningTerminalizesToCancelled(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-idle-cancel',
            status: RunStatus::Running,
            version: 5,
            turnNo: 2,
            lastSeq: 8,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hi']]),
            ],
            activeStepId: null,  // idle: no active step
            retryableFailure: false,
        );

        $cancelMessage = new ApplyCommand(
            runId: 'run-idle-cancel',
            turnNo: 2,
            stepId: 'cancel-step-1',
            attempt: 1,
            idempotencyKey: 'cancel-idle-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        // Must reach Cancelled directly — no Cancelling limbo.
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status,
            'Cancel from idle Running must terminalize to Cancelled, not Cancelling');
        $this->assertNull($result->nextState->activeStepId);
        $this->assertFalse($result->nextState->isStreaming);

        // Events: agent_command_applied + agent_end (cancelled reason).
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_command_applied', $eventTypes);
        $this->assertContains('agent_end', $eventTypes);

        $agentEndEvent = array_values(array_filter(
            $result->events,
            static fn ($e) => 'agent_end' === $e->type,
        ))[0] ?? null;
        $this->assertNotNull($agentEndEvent, 'Must emit agent_end event.');
        $this->assertSame('cancelled', $agentEndEvent->payload['reason'] ?? null,
            'AgentEnd reason must be cancelled.');
    }

    /**
     * Thesis: repeated cancel while already Cancelling with no active work
     * is idempotent — accepted as an applied command without changing state
     * or rejecting.
     */
    public function testRepeatedCancelWhileCancellingNoActiveWorkIsIdempotent(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-repeat',
            status: RunStatus::Cancelling,
            version: 10,
            turnNo: 3,
            lastSeq: 12,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: 'Run cancelled by command.',
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Cancel me']]),
            ],
            activeStepId: null,  // no active work
            retryableFailure: false,
        );

        $cancelMessage = new ApplyCommand(
            runId: 'run-cancel-repeat',
            turnNo: 3,
            stepId: 'cancel-step-2',
            attempt: 1,
            idempotencyKey: 'cancel-repeat-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        // Must be accepted (not rejected), preserving Cancelling status.
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status,
            'Cancel while stuck Cancelling with no active work must terminalize');

        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_command_applied', $eventTypes);
        $this->assertContains('agent_end', $eventTypes);
    }

    /**
     * Session 4 class bug: stale activeStepId after all tools resolved must not
     * block immediate cancel terminalization.
     */
    public function testCancelWithStaleActiveStepAndAllPendingToolCallsResolvedTerminalizes(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-stale-step',
            status: RunStatus::Running,
            version: 20,
            turnNo: 15,
            lastSeq: 200,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [
                'call_00' => true,
                'call_01' => true,
            ],
            errorMessage: null,
            messages: [],
            activeStepId: 'advance-after-tools-33525236701801',
            retryableFailure: false,
        );

        $cancelMessage = new ApplyCommand(
            runId: 'run-stale-step',
            turnNo: 15,
            stepId: 'cancel-seq201',
            attempt: 1,
            idempotencyKey: 'cancel-stale-step-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertNull($result->nextState->activeStepId);
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_end', $eventTypes);
    }

    public function testCancelWithUnresolvedPendingToolCallEntersCancelling(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-pending-tool',
            status: RunStatus::Running,
            version: 3,
            turnNo: 2,
            lastSeq: 10,
            isStreaming: false,
            pendingToolCalls: ['call_00' => false],
            messages: [],
            activeStepId: 'step-tools',
            retryableFailure: false,
        );

        $cancelMessage = new ApplyCommand(
            runId: 'run-pending-tool',
            turnNo: 2,
            stepId: 'cancel-1',
            attempt: 1,
            idempotencyKey: 'cancel-pending-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        $this->assertSame(RunStatus::Cancelling, $result->nextState->status);
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertNotContains('agent_end', $eventTypes);
    }

    public function testCancelWhileAlreadyCancellingWithNoActiveWorkTerminalizes(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-stuck-cancelling',
            status: RunStatus::Cancelling,
            version: 30,
            turnNo: 15,
            lastSeq: 200,
            isStreaming: false,
            pendingToolCalls: ['call_00' => true, 'call_01' => true],
            messages: [],
            activeStepId: 'advance-after-tools-33525236701801',
            retryableFailure: false,
        );

        $cancelMessage = new ApplyCommand(
            runId: 'run-stuck-cancelling',
            turnNo: 15,
            stepId: 'cancel-seq202',
            attempt: 1,
            idempotencyKey: 'cancel-stuck-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertContains('agent_end', array_map(static fn ($e) => $e->type, $result->events));
    }

}
