<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\ToolBatchIdentity;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\Builder\AdvanceRunMessageBuilder;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallResultBuilder;
use Ineersa\AgentCore\Tests\Support\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\AgentCore\Tests\Support\TestToolBatchStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class ToolCallResultHandlerTest extends TestCase
{
    private string $toolBatchProjectDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->toolBatchProjectDir = TestDirectoryIsolation::createOsTempDir('tool-call-result-handler-batch');
        TestDirectoryIsolation::createHatfieldTree($this->toolBatchProjectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->toolBatchProjectDir);
        parent::tearDown();
    }

    public function testStandaloneShellCompletionPreservesAttachedShellAndWakesMailbox(): void
    {
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );
        $state = RunStateBuilder::running('run-shell')
            ->withVersion(3)
            ->withTurnNo(2)
            ->withLastSeq(4)
            ->withActiveStepId('shell-step')
            ->build()
            ->with([
                'pendingShellToolCalls' => ['shell-call-a' => true, 'shell-call-b' => true],
                'currentOperation' => new CurrentOperationDTO(2, 'shell-step', 2, 'shell-operation'),
                'currentToolCalls' => [
                    new CurrentToolCallDTO(
                        ToolBatchIdentity::fromTurnAndStep(2, 'shell-step'),
                        'shell-call-a',
                        0,
                        RunOperationalToolCallStatusEnum::Running,
                        2,
                    ),
                    new CurrentToolCallDTO(
                        ToolBatchIdentity::fromTurnAndStep(2, 'shell-step'),
                        'shell-call-b',
                        1,
                        RunOperationalToolCallStatusEnum::Running,
                        2,
                    ),
                ],
            ]);
        $standalone = ToolCallResultBuilder::success('run-shell')
            ->withTurnNo(2)
            ->withStepId('shell-step')
            ->withIdempotencyKey('shell-result-a')
            ->withToolCallId('shell-call-a')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'bash',
                'content' => [['type' => 'text', 'text' => 'A done']],
                'arguments' => ['command' => 'echo A'],
                'standalone' => true,
            ])
            ->build();

        $completedStandalone = $handler->handle($standalone, $state);

        $this->assertSame(RunStatus::Completed, $completedStandalone->nextState?->status);
        $this->assertSame([], $completedStandalone->nextState?->pendingToolCalls);
        $this->assertSame(['shell-call-b' => true], $completedStandalone->nextState?->pendingShellToolCalls);
        $this->assertSame(['shell-call-b'], array_map(static fn (CurrentToolCallDTO $call): string => $call->toolCallId, $completedStandalone->nextState?->currentToolCalls ?? []));
        $this->assertNull($completedStandalone->nextState?->activeStepId);
        $this->assertNull($completedStandalone->nextState?->currentOperation);
        $this->assertSame(
            [RunEventTypeEnum::ToolExecutionEnd->value, RunEventTypeEnum::AgentEnd->value],
            array_map(static fn ($event): string => $event->type, $completedStandalone->events),
        );
        $this->assertSame([], $handler->handle($standalone, $completedStandalone->nextState)->events);

        $commandStore = new InMemoryCommandStore();
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-shell',
            kind: CoreCommandKind::FollowUp,
            idempotencyKey: 'queued-during-shell',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'follow up']]]],
            options: new CommandCancellationOptions(safe: false),
        ));
        $advance = new AdvanceRunHandler(
            commandMailboxPolicy: new CommandMailboxPolicy($commandStore, new CommandRouter([])),
            eventFactory: new EventFactory(),
        );
        $woken = $advance->handle(AdvanceRunMessageBuilder::create('run-shell')
            ->withTurnNo(2)
            ->withStepId('shell-follow-up-step')
            ->withIdempotencyKey('shell-standalone-advance')
            ->build(), $completedStandalone->nextState);

        $this->assertNotNull($woken->nextState, 'Standalone shell wake must be accepted to drain a command queued during execution.');
        $this->assertCount(1, $woken->nextState->messages);

        $attached = ToolCallResultBuilder::success('run-shell')
            ->withTurnNo(2)
            ->withStepId('shell-step')
            ->withIdempotencyKey('shell-result-b')
            ->withToolCallId('shell-call-b')
            ->withOrderIndex(1)
            ->withResult([
                'tool_name' => 'bash',
                'content' => [['type' => 'text', 'text' => 'B done']],
                'arguments' => ['command' => 'echo B'],
                'standalone' => false,
            ])
            ->build();
        $completedAttached = $handler->handle($attached, $completedStandalone->nextState);

        $this->assertSame([], $completedAttached->nextState?->pendingShellToolCalls);
        $this->assertSame([], $completedAttached->nextState?->currentToolCalls);
        $this->assertSame([RunEventTypeEnum::ToolExecutionEnd->value], array_map(static fn ($event): string => $event->type, $completedAttached->events));
        $typedAttachedResult = (new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()))
            ->fromEventPayload($completedAttached->events[0]->payload);
        $this->assertSame($attached->result, $typedAttachedResult->result);
    }

    public function testHandleAcceptedPendingResultReturnsPostCommitEffectsForNextToolCall(): void
    {
        $collector = new ToolBatchCollector($store = new TestToolBatchStore());

        TestToolBatchRegistration::register($collector, $store,
            runId: 'run-tool-handler-1',
            turnNo: 1,
            stepId: 'turn-1-step',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-tool-handler-1',
                    turnNo: 1,
                    stepId: 'turn-1-step',
                    attempt: 1,
                    idempotencyKey: 'exec-tool-a',
                    toolCallId: 'tool-a',
                    toolName: 'alpha',
                    args: [],
                    orderIndex: 0,
                    maxParallelism: 1,
                ),
                new ExecuteToolCall(
                    runId: 'run-tool-handler-1',
                    turnNo: 1,
                    stepId: 'turn-1-step',
                    attempt: 1,
                    idempotencyKey: 'exec-tool-b',
                    toolCallId: 'tool-b',
                    toolName: 'beta',
                    args: [],
                    orderIndex: 1,
                    maxParallelism: 1,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $state = RunStateBuilder::running('run-tool-handler-1')
            ->withVersion(5)
            ->withTurnNo(1)
            ->withLastSeq(6)
            ->withPendingToolCalls([
                'tool-a' => false,
                'tool-b' => false,
            ])
            ->withActiveStepId('turn-1-step')
            ->build()
            ->with(['currentToolCalls' => [
                new CurrentToolCallDTO(ToolBatchIdentity::fromTurnAndStep(1, 'turn-1-step'), 'tool-a', 0, RunOperationalToolCallStatusEnum::Running, 1),
                new CurrentToolCallDTO(ToolBatchIdentity::fromTurnAndStep(1, 'turn-1-step'), 'tool-b', 1, RunOperationalToolCallStatusEnum::Running, 1),
            ]]);

        $message = ToolCallResultBuilder::success('run-tool-handler-1')
            ->withTurnNo(1)
            ->withStepId('turn-1-step')
            ->withIdempotencyKey('tool-result-a')
            ->withToolCallId('tool-a')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'alpha',
                'content' => [['type' => 'text', 'text' => 'A']],
            ])
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status);
        $this->assertSame(6, $result->nextState->version);
        $this->assertSame(7, $result->nextState->lastSeq);
        $this->assertSame([
            'tool-a' => true,
            'tool-b' => false,
        ], $result->nextState->pendingToolCalls);
        $this->assertSame([RunOperationalToolCallStatusEnum::Completed, RunOperationalToolCallStatusEnum::Running], array_map(static fn (CurrentToolCallDTO $toolCall): RunOperationalToolCallStatusEnum => $toolCall->status, $result->nextState->currentToolCalls));

        $this->assertCount(1, $result->events);
        $this->assertSame('tool_execution_end', $result->events[0]->type);
        $this->assertSame(['tool_result'], array_keys($result->events[0]->payload));
        [$serializer] = AttributeSerializerValidatorTestFactory::create();
        $typedResult = (new ToolExecutionEndPayloadCodec($serializer))
            ->fromEventPayload($result->events[0]->payload);
        $this->assertSame('run-tool-handler-1', $typedResult->runId());
        $this->assertSame('turn-1-step', $typedResult->stepId());
        $this->assertSame('tool-result-a', $typedResult->idempotencyKey());
        $this->assertSame('tool-a', $typedResult->toolCallId);
        $this->assertSame($message->result, $typedResult->result);
        $this->assertFalse($typedResult->isError);

        $this->assertSame([], $result->effects);
        $this->assertCount(1, $result->postCommitEffects);
        $this->assertInstanceOf(ExecuteToolCall::class, $result->postCommitEffects[0]);
        $this->assertSame('tool-b', $result->postCommitEffects[0]->toolCallId);
        $this->assertCount(1, $result->postCommitActions);
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO::class, $result->postCommitActions[0]);
    }

    public function testUntrackedCurrentTokenRedeliveryIsIdempotentNoOp(): void
    {
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $state = RunStateBuilder::running('run-untracked-current-token')
            ->withTurnNo(1)
            ->withActiveStepId('step-1')
            ->build();
        $message = ToolCallResultBuilder::success('run-untracked-current-token')
            ->withTurnNo(1)
            ->withStepId('step-1')
            ->withIdempotencyKey('untracked-result')
            ->withToolCallId('unknown-call')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => 'ignored']]])
            ->build();

        foreach ([$handler->handle($message, $state), $handler->handle($message, $state)] as $result) {
            $this->assertNull($result->nextState);
            $this->assertSame([], $result->events);
            $this->assertSame([], $result->effects);
            $this->assertSame([], $result->postCommitEffects);
            $this->assertSame([], $result->postCommitActions);
        }
    }

    public function testCancellingWithPendingToolCallsSynthesizesToolMessages(): void
    {
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $assistantMsg = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Let me check']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'tc-cat', 'name' => 'bash', 'arguments' => ['command' => 'ls'], 'order_index' => 0],
                ],
            ],
        );

        $state = RunStateBuilder::running('run-cancel-test')
            ->withStatus(RunStatus::Cancelling)
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(4)
            ->withPendingToolCalls(['tc-cat' => false])
            ->withActiveStepId('turn-step-1')
            ->withMessages([$assistantMsg])
            ->build();

        $message = ToolCallResultBuilder::success('run-cancel-test')
            ->withTurnNo(1)
            ->withStepId('turn-step-1')
            ->withIdempotencyKey('tool-result-arriving')
            ->withToolCallId('tc-cat')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'results']]])
            ->build();

        $result = $handler->handle($message, $state);

        // Next state assertions
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame(4, $result->nextState->version);
        $this->assertSame(7, $result->nextState->lastSeq);
        $this->assertSame([], $result->nextState->pendingToolCalls);
        $this->assertNull($result->nextState->activeStepId);
        $this->assertNull($result->nextState->currentOperation);

        // Messages: original assistant + synthetic tool
        $this->assertCount(2, $result->nextState->messages);
        $this->assertSame('assistant', $result->nextState->messages[0]->role);
        $this->assertSame('tool', $result->nextState->messages[1]->role);
        $this->assertSame('tc-cat', $result->nextState->messages[1]->toolCallId);
        $this->assertFalse($result->nextState->messages[1]->isError);
        $this->assertSame('bash', $result->nextState->messages[1]->toolName);

        $this->assertSame(
            ['tool_execution_end', 'tool_batch_committed', 'agent_end'],
            array_map(static fn ($event): string => $event->type, $result->events),
        );
        $this->assertSame('cancelled', $result->events[2]->payload['reason']);

        $this->assertSame([], $result->effects);
        $this->assertSame([], $result->postCommitEffects);
    }

    public function testCancellingWithEmptyPendingCallsDoesNotSynthesize(): void
    {
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $assistantMsg = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Okay']],
        );

        $state = RunStateBuilder::running('run-cancel-empty')
            ->withStatus(RunStatus::Cancelling)
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(4)
            ->withPendingToolCalls([])
            ->withActiveStepId('turn-step-1')
            ->withMessages([$assistantMsg])
            ->build();

        $message = ToolCallResultBuilder::success('run-cancel-empty')
            ->withTurnNo(1)
            ->withStepId('turn-step-1')
            ->withIdempotencyKey('tool-result-arriving')
            ->withToolCallId('tc-old')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => 'done']]])
            ->build();

        $result = $handler->handle($message, $state);

        // Discarded stale work is observable only in metrics; no canonical result event.
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_end', $result->events[0]->type);
        $this->assertSame('cancelled', $result->events[0]->payload['reason']);

        // Messages unchanged
        $this->assertCount(1, $result->nextState->messages);
        $this->assertSame('assistant', $result->nextState->messages[0]->role);

        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
    }

    public function testCancellingWithMultiplePendingToolCallsSynthesizesAll(): void
    {
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $assistantMsg = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Checking both']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'tc-read-1', 'name' => 'read', 'arguments' => ['path' => './a.txt'], 'order_index' => 0],
                    ['id' => 'tc-read-2', 'name' => 'read', 'arguments' => ['path' => './b.txt'], 'order_index' => 1],
                ],
            ],
        );

        $state = RunStateBuilder::running('run-cancel-multi')
            ->withStatus(RunStatus::Cancelling)
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(4)
            ->withPendingToolCalls(['tc-read-1' => false, 'tc-read-2' => false])
            ->withActiveStepId('turn-step-1')
            ->withMessages([$assistantMsg])
            ->build();

        $message = ToolCallResultBuilder::success('run-cancel-multi')
            ->withTurnNo(1)
            ->withStepId('turn-step-1')
            ->withIdempotencyKey('tool-result-arriving')
            ->withToolCallId('tc-read-1')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => 'a content']]])
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertSame(
            ['tool_execution_end', 'tool_execution_end', 'tool_batch_committed', 'agent_end'],
            array_map(static fn ($event): string => $event->type, $result->events),
        );
        [$serializer] = AttributeSerializerValidatorTestFactory::create();
        $this->assertSame('tc-read-1', (new ToolExecutionEndPayloadCodec($serializer))->fromEventPayload($result->events[0]->payload)->toolCallId);
        $this->assertTrue((new ToolExecutionEndPayloadCodec($serializer))->fromEventPayload($result->events[1]->payload)->isError);

        // Messages: assistant + 2 synthetic tool
        $this->assertCount(3, $result->nextState->messages);
        $this->assertSame('tool', $result->nextState->messages[1]->role);
        $this->assertSame('tc-read-1', $result->nextState->messages[1]->toolCallId);
        $this->assertSame('tool', $result->nextState->messages[2]->role);
        $this->assertSame('tc-read-2', $result->nextState->messages[2]->toolCallId);

        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame([], $result->nextState->pendingToolCalls);
    }

    public function testCancellingWithPartialCompleteClosesAll(): void
    {
        $collector = new ToolBatchCollector($store = new TestToolBatchStore());
        TestToolBatchRegistration::register($collector, $store,
            runId: 'run-cancel-partial',
            turnNo: 1,
            stepId: 'turn-step-1',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-cancel-partial',
                    turnNo: 1,
                    stepId: 'turn-step-1',
                    attempt: 1,
                    idempotencyKey: 'exec-tc-done',
                    toolCallId: 'tc-done',
                    toolName: 'bash',
                    args: [],
                    orderIndex: 0,
                    mode: 'parallel',
                    maxParallelism: 2,
                ),
                new ExecuteToolCall(
                    runId: 'run-cancel-partial',
                    turnNo: 1,
                    stepId: 'turn-step-1',
                    attempt: 1,
                    idempotencyKey: 'exec-tc-pending',
                    toolCallId: 'tc-pending',
                    toolName: 'read',
                    args: ['path' => './f.txt'],
                    orderIndex: 1,
                    mode: 'parallel',
                    maxParallelism: 2,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $assistantMsg = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Running tools']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'tc-done', 'name' => 'bash', 'arguments' => [], 'order_index' => 0],
                    ['id' => 'tc-pending', 'name' => 'read', 'arguments' => ['path' => './f.txt'], 'order_index' => 1],
                ],
            ],
        );

        // Exact race window: A accepted while Running (received+end, no projection),
        // then cancel transition, then a Cancelling-path result for A (stale) or B.
        $runningState = RunStateBuilder::running('run-cancel-partial')
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(4)
            ->withPendingToolCalls(['tc-done' => false, 'tc-pending' => false])
            ->withActiveStepId('turn-step-1')
            ->withMessages([$assistantMsg])
            ->build();

        $doneResult = ToolCallResultBuilder::success('run-cancel-partial')
            ->withTurnNo(1)
            ->withStepId('turn-step-1')
            ->withIdempotencyKey('tool-result-done')
            ->withToolCallId('tc-done')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'done']]])
            ->build();

        $partial = $handler->handle($doneResult, $runningState);
        $this->assertNotNull($partial->nextState);
        $this->assertSame(RunStatus::Running, $partial->nextState->status);
        $this->assertTrue($partial->nextState->pendingToolCalls['tc-done']);
        $this->assertFalse($partial->nextState->pendingToolCalls['tc-pending']);
        $this->assertCount(1, $partial->nextState->messages, 'Incomplete batch must defer tool message projection');

        $cancellingState = $partial->nextState->with([
            'status' => RunStatus::Cancelling,
            'version' => $partial->nextState->version + 1,
        ]);

        // Cancelling branch entry: stale redelivery of A's result (already true).
        $staleA = ToolCallResultBuilder::success('run-cancel-partial')
            ->withTurnNo(1)
            ->withStepId('turn-step-1')
            ->withIdempotencyKey('tool-result-done-redeliver')
            ->withToolCallId('tc-done')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'done']]])
            ->build();

        $result = $handler->handle($staleA, $cancellingState);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame([], $result->nextState->pendingToolCalls);
        $this->assertCount(3, $result->nextState->messages);
        $this->assertSame('tool', $result->nextState->messages[1]->role);
        $this->assertSame('tc-done', $result->nextState->messages[1]->toolCallId);
        $this->assertSame('tool', $result->nextState->messages[2]->role);
        $this->assertSame('tc-pending', $result->nextState->messages[2]->toolCallId);

        $types = array_map(static fn ($e) => $e->type, $result->events);
        $this->assertContains(RunEventTypeEnum::ToolBatchCommitted->value, $types);
        $batchCommitted = null;
        foreach ($result->events as $event) {
            if (RunEventTypeEnum::ToolBatchCommitted->value === $event->type) {
                $batchCommitted = $event;
            }
        }
        $this->assertNotNull($batchCommitted);
        $this->assertSame(2, $batchCommitted->payload['count'] ?? null);

        $agentEnds = array_values(array_filter(
            $result->events,
            static fn ($e) => RunEventTypeEnum::AgentEnd->value === $e->type,
        ));
        $this->assertCount(1, $agentEnds);
        $this->assertSame('cancelled', $agentEnds[0]->payload['reason'] ?? null);

        $validator = new AgentMessageToolCallSequenceValidator();
        $validator->validate($result->nextState->messages);
        $validator->validate(array_merge(
            $result->nextState->messages,
            [new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'continue']])],
        ));
    }

    public function testCancellingPreserveIncomingProjectsDeferredSiblingMessageOnly(): void
    {
        $collector = new ToolBatchCollector($store = new TestToolBatchStore());
        TestToolBatchRegistration::register($collector, $store,
            runId: 'run-cancel-preserve',
            turnNo: 1,
            stepId: 'turn-step-1',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-cancel-preserve',
                    turnNo: 1,
                    stepId: 'turn-step-1',
                    attempt: 1,
                    idempotencyKey: 'exec-tc-done',
                    toolCallId: 'tc-done',
                    toolName: 'bash',
                    args: [],
                    orderIndex: 0,
                    mode: 'parallel',
                    maxParallelism: 2,
                ),
                new ExecuteToolCall(
                    runId: 'run-cancel-preserve',
                    turnNo: 1,
                    stepId: 'turn-step-1',
                    attempt: 1,
                    idempotencyKey: 'exec-tc-pending',
                    toolCallId: 'tc-pending',
                    toolName: 'bash',
                    args: [],
                    orderIndex: 1,
                    mode: 'parallel',
                    maxParallelism: 2,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $assistantMsg = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Running tools']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'tc-done', 'name' => 'bash', 'arguments' => [], 'order_index' => 0],
                    ['id' => 'tc-pending', 'name' => 'bash', 'arguments' => [], 'order_index' => 1],
                ],
            ],
        );

        $runningState = RunStateBuilder::running('run-cancel-preserve')
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(4)
            ->withPendingToolCalls(['tc-done' => false, 'tc-pending' => false])
            ->withActiveStepId('turn-step-1')
            ->withMessages([$assistantMsg])
            ->build();

        $doneResult = ToolCallResultBuilder::success('run-cancel-preserve')
            ->withTurnNo(1)
            ->withStepId('turn-step-1')
            ->withIdempotencyKey('tool-result-done')
            ->withToolCallId('tc-done')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'done']]])
            ->build();

        $partial = $handler->handle($doneResult, $runningState);
        $this->assertNotNull($partial->nextState);
        $this->assertTrue($partial->nextState->pendingToolCalls['tc-done']);
        $this->assertFalse($partial->nextState->pendingToolCalls['tc-pending']);
        $this->assertCount(1, $partial->nextState->messages);

        $cancellingState = $partial->nextState->with([
            'status' => RunStatus::Cancelling,
            'version' => $partial->nextState->version + 1,
        ]);

        // Exact observed entry: B's fresh cancelled result while Cancelling (preserveIncoming).
        $cancelledB = ToolCallResultBuilder::error('run-cancel-preserve', 'Tool execution cancelled by user.')
            ->withTurnNo(1)
            ->withStepId('turn-step-1')
            ->withIdempotencyKey('tool-result-pending-cancel')
            ->withToolCallId('tc-pending')
            ->withOrderIndex(1)
            ->withResult([
                'tool_name' => 'bash',
                'content' => [['type' => 'text', 'text' => 'Tool execution cancelled by user.']],
            ])
            ->withError(['type' => 'cancelled', 'message' => 'Tool execution cancelled by user.'])
            ->build();

        $result = $handler->handle($cancelledB, $cancellingState);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame([], $result->nextState->pendingToolCalls);
        $this->assertCount(3, $result->nextState->messages);
        $this->assertSame('tc-done', $result->nextState->messages[1]->toolCallId);
        $this->assertSame('tc-pending', $result->nextState->messages[2]->toolCallId);

        $batchCommitted = array_values(array_filter(
            $result->events,
            static fn ($event): bool => RunEventTypeEnum::ToolBatchCommitted->value === $event->type,
        ));
        $ends = array_values(array_filter(
            $result->events,
            static fn ($event): bool => RunEventTypeEnum::ToolExecutionEnd->value === $event->type,
        ));
        $this->assertCount(1, $batchCommitted);
        $this->assertSame(2, $batchCommitted[0]->payload['count'] ?? null);
        $this->assertCount(1, $ends);
        [$serializer] = AttributeSerializerValidatorTestFactory::create();
        $this->assertSame('tc-pending', (new ToolExecutionEndPayloadCodec($serializer))->fromEventPayload($ends[0]->payload)->toolCallId);

        $agentEnds = array_values(array_filter(
            $result->events,
            static fn ($e) => RunEventTypeEnum::AgentEnd->value === $e->type,
        ));
        $this->assertCount(1, $agentEnds);
        $this->assertSame('cancelled', $agentEnds[0]->payload['reason'] ?? null);

        $validator = new AgentMessageToolCallSequenceValidator();
        $validator->validate($result->nextState->messages);
    }

    public function testCancellingSyntheticMessagesPassValidator(): void
    {
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $assistantMsg = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Let me check']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'tc-cat', 'name' => 'bash', 'arguments' => [], 'order_index' => 0],
                ],
            ],
        );

        $state = RunStateBuilder::running('run-cancel-valid')
            ->withStatus(RunStatus::Cancelling)
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(4)
            ->withPendingToolCalls(['tc-cat' => false])
            ->withActiveStepId('turn-step-1')
            ->withMessages([$assistantMsg])
            ->build();

        $message = ToolCallResultBuilder::success('run-cancel-valid')
            ->withTurnNo(1)
            ->withStepId('turn-step-1')
            ->withIdempotencyKey('tool-result-arriving')
            ->withToolCallId('tc-cat')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'results']]])
            ->build();

        $result = $handler->handle($message, $state);

        // Append a continue message to simulate the user continuing after cancel
        $continueMsg = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Continue']],
        );
        $messagesAfterCancel = array_merge($result->nextState->messages, [$continueMsg]);

        // Validator should NOT throw: assistant(tool_calls) → tool → user
        $validator = new AgentMessageToolCallSequenceValidator();
        $validator->validate($messagesAfterCancel);

        $this->assertCount(3, $messagesAfterCancel,
            'Cancellation + Continue produces valid assistant()->tool()->user() sequence');
    }

    public function testCancellingPreservesRichIncomingToolCallResult(): void
    {
        $richMessage = implode('
', [
            'Subagent scout cancelled by parent run.',
            'Artifact: agent_41d4ca5566368a6b',
            'Status: cancelled',
            'Use agent_retrieve (metadata/events/history) for partial child details.',
        ]);

        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $assistantMsg = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Delegating']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'tc-sub', 'name' => 'subagent', 'arguments' => ['agent' => 'scout', 'task' => 'sleep'], 'order_index' => 0],
                ],
            ],
        );

        $state = RunStateBuilder::running('run-cancel-subagent')
            ->withStatus(RunStatus::Cancelling)
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(4)
            ->withPendingToolCalls(['tc-sub' => false])
            ->withActiveStepId('turn-step-1')
            ->withMessages([$assistantMsg])
            ->build();

        $message = ToolCallResultBuilder::create('run-cancel-subagent')
            ->withTurnNo(1)
            ->withStepId('turn-step-1')
            ->withIdempotencyKey('tool-result-arriving')
            ->withToolCallId('tc-sub')
            ->withOrderIndex(0)
            ->withIsError(true)
            ->withResult([
                'tool_name' => 'subagent',
                'content' => [['type' => 'text', 'text' => $richMessage]],
            ])
            ->withError(['type' => 'cancelled', 'message' => $richMessage])
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertSame('tool_execution_end', $result->events[0]->type);
        [$serializer] = AttributeSerializerValidatorTestFactory::create();
        $typed = (new ToolExecutionEndPayloadCodec($serializer))->fromEventPayload($result->events[0]->payload);
        $this->assertSame($richMessage, $typed->error['message']);
        $this->assertSame('cancelled', $typed->error['type']);
        $this->assertSame('tool', $result->nextState->messages[1]->role);
        $this->assertStringContainsString('Artifact: agent_41d4ca5566368a6b', $result->nextState->messages[1]->content[0]['text'] ?? '');
    }

    public function testFinalizedRedeliveryAfterCanonicalCommitIsIdempotentNoOp(): void
    {
        $store = $this->createSessionToolBatchStore();
        $collector = new ToolBatchCollector($store, 4);

        TestToolBatchRegistration::register($collector, $store, 'run-redeliver-post', 1, 'step-1', [
            new ExecuteToolCall(
                runId: 'run-redeliver-post',
                turnNo: 1,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'exec-call-1',
                toolCallId: 'call-1',
                toolName: 'read',
                args: [],
                orderIndex: 0,
                maxParallelism: 1,
            ),
        ]);

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $message = ToolCallResultBuilder::success('run-redeliver-post')
            ->withTurnNo(1)
            ->withStepId('step-1')
            ->withIdempotencyKey('result-call-1')
            ->withToolCallId('call-1')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'read',
                'content' => [['type' => 'text', 'text' => 'committed-body']],
            ])
            ->build();

        $pendingState = RunStateBuilder::running('run-redeliver-post')
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(6)
            ->withPendingToolCalls(['call-1' => false])
            ->withActiveStepId('step-1')
            ->build();

        $first = $handler->handle($message, $pendingState);
        $this->assertNotNull($first->nextState);
        $this->assertSame([], $first->nextState->pendingToolCalls);
        $this->assertCount(1, $first->nextState->messages);
        $this->assertSame('tool', $first->nextState->messages[0]->role);
        $this->assertSame('call-1', $first->nextState->messages[0]->toolCallId);

        $committedState = $first->nextState;
        $redelivery = $handler->handle($message, $committedState);

        $this->assertNull($redelivery->nextState);
        $this->assertSame([], $redelivery->events);
        $this->assertSame([], $redelivery->postCommitActions);
        $this->assertSame([], $redelivery->postCommitEffects);
    }

    public function testFinalizedRedeliveryBeforeCanonicalCommitRecoversOnce(): void
    {
        $store = $this->createSessionToolBatchStore();
        $collector = new ToolBatchCollector($store, 4);

        TestToolBatchRegistration::register($collector, $store, 'run-redeliver-pre', 1, 'step-1', [
            new ExecuteToolCall(
                runId: 'run-redeliver-pre',
                turnNo: 1,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'exec-call-pre',
                toolCallId: 'call-pre',
                toolName: 'read',
                args: [],
                orderIndex: 0,
                maxParallelism: 1,
            ),
        ]);

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $message = ToolCallResultBuilder::success('run-redeliver-pre')
            ->withTurnNo(1)
            ->withStepId('step-1')
            ->withIdempotencyKey('result-call-pre')
            ->withToolCallId('call-pre')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'read',
                'content' => [['type' => 'text', 'text' => 'recover-body']],
            ])
            ->build();

        $pendingState = RunStateBuilder::running('run-redeliver-pre')
            ->withVersion(2)
            ->withTurnNo(1)
            ->withLastSeq(4)
            ->withPendingToolCalls(['call-pre' => false])
            ->withActiveStepId('step-1')
            ->build();

        $first = $handler->handle($message, $pendingState);
        $this->assertNotNull($first->nextState);
        $this->assertSame([], $first->nextState->pendingToolCalls);

        $recoveryState = RunStateBuilder::running('run-redeliver-pre')
            ->withVersion(2)
            ->withTurnNo(1)
            ->withLastSeq(4)
            ->withPendingToolCalls(['call-pre' => false])
            ->withActiveStepId('step-1')
            ->build();

        $recovery = $handler->handle($message, $recoveryState);
        $this->assertNotNull($recovery->nextState);
        $this->assertSame([], $recovery->nextState->pendingToolCalls);
        $this->assertCount(1, $recovery->nextState->messages);
        $eventTypes = array_map(static fn ($e) => $e->type, $recovery->events);
        $this->assertContains('tool_batch_committed', $eventTypes);
    }

    public function testCancellingSyntheticUnresolvedToolExecutionEndHasResultAndCancellationMetadata(): void
    {
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(new TestToolBatchStore()),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $assistantMsg = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Running']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'tc-only', 'name' => 'bash', 'arguments' => [], 'order_index' => 0],
                ],
            ],
        );

        $state = RunStateBuilder::running('run-cancel-synth-meta')
            ->withStatus(RunStatus::Cancelling)
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(2)
            ->withPendingToolCalls(['tc-only' => false])
            ->withActiveStepId('turn-step-1')
            ->withMessages([$assistantMsg])
            ->build();

        // Stale unrelated result triggers synthesis for unresolved pending only.
        $message = ToolCallResultBuilder::success('run-cancel-synth-meta')
            ->withTurnNo(1)
            ->withStepId('turn-step-1')
            ->withIdempotencyKey('stale')
            ->withToolCallId('tc-other')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => 'ignored']]])
            ->build();

        $result = $handler->handle($message, $state);

        $toolEnd = null;
        foreach ($result->events as $event) {
            if ('tool_execution_end' === $event->type) {
                $toolEnd = $event;
                break;
            }
        }

        $this->assertNotNull($toolEnd);
        [$serializer] = AttributeSerializerValidatorTestFactory::create();
        $typed = (new ToolExecutionEndPayloadCodec($serializer))->fromEventPayload($toolEnd->payload);
        $this->assertSame('Tool execution cancelled by user.', $typed->error['message']);
        $this->assertSame('cancelled', $typed->error['type']);
        $this->assertSame('Tool execution cancelled by user.', $result->nextState->messages[1]->content[0]['text'] ?? null);
    }

    public function testMixedForkCancellationPreservesCompletedSiblingResult(): void
    {
        $store = $this->createSessionToolBatchStore();
        $collector = new ToolBatchCollector($store);
        $runId = 'run-cancel-fork-retention';
        $fork = new ExecuteToolCall(
            runId: $runId, turnNo: 1, stepId: 'tools', attempt: 1,
            idempotencyKey: 'fork-execution', toolCallId: 'fork-call', toolName: 'fork', args: [],
            orderIndex: 1, mode: 'parallel', maxParallelism: 2,
            launchContext: new \Ineersa\AgentCore\Domain\Tool\ToolLaunchInputReferenceDTO(
                kind: 'fork', producingRunId: $runId, producingTurnNo: 1, producingModel: 'test-model',
                producingStepId: 'tools', toolCallId: 'fork-call', sha256: str_repeat('a', 64), bytes: 100,
            ),
        );
        TestToolBatchRegistration::register($collector, $store, $runId, 1, 'tools', [
            new ExecuteToolCall(
                runId: $runId, turnNo: 1, stepId: 'tools', attempt: 1,
                idempotencyKey: 'read-execution', toolCallId: 'read-call', toolName: 'read',
                args: ['path' => 'file.txt'], orderIndex: 0, mode: 'parallel', maxParallelism: 2,
            ),
            $fork,
        ]);
        $read = ToolCallResultBuilder::success($runId)->withTurnNo(1)->withStepId('tools')
            ->withToolCallId('read-call')->withOrderIndex(0)
            ->withResult(['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => 'saved read result']]])->build();
        $this->assertFalse(\Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $store, $read)->complete);
        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector, eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(), messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );
        $state = RunStateBuilder::running($runId)->withTurnNo(1)->withActiveStepId('tools')
            ->withPendingToolCalls(['read-call' => true, 'fork-call' => false])
            ->withMessages([new AgentMessage(role: 'assistant', content: [], metadata: ['tool_calls' => [
                ['id' => 'read-call', 'name' => 'read', 'arguments' => ['path' => 'file.txt'], 'order_index' => 0],
                ['id' => 'fork-call', 'name' => 'fork', 'arguments' => [], 'order_index' => 1],
            ]])])->build()->with(['status' => RunStatus::Cancelling]);
        $result = $handler->handle($read, $state);
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame('saved read result', $result->nextState->messages[1]->content[0]['text']);
        $this->assertNotNull($fork->launchContext);
        $this->assertSame('fork-call', $fork->launchContext->toolCallId);
    }

    public function testDetachedTerminalAcceptanceRoutesBeforeStaleStepRejection(): void
    {
        $store = new TestToolBatchStore();
        $deferred = new InMemoryDeferredToolCompletionRepository();
        $collector = new ToolBatchCollector($store, 4, $deferred);
        $call = new ExecuteToolCall(
            runId: 'run-detached',
            turnNo: 1,
            stepId: 'tools-old',
            attempt: 1,
            idempotencyKey: 'call-detached-key',
            toolCallId: 'call-detached',
            toolName: 'bash',
            args: ['command' => 'true'],
            orderIndex: 0,
            mode: 'sequential',
        );
        TestToolBatchRegistration::register($collector, $store, 'run-detached', 1, 'tools-old', [$call]);

        $base = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440700',
            runId: 'run-detached',
            turnNo: 1,
            stepId: 'tools-old',
            attempt: 1,
            idempotencyKey: 'call-detached-key',
            toolCallId: 'call-detached',
            toolName: 'bash',
            arguments: ['command' => 'true'],
            orderIndex: 0,
            mode: 'sequential',
            invocationId: 'inv-detached',
            controllerRunId: 'run-detached',
            executionOwnerKind: 'bash',
            executionOwnerReference: new ToolInvocationOwnerReference('bash', 'record-detached', 9, '/tmp/detached.log'),
            originalExecutionMode: 'sequential',
        );
        $correlation = $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $base->deferredId,
            runId: $base->runId,
            turnNo: $base->turnNo,
            stepId: $base->stepId,
            attempt: $base->attempt,
            idempotencyKey: $base->idempotencyKey,
            toolCallId: $base->toolCallId,
            toolName: $base->toolName,
            arguments: $base->arguments,
            orderIndex: $base->orderIndex,
            mode: $base->mode,
            invocationId: $base->invocationId,
            controllerRunId: $base->controllerRunId,
            executionOwnerKind: $base->executionOwnerKind,
            executionOwnerReference: $base->executionOwnerReference,
            originalExecutionMode: $base->originalExecutionMode,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($base),
        ));
        $deferred->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        ));

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $message = ToolCallResultBuilder::success('run-detached')
            ->withTurnNo(1)
            ->withStepId('tools-old')
            ->withIdempotencyKey('call-detached-key')
            ->withToolCallId('call-detached')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'done']]])
            ->build();

        // Current step advanced after background acknowledgment.
        $state = RunStateBuilder::running('run-detached')
            ->withTurnNo(2)
            ->withActiveStepId('tools-new')
            ->withLastSeq(10)
            ->withPendingToolCalls([])
            ->build();

        $result = $handler->handle($message, $state);
        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ToolExecutionEnd->value, $result->events[0]->type);
        $this->assertSame(1, $result->events[0]->turnNo);
        $this->assertSame('inv-detached', $result->events[0]->payload['accepted_completion']['invocation_id'] ?? null);
        $this->assertTrue($result->events[0]->payload['accepted_completion']['detached'] ?? false);
        $this->assertCount(1, $result->postCommitActions);
        $this->assertInstanceOf(RecordToolInvocationLifecycleDTO::class, $result->postCommitActions[0]);

        $deferred->applyLifecycleProjection($result->postCommitActions[0]);
        $this->assertSame('completed', $deferred->status($correlation->deferredId));

        // Duplicate late delivery is idempotent and does not reopen the batch.
        $duplicate = $handler->handle($message, $result->nextState);
        $this->assertNull($duplicate->nextState);
        $this->assertSame([], $duplicate->events);
        $this->assertSame('completed', $deferred->status($correlation->deferredId));

        $batch = $store->load('run-detached', 1, 'tools-old');
        $this->assertNotNull($batch);
        $this->assertArrayNotHasKey('call-detached', $batch->results);
    }

    public function testDetachedTerminalAcceptanceSettlesDuringCancellationWithoutRevival(): void
    {
        $store = new TestToolBatchStore();
        $deferred = new InMemoryDeferredToolCompletionRepository();
        $collector = new ToolBatchCollector($store, 4, $deferred);
        $call = new ExecuteToolCall(
            runId: 'run-detach-cancel',
            turnNo: 1,
            stepId: 'tools-old',
            attempt: 1,
            idempotencyKey: 'call-detach-cancel-key',
            toolCallId: 'call-detach-cancel',
            toolName: 'bash',
            args: ['command' => 'true'],
            orderIndex: 0,
            mode: 'sequential',
        );
        TestToolBatchRegistration::register($collector, $store, 'run-detach-cancel', 1, 'tools-old', [$call]);
        $correlation = $this->registerBackgroundAcknowledged(
            $deferred,
            'run-detach-cancel',
            'tools-old',
            'call-detach-cancel',
            'call-detach-cancel-key',
            'inv-detach-cancel',
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );
        $message = ToolCallResultBuilder::success('run-detach-cancel')
            ->withTurnNo(1)
            ->withStepId('tools-old')
            ->withIdempotencyKey('call-detach-cancel-key')
            ->withToolCallId('call-detach-cancel')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'done']]])
            ->build();
        $state = RunStateBuilder::running('run-detach-cancel')
            ->withTurnNo(2)
            ->withActiveStepId('tools-new')
            ->withLastSeq(10)
            ->withPendingToolCalls([])
            ->withStatus(RunStatus::Cancelling)
            ->build();

        $result = $handler->handle($message, $state);
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame(
            [RunEventTypeEnum::ToolExecutionEnd->value, RunEventTypeEnum::AgentEnd->value],
            array_map(static fn ($event): string => $event->type, $result->events),
        );
        $this->assertTrue($result->events[0]->payload['accepted_completion']['detached'] ?? false);
        $this->assertContainsOnlyInstancesOf(RecordToolInvocationLifecycleDTO::class, array_values(array_filter(
            $result->postCommitActions,
            static fn ($action): bool => $action instanceof RecordToolInvocationLifecycleDTO,
        )));
        foreach ($result->postCommitActions as $action) {
            if ($action instanceof RecordToolInvocationLifecycleDTO) {
                $deferred->applyLifecycleProjection($action);
            }
        }
        $this->assertSame('completed', $deferred->status($correlation->deferredId));
        $this->assertSame([], $result->nextState->pendingToolCalls);
        $this->assertCount(0, $result->nextState->messages);
    }

    public function testAttachedDeferredPreserveIncomingCancellationSettlesAcceptance(): void
    {
        $store = new TestToolBatchStore();
        $deferred = new InMemoryDeferredToolCompletionRepository();
        $collector = new ToolBatchCollector($store, 4, $deferred);
        $call = new ExecuteToolCall(
            runId: 'run-attach-cancel',
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'call-attach-cancel-key',
            toolCallId: 'call-attach-cancel',
            toolName: 'subagent',
            args: [],
            orderIndex: 0,
            mode: 'sequential',
        );
        TestToolBatchRegistration::register($collector, $store, 'run-attach-cancel', 1, 'tools', [$call]);
        $base = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440801',
            runId: 'run-attach-cancel',
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'call-attach-cancel-key',
            toolCallId: 'call-attach-cancel',
            toolName: 'subagent',
            arguments: [],
            orderIndex: 0,
            mode: 'sequential',
            invocationId: 'inv-attach-cancel',
            controllerRunId: 'run-attach-cancel',
            executionOwnerKind: 'subagent',
            executionOwnerReference: new ToolInvocationOwnerReference('subagent', 'record-attach-cancel'),
            originalExecutionMode: 'sequential',
        );
        $correlation = $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $base->deferredId,
            runId: $base->runId,
            turnNo: $base->turnNo,
            stepId: $base->stepId,
            attempt: $base->attempt,
            idempotencyKey: $base->idempotencyKey,
            toolCallId: $base->toolCallId,
            toolName: $base->toolName,
            arguments: $base->arguments,
            orderIndex: $base->orderIndex,
            mode: $base->mode,
            invocationId: $base->invocationId,
            controllerRunId: $base->controllerRunId,
            executionOwnerKind: $base->executionOwnerKind,
            executionOwnerReference: $base->executionOwnerReference,
            originalExecutionMode: $base->originalExecutionMode,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($base),
        ));

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );
        $message = ToolCallResultBuilder::success('run-attach-cancel')
            ->withTurnNo(1)
            ->withStepId('tools')
            ->withIdempotencyKey('call-attach-cancel-key')
            ->withToolCallId('call-attach-cancel')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'subagent', 'content' => [['type' => 'text', 'text' => 'child done']]])
            ->build();
        $state = RunStateBuilder::running('run-attach-cancel')
            ->withTurnNo(1)
            ->withActiveStepId('tools')
            ->withLastSeq(4)
            ->withPendingToolCalls(['call-attach-cancel' => false])
            ->withStatus(RunStatus::Cancelling)
            ->withMessages([new AgentMessage(role: 'assistant', content: [], metadata: ['tool_calls' => [
                ['id' => 'call-attach-cancel', 'name' => 'subagent', 'arguments' => [], 'order_index' => 0],
            ]])])
            ->build();

        $result = $handler->handle($message, $state);
        $this->assertSame(RunStatus::Cancelled, $result->nextState?->status);
        $lifecycleActions = array_values(array_filter(
            $result->postCommitActions,
            static fn ($action): bool => $action instanceof RecordToolInvocationLifecycleDTO,
        ));
        $this->assertCount(1, $lifecycleActions);
        $deferred->applyLifecycleProjection($lifecycleActions[0]);
        $this->assertSame('completed', $deferred->status($correlation->deferredId));
        $accepted = $deferred->findByDeferredId($correlation->deferredId);
        $this->assertSame(ToolInvocationCallerDispositionEnum::ForegroundResultAccepted, $accepted?->callerDisposition);
        $this->assertTrue(false === $result->events[0]->payload['accepted_completion']['detached']);
        $this->assertSame('child done', $result->nextState?->messages[1]->content[0]['text'] ?? null);
    }

    public function testDetachedAcceptedReceiptDoesNotPolluteLaterForegroundBatchReplay(): void
    {
        $store = new TestToolBatchStore();
        $deferred = new InMemoryDeferredToolCompletionRepository();
        $collector = new ToolBatchCollector($store, 4, $deferred);
        $detachedCall = new ExecuteToolCall(
            runId: 'run-detach-replay',
            turnNo: 1,
            stepId: 'tools-old',
            attempt: 1,
            idempotencyKey: 'call-detach-replay-key',
            toolCallId: 'call-detach-replay',
            toolName: 'bash',
            args: ['command' => 'true'],
            orderIndex: 0,
            mode: 'sequential',
        );
        TestToolBatchRegistration::register($collector, $store, 'run-detach-replay', 1, 'tools-old', [$detachedCall]);
        $this->registerBackgroundAcknowledged(
            $deferred,
            'run-detach-replay',
            'tools-old',
            'call-detach-replay',
            'call-detach-replay-key',
            'inv-detach-replay',
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );
        $ackState = RunStateBuilder::running('run-detach-replay')
            ->withTurnNo(1)
            ->withActiveStepId('tools-old')
            ->withLastSeq(2)
            ->withPendingToolCalls([])
            ->withMessages([
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'ack']], metadata: []),
                new AgentMessage(role: 'tool', content: [['type' => 'text', 'text' => 'running_in_background']], metadata: [
                    'tool_call_id' => 'call-detach-replay',
                    'order_index' => 0,
                ]),
            ])
            ->build();
        $detachedResult = ToolCallResultBuilder::success('run-detach-replay')
            ->withTurnNo(1)
            ->withStepId('tools-old')
            ->withIdempotencyKey('call-detach-replay-key')
            ->withToolCallId('call-detach-replay')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'background done']]])
            ->build();
        $accepted = $handler->handle($detachedResult, $ackState->with([
            'turnNo' => 2,
            'activeStepId' => 'tools-new',
            'lastSeq' => 5,
            'pendingToolCalls' => [],
        ]));
        $this->assertCount(1, $accepted->events);
        $this->assertTrue($accepted->events[0]->payload['accepted_completion']['detached'] ?? false);

        $laterCall = new ExecuteToolCall(
            runId: 'run-detach-replay',
            turnNo: 2,
            stepId: 'tools-new',
            attempt: 1,
            idempotencyKey: 'call-later-key',
            toolCallId: 'call-later',
            toolName: 'read',
            args: ['path' => 'a.txt'],
            orderIndex: 0,
            mode: 'parallel',
        );
        TestToolBatchRegistration::register($collector, $store, 'run-detach-replay', 2, 'tools-new', [$laterCall]);
        $laterState = ($accepted->nextState ?? $ackState)->with([
            'turnNo' => 2,
            'activeStepId' => 'tools-new',
            'pendingToolCalls' => ['call-later' => false],
            'messages' => [
                ...($accepted->nextState ?? $ackState)->messages,
                new AgentMessage(role: 'assistant', content: [], metadata: ['tool_calls' => [
                    ['id' => 'call-later', 'name' => 'read', 'arguments' => ['path' => 'a.txt'], 'order_index' => 0],
                ]]),
            ],
        ]);
        $laterResult = ToolCallResultBuilder::success('run-detach-replay')
            ->withTurnNo(2)
            ->withStepId('tools-new')
            ->withIdempotencyKey('call-later-key')
            ->withToolCallId('call-later')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => 'file']]])
            ->build();
        $later = $handler->handle($laterResult, $laterState);
        $this->assertNotNull($later->nextState);
        $toolMessages = array_values(array_filter(
            $later->nextState->messages,
            static fn (AgentMessage $message): bool => 'tool' === $message->role,
        ));
        $this->assertSame(
            ['running_in_background', 'file'],
            array_map(static fn (AgentMessage $message): string => $message->content[0]['text'] ?? '', $toolMessages),
        );

        $events = [
            new RunEvent('run-detach-replay', 1, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            new RunEvent('run-detach-replay', 2, 1, RunEventTypeEnum::LlmStepCompleted->value, [
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'ack']], 'tool_calls' => [
                    ['id' => 'call-detach-replay', 'name' => 'bash', 'arguments' => ['command' => 'true'], 'order_index' => 0],
                ]],
            ]),
            new RunEvent('run-detach-replay', 3, 1, RunEventTypeEnum::ToolBatchCommitted->value, [
                'count' => 1,
                'turn_no' => 1,
                'step_id' => 'tools-old',
            ]),
            $accepted->events[0],
            new RunEvent('run-detach-replay', 5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            new RunEvent('run-detach-replay', 6, 2, RunEventTypeEnum::LlmStepCompleted->value, [
                'assistant_message' => ['role' => 'assistant', 'content' => [], 'tool_calls' => [
                    ['id' => 'call-later', 'name' => 'read', 'arguments' => ['path' => 'a.txt'], 'order_index' => 0],
                ]],
            ]),
            $later->events[0],
            $later->events[1],
        ];
        // Fix sequences for reducer input.
        $sequenced = [];
        foreach ($events as $i => $event) {
            $sequenced[] = new RunEvent(
                $event->runId,
                $i + 1,
                $event->turnNo,
                $event->type,
                $event->payload,
            );
        }
        $replayed = (new RunStateReducer(
            AttributeSerializerValidatorTestFactory::denormalizer(),
            new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()),
        ))->replay(RunState::queued('run-detach-replay'), $sequenced);
        $replayToolTexts = array_values(array_map(
            static fn (AgentMessage $message): string => $message->content[0]['text'] ?? '',
            array_filter($replayed->messages, static fn (AgentMessage $message): bool => 'tool' === $message->role),
        ));
        $this->assertSame(['file'], $replayToolTexts);
        $this->assertSame([], $replayed->pendingToolCalls);
    }

    private function registerBackgroundAcknowledged(
        InMemoryDeferredToolCompletionRepository $deferred,
        string $runId,
        string $stepId,
        string $toolCallId,
        string $idempotencyKey,
        string $invocationId,
    ): DeferredToolCompletionCorrelation {
        $base = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-44665544'.substr(hash('sha256', $toolCallId), 0, 4),
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: $idempotencyKey,
            toolCallId: $toolCallId,
            toolName: 'bash',
            arguments: ['command' => 'true'],
            orderIndex: 0,
            mode: 'sequential',
            invocationId: $invocationId,
            controllerRunId: $runId,
            executionOwnerKind: 'bash',
            executionOwnerReference: new ToolInvocationOwnerReference('bash', 'record-'.$toolCallId, 9, '/tmp/'.$toolCallId.'.log'),
            originalExecutionMode: 'sequential',
        );
        $correlation = $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $base->deferredId,
            runId: $base->runId,
            turnNo: $base->turnNo,
            stepId: $base->stepId,
            attempt: $base->attempt,
            idempotencyKey: $base->idempotencyKey,
            toolCallId: $base->toolCallId,
            toolName: $base->toolName,
            arguments: $base->arguments,
            orderIndex: $base->orderIndex,
            mode: $base->mode,
            invocationId: $base->invocationId,
            controllerRunId: $base->controllerRunId,
            executionOwnerKind: $base->executionOwnerKind,
            executionOwnerReference: $base->executionOwnerReference,
            originalExecutionMode: $base->originalExecutionMode,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($base),
        ));
        $deferred->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        ));

        $updated = $deferred->findByDeferredId($correlation->deferredId);
        $this->assertNotNull($updated);

        return $updated;
    }

    private function createSessionToolBatchStore(): \Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface
    {
        return new TestToolBatchStore();
    }
}
