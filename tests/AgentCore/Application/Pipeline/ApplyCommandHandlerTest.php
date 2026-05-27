<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\RunMessageStateTools;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

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

        $commandBus = new ApplyCommandRecordingBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            stateTools: new RunMessageStateTools(new EventFactory(), new ToolCallExtractor()),
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

        $commandBus = new ApplyCommandRecordingBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            stateTools: new RunMessageStateTools(new EventFactory(), new ToolCallExtractor()),
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
            stateTools: new RunMessageStateTools(new EventFactory(), new ToolCallExtractor()),
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
            stateTools: new RunMessageStateTools(new EventFactory(), new ToolCallExtractor()),
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
            stateTools: new RunMessageStateTools(new EventFactory(), new ToolCallExtractor()),
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
            stateTools: new RunMessageStateTools(new EventFactory(), new ToolCallExtractor()),
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
}

final class ApplyCommandRecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
