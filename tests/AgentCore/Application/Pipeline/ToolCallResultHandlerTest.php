<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\ToolBatchIdentity;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallResultBuilder;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionToolBatchStore;
use Ineersa\CodingAgent\Tests\Session\Support\ParentSessionToolBatchRunStoragePaths;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

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

    public function testHandleAcceptedPendingResultReturnsPostCommitEffectsForNextToolCall(): void
    {
        $collector = new ToolBatchCollector();

        $collector->registerExpectedBatch(
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
        $this->assertSame([], $result->postCommit);
    }

    public function testUntrackedCurrentTokenRedeliveryIsIdempotentNoOp(): void
    {
        $metrics = new RunMetrics();
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            metrics: $metrics,
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
            $this->assertSame([], $result->postCommit);
        }
        $this->assertSame(2, $metrics->snapshot()['stale_result_count']);
    }

    public function testCancellingWithPendingToolCallsSynthesizesToolMessages(): void
    {
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
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
            toolBatchCollector: new ToolBatchCollector(),
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
            toolBatchCollector: new ToolBatchCollector(),
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
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch(
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
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch(
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
            toolBatchCollector: new ToolBatchCollector(),
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
            toolBatchCollector: new ToolBatchCollector(),
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
        $collector = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);

        $collector->registerExpectedBatch('run-redeliver-post', 1, 'step-1', [
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
        $this->assertSame([], $redelivery->postCommit);
        $this->assertSame([], $redelivery->postCommitEffects);
    }

    public function testFinalizedRedeliveryBeforeCanonicalCommitRecoversOnce(): void
    {
        $store = $this->createSessionToolBatchStore();
        $collector = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);

        $collector->registerExpectedBatch('run-redeliver-pre', 1, 'step-1', [
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
            toolBatchCollector: new ToolBatchCollector(),
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

    private function createSessionToolBatchStore(): SessionToolBatchStore
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->toolBatchProjectDir,
        );
        $hatfield = new HatfieldSessionStore($appConfig, $entityManager, new \Symfony\Component\EventDispatcher\EventDispatcher());

        [$serializer, $validator] = AttributeSerializerValidatorTestFactory::create();

        return new SessionToolBatchStore(
            new ParentSessionToolBatchRunStoragePaths($hatfield),
            new LockFactory(new FlockStore()),
            new NullLogger(),
            $serializer,
            $validator,
        );
    }
}
