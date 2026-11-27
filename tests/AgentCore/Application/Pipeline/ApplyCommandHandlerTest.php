<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Compaction\PreLlmCompactionGuardInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RejectCommandDTO;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\DTO\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\DTO\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\ToolBatchIdentity;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\CoordinationActionTestRunner;
use Ineersa\AgentCore\Tests\Support\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\AgentCore\Tests\Support\TestToolBatchStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ApplyCommandHandlerTest extends TestCase
{
    public function testFollowUpAllowedAfterCancelledRun(): void
    {
        $commandStore = new InMemoryCommandStore();

        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-followup',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
            model: 'test-model');

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
        $this->assertArrayNotHasKey('options', $result->events[0]->payload);

        // FollowUp dispatches AdvanceRun to pick up the queued message
        $this->assertCount(2, $result->postCommitActions);
        foreach ($result->postCommitActions as $action) {
            CoordinationActionTestRunner::run($action, $commandBus, store: $commandStore);
        }
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
    }

    public function testAppendMessageAllowedAfterCancelledRun(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-append',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [],
            model: 'test-model');

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
        $this->assertCount(2, $result->postCommitActions);
        foreach ($result->postCommitActions as $action) {
            CoordinationActionTestRunner::run($action, $commandBus, store: $commandStore);
        }
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
    }

    public function testSteerCommandRejectedAfterCancelledRun(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-steer',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [],
            model: 'test-model');

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

    /** @param array<string, mixed> $payload */
    #[DataProvider('commandsRejectedDuringCancellation')]
    public function testCommandRejectedDuringCancelling(string $kind, array $payload): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancelling-followup',
            status: RunStatus::Cancelling,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
            model: 'test-model');

        $message = new ApplyCommand(
            runId: 'run-cancelling-followup',
            turnNo: 1,
            stepId: 'followup-step-1',
            attempt: 1,
            idempotencyKey: 'followup-idempotency-1',
            kind: $kind,
            payload: $payload,
        );

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelling, $result->nextState->status);
        $this->assertStringContainsString('rejected because cancellation is in progress', $result->nextState->errorMessage ?? '');
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_rejected', $result->events[0]->type);
        $this->assertSame($kind, $result->events[0]->payload['kind']);
        $this->assertSame([], $result->effects);
        $this->assertSame([], $result->postCommitEffects);
        $this->assertCount(1, $result->postCommitActions);
        $action = $result->postCommitActions[0];
        $this->assertInstanceOf(RejectCommandDTO::class, $action);
        $this->assertSame($message->runId(), $action->runId);
        $this->assertSame($message->idempotencyKey(), $action->idempotencyKey);
        $this->assertSame($result->events[0]->payload['reason'], $action->reason);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function commandsRejectedDuringCancellation(): iterable
    {
        yield 'follow-up' => [CoreCommandKind::FollowUp, ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Next message']]]]];
        yield 'manual compaction' => [CoreCommandKind::Compact, ['custom_instructions' => 'Be brief.']];
    }

    public function testHumanResponseRejectedAfterCancelledRun(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-human',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [],
            model: 'test-model');

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

        // Late human_response after cancel is a safe no-op (no state / events).
        $this->assertNull($result->nextState);
        $this->assertSame([], $result->events);
        $this->assertSame([], $result->postCommitActions);
    }

    public function testSteerWhileRunningQueuesButDoesNotDispatchAdvanceRun(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-steer-while-running',
            status: RunStatus::Running,
            version: 3,
            turnNo: 1,
            lastSeq: 5,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
            model: 'test-model');

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
        $this->assertCount(1, $result->postCommitActions,
            'Steer while Running must not dispatch AdvanceRun — only drain at safe boundary.',
        );

        $this->finalizeMailbox($result, $commandStore);
        // Verify the command is in the store for later drain
        $this->assertTrue($commandStore->has('run-steer-while-running', 'steer-running-1'));
        $this->assertCount(1, $commandStore->pending('run-steer-while-running'));
    }

    public function testFollowUpWhileRunningQueuesButDoesNotDispatchAdvanceRun(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-followup-while-running',
            status: RunStatus::Running,
            version: 3,
            turnNo: 1,
            lastSeq: 5,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
            model: 'test-model');

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
        $this->assertCount(1, $result->postCommitActions,
            'FollowUp while Running must not dispatch AdvanceRun.',
        );
    }

    public function testFollowUpAfterCancelledStillDispatchesAdvanceRun(): void
    {
        $commandStore = new InMemoryCommandStore();

        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-followup-advance',
            status: RunStatus::Cancelled,
            version: 3,
            turnNo: 1,
            lastSeq: 10,
            messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hello']])],
            model: 'test-model');

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
        $this->assertCount(2, $result->postCommitActions,
            'FollowUp after Cancelled must still dispatch AdvanceRun.',
        );

        foreach ($result->postCommitActions as $action) {
            CoordinationActionTestRunner::run($action, $commandBus, store: $commandStore);
        }
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
    }

    public function testCancelRejectsPendingSteerAndFollowUp(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        // Pre-queue a steer and a follow_up command
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-cancel-stale',
            kind: CoreCommandKind::Steer,
            idempotencyKey: 'steer-stale-1',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Stale steer']]]],
        ));

        $commandStore->enqueue(new PendingCommand(
            runId: 'run-cancel-stale',
            kind: CoreCommandKind::FollowUp,
            idempotencyKey: 'followup-stale-1',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Stale follow up']]]],
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
            model: 'test-model');

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
        $this->finalizeMailbox($result, $commandStore);
        $this->assertCount(0, $commandStore->pending('run-cancel-stale'),
            'All stale queued commands should be rejected after cancel.',
        );
    }

    public function testCancelPreservesQueuedAppendMessageForPostCancelMailboxDrain(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $runtimeText = 'Runtime notification line one';
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-append-cancel',
            kind: CoreCommandKind::AppendMessage,
            idempotencyKey: 'append-1',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $runtimeText]]]],
        ));

        $state = new RunState(
            runId: 'run-append-cancel',
            status: RunStatus::Running,
            version: 2,
            turnNo: 1,
            lastSeq: 5,
            activeStepId: 'step-active',
            messages: [],
            model: 'test-model');

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
        $this->assertCount(0, $result->nextState->messages, 'AppendMessage must stay pending until post-cancel AdvanceRun drains mailbox');

        $appliedAppend = array_values(array_filter(
            $result->events,
            static fn ($e) => 'agent_command_applied' === $e->type && CoreCommandKind::AppendMessage === ($e->payload['kind'] ?? null),
        ));
        $this->assertCount(0, $appliedAppend, 'Cancel must not apply AppendMessage inline');
        $this->assertTrue($commandStore->has('run-append-cancel', 'append-1'));
        $this->assertCount(2, $result->postCommitActions);
        foreach ($result->postCommitActions as $action) {
            CoordinationActionTestRunner::run($action, $commandBus, store: $commandStore);
        }
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
        $this->assertStringStartsWith('post-cancel-advance-', $commandBus->messages[0]->stepId());

        $advanceHandler = new AdvanceRunHandler(new InMemoryDeferredToolCompletionRepository(),
            commandMailboxPolicy: new CommandMailboxPolicy($commandStore),
            eventFactory: new EventFactory(),
            tracer: new RunTracer(new NullLogger()),
            preLlmCompactionGuard: $this->createStub(PreLlmCompactionGuardInterface::class),
        );
        $drainResult = $advanceHandler->handle($commandBus->messages[0], $result->nextState);
        $this->assertSame(RunStatus::Running, $drainResult->nextState->status);
        $this->assertCount(1, $drainResult->nextState->messages);
        $this->assertSame($runtimeText, $drainResult->nextState->messages[0]->content[0]['text'] ?? '');
    }

    public function testSecondCancelWhileCancellingPreservesPendingAppendMessage(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $runtimeText = 'Queued between cancels';
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-second-cancel-append',
            kind: CoreCommandKind::AppendMessage,
            idempotencyKey: 'append-between-cancels',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $runtimeText]]]],
        ));

        $state = new RunState(
            runId: 'run-second-cancel-append',
            status: RunStatus::Cancelling,
            version: 8,
            turnNo: 2,
            lastSeq: 40,
            isStreaming: false,
            pendingToolCalls: [],
            messages: [new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'prior']])],
            activeStepId: null,
            model: 'test-model');

        $cancelMessage = new ApplyCommand(
            runId: 'run-second-cancel-append',
            turnNo: 2,
            stepId: 'cancel-repeat-2',
            attempt: 1,
            idempotencyKey: 'cancel-repeat-2',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertCount(1, $result->nextState->messages, 'Only pre-existing messages; pending append must not be applied inline');
        $appliedAppend = array_values(array_filter(
            $result->events,
            static fn ($e) => 'agent_command_applied' === $e->type && CoreCommandKind::AppendMessage === ($e->payload['kind'] ?? null),
        ));
        $this->assertCount(0, $appliedAppend);
        $this->assertTrue($commandStore->has('run-second-cancel-append', 'append-between-cancels'));
        $this->assertCount(2, $result->postCommitActions);
        foreach ($result->postCommitActions as $action) {
            CoordinationActionTestRunner::run($action, $commandBus, store: $commandStore);
        }
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
    }

    public function testAppendMessageQueuedWhileCancelling(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
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
            model: 'test-model');

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
        $this->finalizeMailbox($result, $commandStore);
        $this->assertTrue($commandStore->has('run-append-cancelling', 'append-cancelling-1'));
    }

    public function testOrdinaryFollowUpRejectedWhileCancelling(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-user-cancelling',
            status: RunStatus::Cancelling,
            version: 2,
            turnNo: 1,
            lastSeq: 5,
            messages: [],
            model: 'test-model');

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
    #[DataProvider('terminalCompactionStatuses')]
    public function testCompactOnTerminalRunMarksAppliedNotQueued(RunStatus $status): void
    {
        $commandStore = new InMemoryCommandStore();

        $commandBus = new TestMessageBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-terminal-compact',
            status: $status,
            version: 3,
            turnNo: 5,
            lastSeq: 10,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hi']]),
            ],
            model: 'test-model');

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
        $this->assertSame($status, $result->nextState->status,
            'Terminal compact must not change run status.',
        );

        // Event must be agent_command_applied (not agent_command_queued)
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_applied', $result->events[0]->type,
            'Terminal compact must emit agent_command_applied, not agent_command_queued.',
        );
        $this->assertSame('compact', $result->events[0]->payload['kind']);

        // CompactRun must be dispatched via post-commit callback
        $this->assertCount(2, $result->postCommitActions,
            'Terminal compact must include a post-commit CompactRun dispatch.',
        );
        foreach ($result->postCommitActions as $action) {
            CoordinationActionTestRunner::run($action, $commandBus, store: $commandStore);
        }
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(CompactRun::class, $commandBus->messages[0]);
        $this->assertSame('run-terminal-compact', $commandBus->messages[0]->runId());
        $this->assertSame(5, $commandBus->messages[0]->turnNo());

        // No pending command left in store (would cause duplicate drain)
        $this->assertCount(0, $commandStore->pending('run-terminal-compact'),
            'Terminal compact must not leave a pending command in the store.',
        );
    }

    /** @return iterable<string, array{RunStatus}> */
    public static function terminalCompactionStatuses(): iterable
    {
        yield 'completed' => [RunStatus::Completed];
        yield 'cancelled' => [RunStatus::Cancelled];
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

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
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
            model: 'test-model');

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

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
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
            model: 'test-model');

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

    public function testCompletedCompactCommandIdCanBeReused(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-compact-idempotent',
            status: RunStatus::Completed,
            version: 3,
            turnNo: 5,
            lastSeq: 10,
            messages: [],
            model: 'test-model');

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

        $this->finalizeMailbox($result1, $commandStore);
        $this->assertFalse($commandStore->has($state->runId, $message->idempotencyKey()));
        $result2 = $handler->handle($message, $result1->nextState ?? $state);
        $this->assertNotNull($result2->nextState);
    }

    /**
     * Thesis: cancel from idle Running (no currentOperation, not streaming,
     * no pendingToolCalls) must terminalize immediately to Cancelled with
     * AgentEnd rather than getting stuck in Cancelling.
     */
    public function testCancelFromIdleRunningTerminalizesToCancelled(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-idle-cancel',
            status: RunStatus::Running,
            version: 5,
            turnNo: 2,
            lastSeq: 8,
            isStreaming: false,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hi']]),
            ],
            activeStepId: null,
            currentOperation: null,
            model: 'test-model');

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
        $this->assertNull($result->nextState->currentOperation);
        $this->assertFalse($result->nextState->isStreaming);

        $this->assertSame([], $result->nextState->pendingToolCalls);
        $this->assertSame([], $result->nextState->currentToolCalls);
        $this->assertSame(['agent_command_applied', 'agent_end'], array_column($result->events, 'type'));

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

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-repeat',
            status: RunStatus::Cancelling,
            version: 10,
            turnNo: 3,
            lastSeq: 12,
            isStreaming: false,
            pendingToolCalls: [],
            errorMessage: 'Run cancelled by command.',
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Cancel me']]),
            ],
            activeStepId: 'stale-step',
            currentOperation: null,
            model: 'test-model');

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
        $this->assertNull($result->nextState->activeStepId);
        $this->assertNull($result->nextState->currentOperation);

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

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-stale-step',
            status: RunStatus::Running,
            version: 20,
            turnNo: 15,
            lastSeq: 200,
            isStreaming: false,
            pendingToolCalls: [
                'call_00' => true,
                'call_01' => true,
            ],
            errorMessage: null,
            messages: [],
            activeStepId: 'advance-after-tools-33525236701801',
            currentOperation: null,
            model: 'test-model');

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
        $this->assertNull($result->nextState->currentOperation);
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_end', $eventTypes);
    }

    /**
     * Session 1: cancel while an LLM currentOperation is active must remain
     * Cancelling (no agent_end) until the worker result/abort path terminalizes.
     */
    public function testCancelWithActiveLlmCurrentOperationEntersCancellingWithoutAgentEnd(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $state = new RunState(
            runId: 'run-cancel-active-op',
            status: RunStatus::Running,
            version: 7,
            turnNo: 4,
            lastSeq: 40,
            isStreaming: false,
            pendingToolCalls: [],
            messages: [],
            activeStepId: 'follow_up-18906669744370',
            currentOperation: new CurrentOperationDTO(
                4,
                'follow_up-18906669744370',
                1,
                'bf3b9f9cdb84523728729edf1d3a2c922e682a1b7de8f30522cabe59ab4510c3',
            ),
            model: 'test-model',
        );

        $cancelMessage = new ApplyCommand(
            runId: 'run-cancel-active-op',
            turnNo: 4,
            stepId: 'cancel-active-op',
            attempt: 1,
            idempotencyKey: 'cancel-active-op-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        $this->assertSame(RunStatus::Cancelling, $result->nextState?->status,
            'Active LLM currentOperation must keep cancel in Cancelling until abort/result');
        $this->assertNotNull($result->nextState?->currentOperation);
        $this->assertSame('follow_up-18906669744370', $result->nextState?->activeStepId);
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_command_applied', $eventTypes);
        $this->assertNotContains('agent_end', $eventTypes);
        $this->assertCount(1, $result->postCommitActions);
        $this->assertInstanceOf(MarkCommandAppliedDTO::class, $result->postCommitActions[0]);
    }

    /**
     * Standalone shell currentOperation must not delay cancel: its result path
     * does not honor Cancelling, so cancel terminalizes immediately.
     */
    public function testCancelWithStandaloneShellCurrentOperationTerminalizesImmediately(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            maxPendingCommands: 10,
        );

        $shellKey = 'shell-standalone-key';
        $shellToolCallId = 'sh_'.hash('sha256', $shellKey);
        $state = new RunState(
            runId: 'run-cancel-shell-op',
            status: RunStatus::Running,
            version: 3,
            turnNo: 2,
            lastSeq: 8,
            isStreaming: false,
            pendingToolCalls: [],
            pendingShellToolCalls: [$shellToolCallId => true],
            messages: [],
            activeStepId: 'shell-step',
            currentOperation: new CurrentOperationDTO(2, 'shell-step', 1, $shellKey),
            model: 'test-model',
        );

        $cancelMessage = new ApplyCommand(
            runId: 'run-cancel-shell-op',
            turnNo: 2,
            stepId: 'cancel-shell-op',
            attempt: 1,
            idempotencyKey: 'cancel-shell-op-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        );

        $result = $handler->handle($cancelMessage, $state);

        $this->assertSame(RunStatus::Cancelled, $result->nextState?->status,
            'Standalone shell currentOperation must not keep cancel in Cancelling');
        $this->assertNull($result->nextState?->currentOperation);
        $this->assertNull($result->nextState?->activeStepId);
        $eventTypes = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains('agent_end', $eventTypes);
    }

    public function testCancelWithUnresolvedPendingToolCallEntersCancelling(): void
    {
        $commandStore = new InMemoryCommandStore();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
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
            model: 'test-model');

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

    #[DataProvider('terminalCancellationCases')]
    public function testTerminalCancellationMatchesWholeReplayState(RunStatus $status, ?string $reason, bool $rejectQueuedCommands): void
    {
        $runId = 'run-terminal-cancel';
        $stepId = 'tool-step';
        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        $codec = new ToolExecutionEndPayloadCodec($serializer);
        $reducer = new RunStateReducer($serializer, $codec, new AgentMessageNormalizer(), new ReplayAssistantMessageFactory());
        $commandStore = new InMemoryCommandStore();
        $collector = new ToolBatchCollector($batchStore = new TestToolBatchStore(), 2);
        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: $collector,
            logger: new NullLogger(),
            serializer: $serializer,
            maxPendingCommands: 10,
        );
        $deferred = RunStatus::WaitingHuman === $status;
        $calls = [['id' => 'completed', 'name' => 'read', 'arguments' => [], 'order_index' => 0]];
        if ($deferred) {
            $calls[] = ['id' => 'waiting', 'name' => 'ask_human', 'arguments' => [], 'order_index' => 1];
            TestToolBatchRegistration::register($collector, $batchStore, $runId, 1, $stepId, [
                new ExecuteToolCall($runId, 1, $stepId, 1, 'read-invocation', 'completed', 'read', [], 0),
                new ExecuteToolCall($runId, 1, $stepId, 1, 'ask-invocation', 'waiting', 'ask_human', [], 1),
            ]);
        }
        $completed = new ToolCallResult(
            $runId, 1, $stepId, 1, 'read-invocation', 'completed', 0,
            ['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => 'Read result']]], false, null,
        );
        $history = [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'step_id' => 'start',
                'payload' => [
                    'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Read the file']]]],
                    'metadata' => ['model' => 'test-model'],
                ],
            ]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['step_id' => $stepId, 'turn_no' => 1]),
            new RunEvent($runId, 3, 1, RunEventTypeEnum::LlmStepCompleted->value, [
                'step_id' => $stepId,
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Checking the file']],
                    'tool_calls' => $calls,
                ],
            ]),
            new RunEvent($runId, 4, 1, RunEventTypeEnum::ToolExecutionEnd->value, $codec->toEventPayload($completed)),
        ];
        if ($deferred) {
            $this->assertTrue(TestToolBatchCoordination::collect($collector, $batchStore, $completed)->accepted);
            TestToolBatchCoordination::suspend($collector, $batchStore, $runId, 1, $stepId, 'waiting', 'question');
            $history[] = new RunEvent($runId, 5, 1, RunEventTypeEnum::WaitingHuman->value, [
                'kind' => 'interrupt',
                'question_id' => 'question',
                'prompt' => 'Continue?',
                'continuation_kind' => 'tool_call',
                'continuation_ref' => ['run_id' => $runId, 'turn_no' => 1, 'step_id' => $stepId, 'tool_call_id' => 'waiting'],
            ]);
        } elseif (RunStatus::Cancelling === $status) {
            $history[] = new RunEvent($runId, 5, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'cancel',
                'idempotency_key' => 'prior-cancel',
            ]);
        }
        if ($rejectQueuedCommands) {
            foreach ([CoreCommandKind::Steer, CoreCommandKind::FollowUp] as $kind) {
                $commandStore->enqueue(new PendingCommand(
                    runId: $runId,
                    kind: $kind,
                    idempotencyKey: 'queued-'.$kind,
                    payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Queued input']]]],
                ));
            }
        }

        $state = $reducer->replay(RunState::queued($runId)->with(['version' => 7]), $history);
        $this->assertSame($status, $state->status);
        $this->assertSame($deferred ? ['completed' => true, 'waiting' => false] : ['completed' => true], $state->pendingToolCalls);
        $this->assertCount($deferred ? 2 : 1, $state->currentToolCalls);
        $this->assertCount($deferred ? 1 : 0, $state->pendingHumanInputRequests);
        $this->assertFalse($state->isStreaming);
        $this->assertNull($state->currentOperation);
        $this->assertSame($stepId, $state->activeStepId);

        $result = $handler->handle(new ApplyCommand(
            runId: $runId,
            turnNo: 1,
            stepId: 'cancel-step',
            attempt: 1,
            idempotencyKey: 'cancel-command',
            kind: CoreCommandKind::Cancel,
            payload: null === $reason ? [] : ['reason' => $reason],
        ), $state);

        $live = $result->nextState;
        $this->assertNotNull($live);
        $this->assertSame(RunStatus::Cancelled, $live->status);
        $this->assertSame($reason ?? 'Run cancelled by command.', $live->errorMessage);
        $this->assertSame([
            'agent_command_applied',
            ...($rejectQueuedCommands ? ['agent_command_rejected', 'agent_command_rejected'] : []),
            ...($deferred ? ['tool_execution_end', 'tool_batch_committed'] : []),
            'agent_end',
        ], array_column($result->events, 'type'));

        // Version belongs to the stored transition, not to the event stream.
        $replayed = $reducer->replay($live, [...$history, ...$result->events]);
        $this->assertEquals($live, $replayed);
        $this->assertSame([], $live->pendingToolCalls);
        $this->assertSame([], $live->currentToolCalls);
        $this->assertSame([], $live->pendingHumanInputRequests);
        $this->assertNull($live->activeStepId);
        $this->assertNull($live->currentOperation);
        $this->assertFalse($live->isStreaming);
        $this->assertSame($deferred ? ['user', 'assistant', 'tool', 'tool'] : ['user', 'assistant'], array_column($live->messages, 'role'));
    }

    public static function terminalCancellationCases(): iterable
    {
        yield 'first cancel with completed pending entry and queued commands' => [RunStatus::Running, null, true];
        yield 'already cancelling without active work' => [RunStatus::Cancelling, 'Stop the completed batch.', false];
        yield 'deferred tool human wait with queued commands' => [RunStatus::WaitingHuman, 'Do not wait for approval.', true];
    }

    public function testCancelDeferredToolCallHumanWaitTerminalizesWithSyntheticResults(): void
    {
        $commandStore = new InMemoryCommandStore();
        $collector = new ToolBatchCollector($batchStore = new TestToolBatchStore());
        TestToolBatchRegistration::register($collector, $batchStore, 'run-deferred-cancel', 1, 'step-d', [
            new ExecuteToolCall(
                'run-deferred-cancel',
                1,
                'step-d',
                1,
                'idemp-d',
                'call-d',
                'bash',
                ['command' => 'ls'],
                0,
            ),
        ]);
        TestToolBatchCoordination::suspend($collector, $batchStore, 'run-deferred-cancel', 1, 'step-d', 'call-d', 'q-d');

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: $collector,
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::serializer(),
            maxPendingCommands: 10,
        );

        $assistant = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'need approval']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'call-d', 'name' => 'bash', 'arguments' => ['command' => 'ls'], 'order_index' => 0],
                ],
            ],
        );

        $state = new RunState(
            runId: 'run-deferred-cancel',
            status: RunStatus::WaitingHuman,
            version: 4,
            turnNo: 1,
            lastSeq: 9,
            isStreaming: false,
            pendingToolCalls: ['call-d' => false],
            messages: [$assistant],
            activeStepId: 'step-d',
            currentToolCalls: [
                new CurrentToolCallDTO(
                    ToolBatchIdentity::fromTurnAndStep(1, 'step-d'),
                    'call-d',
                    0,
                    RunOperationalToolCallStatusEnum::WaitingHuman,
                    1,
                ),
            ],
            pendingHumanInputRequests: [
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q-d', 'prompt' => 'Allow?'],
                    ['run_id' => 'run-deferred-cancel', 'turn_no' => 1, 'step_id' => 'step-d', 'tool_call_id' => 'call-d'],
                ),
            ],
            model: 'test-model',
        );

        $result = $handler->handle(new ApplyCommand(
            runId: 'run-deferred-cancel',
            turnNo: 1,
            stepId: 'cancel-deferred',
            attempt: 1,
            idempotencyKey: 'cancel-deferred-1',
            kind: CoreCommandKind::Cancel,
            payload: ['reason' => 'Outstanding human questions cancelled on session attach.'],
        ), $state);

        $this->assertSame(RunStatus::Cancelled, $result->nextState?->status);
        $this->assertSame([], $result->nextState?->pendingToolCalls);
        $this->assertSame([], $result->nextState?->pendingHumanInputRequests);
        $this->assertSame(
            ['agent_command_applied', 'tool_execution_end', 'tool_batch_committed', 'agent_end'],
            array_map(static fn ($event): string => $event->type, $result->events),
        );
        $this->assertSame('tool', $result->nextState?->messages[1]->role);
        $this->assertSame('call-d', $result->nextState?->messages[1]->toolCallId);
        $this->assertTrue($result->nextState?->messages[1]->isError);
        $synthetic = (new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()))
            ->fromEventPayload($result->events[1]->payload);
        $text = 'Tool execution cancelled by user.';
        $this->assertSame('run-deferred-cancel', $synthetic->runId());
        $this->assertSame(1, $synthetic->turnNo());
        $this->assertSame('step-d', $synthetic->stepId());
        $this->assertSame(1, $synthetic->attempt());
        $this->assertSame(hash('sha256', 'cancel-run-deferred-cancel-call-d'), $synthetic->idempotencyKey());
        $this->assertSame('call-d', $synthetic->toolCallId);
        $this->assertSame(0, $synthetic->orderIndex);
        $this->assertTrue($synthetic->isError);
        $this->assertSame(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => $text]]], $synthetic->result);
        $this->assertSame(['type' => 'cancelled', 'message' => $text], $synthetic->error);
        $finalizations = array_values(array_filter($result->postCommitActions, static fn (object $action): bool => $action instanceof FinalizeToolBatchDTO));
        $this->assertCount(1, $finalizations);
        $this->assertTrue($finalizations[0]->finalized);
        $this->assertSame([], $finalizations[0]->pendingQueue);
        $this->assertSame([], $finalizations[0]->inFlight);
        $this->assertSame([], $finalizations[0]->awaitingHumanInput);
    }

    public function testCancelMultiDeferredToolCallHumanWaitsSynthesizesAll(): void
    {
        $commandStore = new InMemoryCommandStore();
        $collector = new ToolBatchCollector($batchStore = new TestToolBatchStore(), 2);
        TestToolBatchRegistration::register($collector, $batchStore, 'run-multi-d', 1, 'step-m', [
            new ExecuteToolCall('run-multi-d', 1, 'step-m', 1, 'idemp-a', 'call-a', 'bash', ['command' => 'a'], 0),
            new ExecuteToolCall('run-multi-d', 1, 'step-m', 1, 'idemp-b', 'call-b', 'bash', ['command' => 'b'], 1),
        ]);
        TestToolBatchCoordination::suspend($collector, $batchStore, 'run-multi-d', 1, 'step-m', 'call-a', 'q-a');
        TestToolBatchCoordination::suspend($collector, $batchStore, 'run-multi-d', 1, 'step-m', 'call-b', 'q-b');

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: $collector,
            logger: new NullLogger(),
            serializer: AttributeSerializerValidatorTestFactory::serializer(),
            maxPendingCommands: 10,
        );

        $assistant = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'need two']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'call-a', 'name' => 'bash', 'arguments' => ['command' => 'a'], 'order_index' => 0],
                    ['id' => 'call-b', 'name' => 'bash', 'arguments' => ['command' => 'b'], 'order_index' => 1],
                ],
            ],
        );

        $state = new RunState(
            runId: 'run-multi-d',
            status: RunStatus::WaitingHuman,
            version: 6,
            turnNo: 1,
            lastSeq: 12,
            pendingToolCalls: ['call-b' => false, 'call-done' => true, 'call-a' => false],
            messages: [$assistant, new AgentMessage(role: 'tool', content: [['type' => 'text', 'text' => 'completed']], toolCallId: 'call-done')],
            activeStepId: 'step-m',
            pendingHumanInputRequests: [
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q-a', 'prompt' => 'A?'],
                    ['run_id' => 'run-multi-d', 'turn_no' => 1, 'step_id' => 'step-m', 'tool_call_id' => 'call-a'],
                ),
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q-b', 'prompt' => 'B?'],
                    ['run_id' => 'run-multi-d', 'turn_no' => 1, 'step_id' => 'step-m', 'tool_call_id' => 'call-b'],
                ),
            ],
            model: 'test-model',
        );

        $result = $handler->handle(new ApplyCommand(
            runId: 'run-multi-d',
            turnNo: 1,
            stepId: 'cancel-multi',
            attempt: 1,
            idempotencyKey: 'cancel-multi-1',
            kind: CoreCommandKind::Cancel,
            payload: [],
        ), $state);

        $this->assertSame(RunStatus::Cancelled, $result->nextState?->status);
        $this->assertSame([], $result->nextState?->pendingToolCalls);
        $this->assertSame([], $result->nextState?->pendingHumanInputRequests);
        $toolMessages = array_values(array_filter(
            $result->nextState?->messages ?? [],
            static fn (AgentMessage $message): bool => 'tool' === $message->role,
        ));
        $this->assertCount(3, $toolMessages);
        $this->assertSame(['call-done', 'call-a', 'call-b'], array_map(static fn (AgentMessage $message): ?string => $message->toolCallId, $toolMessages));
        $this->assertContains('agent_end', array_map(static fn ($event) => $event->type, $result->events));
    }

    public function testCancelPreservesCompletedUnprojectedSiblingAndSynthesizesOnlyDeferredWait(): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        $commandStore = new InMemoryCommandStore();
        $collector = new ToolBatchCollector($batchStore = new TestToolBatchStore(), 2);
        TestToolBatchRegistration::register($collector, $batchStore, 'run-partial-d', 1, 'step-p', [
            new ExecuteToolCall('run-partial-d', 1, 'step-p', 1, 'idemp-a', 'call-a', 'bash', ['command' => 'a'], 0),
            new ExecuteToolCall('run-partial-d', 1, 'step-p', 1, 'idemp-b', 'call-b', 'ask_human', ['prompt' => 'B?'], 1),
        ]);

        $completedA = new ToolCallResult(
            runId: 'run-partial-d',
            turnNo: 1,
            stepId: 'step-p',
            attempt: 1,
            idempotencyKey: 'result-a',
            toolCallId: 'call-a',
            orderIndex: 0,
            result: [
                'tool_name' => 'bash',
                'content' => [['type' => 'text', 'text' => 'A succeeded']],
                'details' => [
                    'model_notifications' => [[
                        'id' => 'n-a',
                        'source' => 'output_cap',
                        'kind' => 'output_capped',
                        'severity' => 'warning',
                        'delivery' => 'tool_result_replace',
                        'text' => 'A capped for model',
                        'tool_call_id' => 'call-a',
                        'tool_name' => 'bash',
                        'order_index' => 0,
                    ]],
                ],
            ],
            isError: false,
            error: null,
        );
        $accepted = TestToolBatchCoordination::collect($collector, $batchStore, $completedA);
        $this->assertTrue($accepted->accepted);
        $this->assertFalse($accepted->complete);
        $this->assertArrayHasKey('call-a', $collector->getStoredResults('run-partial-d', 1, 'step-p'));

        TestToolBatchCoordination::suspend($collector, $batchStore, 'run-partial-d', 1, 'step-p', 'call-b', 'q-b');

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: $collector,
            logger: new NullLogger(),
            serializer: $serializer,
            maxPendingCommands: 10,
        );

        $assistant = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'tools']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'call-a', 'name' => 'bash', 'arguments' => ['command' => 'a'], 'order_index' => 0],
                    ['id' => 'call-b', 'name' => 'ask_human', 'arguments' => ['prompt' => 'B?'], 'order_index' => 1],
                ],
            ],
        );

        $state = new RunState(
            runId: 'run-partial-d',
            status: RunStatus::WaitingHuman,
            version: 5,
            turnNo: 1,
            lastSeq: 11,
            pendingToolCalls: ['call-a' => true, 'call-b' => false],
            messages: [$assistant],
            activeStepId: 'step-p',
            pendingHumanInputRequests: [
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q-b', 'prompt' => 'B?'],
                    ['run_id' => 'run-partial-d', 'turn_no' => 1, 'step_id' => 'step-p', 'tool_call_id' => 'call-b'],
                ),
            ],
            model: 'test-model',
        );

        $result = $handler->handle(new ApplyCommand(
            runId: 'run-partial-d',
            turnNo: 1,
            stepId: 'cancel-partial',
            attempt: 1,
            idempotencyKey: 'cancel-partial-1',
            kind: CoreCommandKind::Cancel,
            payload: ['reason' => 'Outstanding human questions cancelled on session attach.'],
        ), $state);

        $this->assertSame(RunStatus::Cancelled, $result->nextState?->status);
        $this->assertSame([], $result->nextState?->pendingToolCalls);
        $this->assertSame([], $result->nextState?->pendingHumanInputRequests);

        $toolMessages = array_values(array_filter(
            $result->nextState?->messages ?? [],
            static fn (AgentMessage $message): bool => 'tool' === $message->role,
        ));
        $this->assertCount(2, $toolMessages);
        $this->assertSame('call-a', $toolMessages[0]->toolCallId);
        $this->assertFalse($toolMessages[0]->isError);
        $this->assertSame('A capped for model', $toolMessages[0]->content[0]['text'] ?? null);
        $this->assertSame($completedA->result, $toolMessages[0]->details);
        $this->assertIsArray($toolMessages[0]->details['details']['model_notifications'] ?? null);
        $this->assertSame(
            'A capped for model',
            $toolMessages[0]->details['details']['model_notifications'][0]['text'] ?? null,
        );
        $this->assertSame('call-b', $toolMessages[1]->toolCallId);
        $this->assertTrue($toolMessages[1]->isError);

        $eventTypes = array_map(static fn ($event): string => $event->type, $result->events);
        $this->assertSame(
            ['agent_command_applied', 'tool_execution_end', 'tool_batch_committed', 'agent_end'],
            $eventTypes,
        );
        $codec = new ToolExecutionEndPayloadCodec($serializer);
        $ends = array_values(array_filter(
            $result->events,
            static fn ($event): bool => RunEventTypeEnum::ToolExecutionEnd->value === $event->type,
        ));
        $this->assertCount(1, $ends);
        $this->assertSame('call-b', $codec->fromEventPayload($ends[0]->payload)->toolCallId);

        $priorAEnd = new RunEvent(
            'run-partial-d',
            10,
            1,
            RunEventTypeEnum::ToolExecutionEnd->value,
            $codec->toEventPayload($completedA),
        );
        $replayed = (new RunStateReducer(
            AttributeSerializerValidatorTestFactory::denormalizer(),
            $codec,
            messageNormalizer: new AgentMessageNormalizer(),
            assistantMessageFactory: new ReplayAssistantMessageFactory(),
        ))->replay(
            RunState::queued('run-partial-d')->with([
                'model' => 'test-model',
            ]),
            array_merge(
                [
                    new RunEvent('run-partial-d', 1, 0, RunEventTypeEnum::RunStarted->value, [
                        'step_id' => 'start',
                        'payload' => [
                            'messages' => [],
                            'metadata' => ['model' => 'test-model'],
                        ],
                    ]),
                    new RunEvent('run-partial-d', 2, 1, RunEventTypeEnum::LlmStepCompleted->value, [
                        'step_id' => 'step-p',
                        'turn_no' => 1,
                        'assistant_message' => [
                            'role' => 'assistant',
                            'content' => [['type' => 'text', 'text' => 'tools']],
                            'tool_calls' => [
                                ['id' => 'call-a', 'name' => 'bash', 'arguments' => ['command' => 'a'], 'order_index' => 0],
                                ['id' => 'call-b', 'name' => 'ask_human', 'arguments' => ['prompt' => 'B?'], 'order_index' => 1],
                            ],
                        ],
                    ]),
                    $priorAEnd,
                    new RunEvent('run-partial-d', 11, 1, RunEventTypeEnum::WaitingHuman->value, [
                        'kind' => 'interrupt',
                        'question_id' => 'q-b',
                        'prompt' => 'B?',
                        'continuation_kind' => 'tool_call',
                        'continuation_ref' => [
                            'run_id' => 'run-partial-d',
                            'turn_no' => 1,
                            'step_id' => 'step-p',
                            'tool_call_id' => 'call-b',
                        ],
                    ]),
                ],
                $result->events,
            ),
        );

        $replayTools = array_values(array_filter(
            $replayed->messages,
            static fn (AgentMessage $message): bool => 'tool' === $message->role,
        ));
        $this->assertCount(2, $replayTools);
        $this->assertSame($toolMessages[0]->toolCallId, $replayTools[0]->toolCallId);
        $this->assertSame($toolMessages[0]->isError, $replayTools[0]->isError);
        $this->assertSame($toolMessages[0]->content, $replayTools[0]->content);
        $this->assertSame($toolMessages[0]->details, $replayTools[0]->details);
        $this->assertSame($toolMessages[0]->toolName, $replayTools[0]->toolName);
        $this->assertSame($toolMessages[1]->toolCallId, $replayTools[1]->toolCallId);
        $this->assertSame($toolMessages[1]->isError, $replayTools[1]->isError);
        $this->assertSame($toolMessages[1]->content, $replayTools[1]->content);
        $this->assertSame($toolMessages[1]->details, $replayTools[1]->details);
        $this->assertSame($toolMessages[1]->toolName, $replayTools[1]->toolName);
        $this->assertSame($result->nextState->status, $replayed->status);
        $this->assertSame($result->nextState->pendingToolCalls, $replayed->pendingToolCalls);
        $this->assertSame($result->nextState->currentToolCalls, $replayed->currentToolCalls);
        $this->assertSame($result->nextState->pendingHumanInputRequests, $replayed->pendingHumanInputRequests);
    }

    public function testCancelCompletedUnprojectedSiblingFailsClosedWhenStoredResultMissing(): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        $commandStore = new InMemoryCommandStore();
        $collector = new ToolBatchCollector($batchStore = new TestToolBatchStore(), 2);
        TestToolBatchRegistration::register($collector, $batchStore, 'run-missing-store', 1, 'step-m', [
            new ExecuteToolCall('run-missing-store', 1, 'step-m', 1, 'idemp-a', 'call-a', 'bash', ['command' => 'a'], 0, mode: 'parallel', maxParallelism: 2),
            new ExecuteToolCall('run-missing-store', 1, 'step-m', 1, 'idemp-b', 'call-b', 'ask_human', ['prompt' => 'B?'], 1, mode: 'parallel', maxParallelism: 2),
        ]);
        TestToolBatchCoordination::suspend($collector, $batchStore, 'run-missing-store', 1, 'step-m', 'call-b', 'q-b');

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolBatchCollector: $collector,
            logger: new NullLogger(),
            serializer: $serializer,
            maxPendingCommands: 10,
        );

        $assistant = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'tools']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'call-a', 'name' => 'bash', 'arguments' => ['command' => 'a'], 'order_index' => 0],
                    ['id' => 'call-b', 'name' => 'ask_human', 'arguments' => ['prompt' => 'B?'], 'order_index' => 1],
                ],
            ],
        );

        $state = new RunState(
            runId: 'run-missing-store',
            status: RunStatus::WaitingHuman,
            version: 5,
            turnNo: 1,
            lastSeq: 11,
            pendingToolCalls: ['call-a' => true, 'call-b' => false],
            messages: [$assistant],
            activeStepId: 'step-m',
            pendingHumanInputRequests: [
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q-b', 'prompt' => 'B?'],
                    ['run_id' => 'run-missing-store', 'turn_no' => 1, 'step_id' => 'step-m', 'tool_call_id' => 'call-b'],
                ),
            ],
            model: 'test-model',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot project completed tool call "call-a" during deferred-tool cancel: stored result is missing for turn=1 step=step-m.');

        $handler->handle(new ApplyCommand(
            runId: 'run-missing-store',
            turnNo: 1,
            stepId: 'cancel-missing',
            attempt: 1,
            idempotencyKey: 'cancel-missing-1',
            kind: CoreCommandKind::Cancel,
            payload: ['reason' => 'Outstanding human questions cancelled on session attach.'],
        ), $state);
    }

    private function finalizeMailbox(HandlerResult $result, CommandStoreInterface $store): void
    {
        foreach ($result->postCommitActions as $action) {
            if ($action instanceof EnqueueCommandDTO || $action instanceof MarkCommandAppliedDTO || $action instanceof RejectCommandDTO) {
                CoordinationActionTestRunner::run($action, store: $store);
            }
        }
    }
}
