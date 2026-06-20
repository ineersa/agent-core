<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
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

        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Running, $result->nextState->status);
        self::assertSame(3, $result->nextState->version);
        self::assertSame(6, $result->nextState->lastSeq);
        self::assertNull($result->nextState->errorMessage);
        self::assertFalse($result->nextState->retryableFailure);

        self::assertCount(1, $result->events);
        self::assertSame('agent_command_applied', $result->events[0]->type);
        self::assertSame(CoreCommandKind::Continue, $result->events[0]->payload['kind']);

        self::assertSame([], $result->effects);
        self::assertSame([], $result->postCommitEffects);
        self::assertCount(1, $result->postCommit);
        self::assertTrue($result->markHandled);

        ($result->postCommit[0])();

        self::assertCount(1, $commandBus->messages);
        self::assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
        self::assertTrue($commandStore->has('run-apply-handler-1', 'continue-idempotency-1'));
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
        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Cancelled, $result->nextState->status, 'RunState stays Cancelled until AdvanceRun transitions it');
        self::assertCount(1, $result->events);
        self::assertSame('agent_command_queued', $result->events[0]->type);
        self::assertSame(CoreCommandKind::FollowUp, $result->events[0]->payload['kind']);

        // FollowUp dispatches AdvanceRun to pick up the queued message
        self::assertCount(1, $result->postCommit);
        ($result->postCommit[0])();
        self::assertCount(1, $commandBus->messages);
        self::assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
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
        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Cancelled, $result->nextState->status);
        self::assertSame('Run is already cancelled.', $result->nextState->errorMessage);
        self::assertCount(1, $result->events);
        self::assertSame('agent_command_rejected', $result->events[0]->type);
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
        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Cancelling, $result->nextState->status);
        self::assertStringContainsString('rejected because cancellation is in progress', $result->nextState->errorMessage ?? '');
        self::assertCount(1, $result->events);
        self::assertSame('agent_command_rejected', $result->events[0]->type);
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
        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Cancelled, $result->nextState->status);
        self::assertSame('Run is already cancelled.', $result->nextState->errorMessage);
        self::assertCount(1, $result->events);
        self::assertSame('agent_command_rejected', $result->events[0]->type);
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
        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Cancelled, $result->nextState->status);
        self::assertSame('Run is already cancelled.', $result->nextState->errorMessage);
        self::assertCount(1, $result->events);
        self::assertSame('agent_command_rejected', $result->events[0]->type);
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
        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Running, $result->nextState->status);
        self::assertCount(1, $result->events);
        self::assertSame('agent_command_queued', $result->events[0]->type);

        // NO postCommit AdvanceRun callback — the run is active,
        // so the queued command will be drained at the next safe
        // boundary (stop boundary or after tool batch).
        self::assertCount(0, $result->postCommit,
            'Steer while Running must not dispatch AdvanceRun — only drain at safe boundary.',
        );

        // Verify the command is in the store for later drain
        self::assertTrue($commandStore->has('run-steer-while-running', 'steer-running-1'));
        self::assertCount(1, $commandStore->pending('run-steer-while-running'));
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
        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Running, $result->nextState->status);
        self::assertCount(1, $result->events);
        self::assertSame('agent_command_queued', $result->events[0]->type);

        // NO postCommit AdvanceRun — active run
        self::assertCount(0, $result->postCommit,
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
        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Cancelled, $result->nextState->status);
        self::assertCount(1, $result->events);
        self::assertSame('agent_command_queued', $result->events[0]->type);

        // FollowUp after Cancelled SHOULD dispatch AdvanceRun to resume
        self::assertCount(1, $result->postCommit,
            'FollowUp after Cancelled must still dispatch AdvanceRun.',
        );

        ($result->postCommit[0])();
        self::assertCount(1, $commandBus->messages);
        self::assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
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

        // Cancel should be applied
        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Cancelling, $result->nextState->status);
        self::assertSame(RunStatus::Running, $state->status, 'Original state unchanged');

        // Already-applied user messages must remain intact
        self::assertCount(2, $result->nextState->messages, 'Already-applied messages must survive cancel.');
        self::assertSame('user', $result->nextState->messages[0]->role);
        self::assertSame('assistant', $result->nextState->messages[1]->role);

        // Must include agent_command_applied + rejections for steer, follow_up, and continue
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        self::assertContains('agent_command_applied', $eventTypes);
        self::assertContains('agent_command_rejected', $eventTypes);

        // Count rejection events: steer + follow_up (continue may not be in store but rejectPendingByKind is called for all three)
        $rejectedEvents = array_filter($result->events, static fn ($e) => 'agent_command_rejected' === $e->type);
        self::assertCount(2, $rejectedEvents, 'Expected 2 rejection events (steer, follow_up).');

        $rejectedKinds = array_map(static fn ($e) => $e->payload['kind'], $rejectedEvents);
        self::assertContains('steer', $rejectedKinds);
        self::assertContains('follow_up', $rejectedKinds);

        // Verify the queued commands are no longer pending
        self::assertCount(0, $commandStore->pending('run-cancel-stale'),
            'All stale queued commands should be rejected after cancel.',
        );
    }
}
