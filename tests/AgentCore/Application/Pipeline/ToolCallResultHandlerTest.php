<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallResultBuilder;
use PHPUnit\Framework\TestCase;

final class ToolCallResultHandlerTest extends TestCase
{
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
            ->build();

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
        $this->assertSame(8, $result->nextState->lastSeq);
        $this->assertSame([
            'tool-a' => true,
            'tool-b' => false,
        ], $result->nextState->pendingToolCalls);

        $this->assertCount(2, $result->events);
        $this->assertSame('tool_call_result_received', $result->events[0]->type);
        $this->assertSame('tool_execution_end', $result->events[1]->type);

        $this->assertArrayHasKey('result', $result->events[1]->payload);
        $this->assertSame('A', $result->events[1]->payload['result'],
            'ToolExecutionEnd must carry the extracted result text, not fall back to "alpha completed"');

        $this->assertSame([], $result->effects);
        $this->assertCount(1, $result->postCommitEffects);
        $this->assertInstanceOf(ExecuteToolCall::class, $result->postCommitEffects[0]);
        $this->assertSame('tool-b', $result->postCommitEffects[0]->toolCallId);
        $this->assertSame([], $result->postCommit);
        $this->assertTrue($result->markHandled);
    }

    public function testExtractResultTextSinglePart(): void
    {
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch(
            runId: 'run-extract-single',
            turnNo: 1,
            stepId: 'turn-1-step',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-extract-single',
                    turnNo: 1,
                    stepId: 'turn-1-step',
                    attempt: 1,
                    idempotencyKey: 'exec-solo',
                    toolCallId: 'tool-solo',
                    toolName: 'read',
                    args: ['path' => './test.txt'],
                    orderIndex: 0,
                    maxParallelism: 1,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
        );

        $state = RunStateBuilder::running('run-extract-single')
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(2)
            ->withPendingToolCalls(['tool-solo' => false])
            ->withActiveStepId('turn-1-step')
            ->build();

        $message = ToolCallResultBuilder::success('run-extract-single')
            ->withTurnNo(1)
            ->withStepId('turn-1-step')
            ->withIdempotencyKey('tool-result-solo')
            ->withToolCallId('tool-solo')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'read',
                'content' => [['type' => 'text', 'text' => 'FILE_CONTENT_SENTINEL']],
            ])
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertSame('tool_execution_end', $result->events[1]->type);
        $this->assertSame('FILE_CONTENT_SENTINEL', $result->events[1]->payload['result']);
    }

    public function testExtractResultTextMultipleParts(): void
    {
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch(
            runId: 'run-extract-multi',
            turnNo: 1,
            stepId: 'turn-1-multi',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-extract-multi',
                    turnNo: 1,
                    stepId: 'turn-1-multi',
                    attempt: 1,
                    idempotencyKey: 'exec-multi',
                    toolCallId: 'tool-multi',
                    toolName: 'bash',
                    args: ['command' => 'echo one; echo two'],
                    orderIndex: 0,
                    maxParallelism: 1,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
        );

        $state = RunStateBuilder::running('run-extract-multi')
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(2)
            ->withPendingToolCalls(['tool-multi' => false])
            ->withActiveStepId('turn-1-multi')
            ->build();

        $message = ToolCallResultBuilder::success('run-extract-multi')
            ->withTurnNo(1)
            ->withStepId('turn-1-multi')
            ->withIdempotencyKey('tool-result-multi')
            ->withToolCallId('tool-multi')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'bash',
                'content' => [
                    ['type' => 'text', 'text' => 'line one'],
                    ['type' => 'text', 'text' => 'line two'],
                ],
            ])
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertSame('tool_execution_end', $result->events[1]->type);
        $this->assertSame("line one\nline two", $result->events[1]->payload['result']);
    }

    public function testExtractResultTextNoTextPartsReturnsEmpty(): void
    {
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch(
            runId: 'run-extract-empty',
            turnNo: 1,
            stepId: 'turn-1-empty',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-extract-empty',
                    turnNo: 1,
                    stepId: 'turn-1-empty',
                    attempt: 1,
                    idempotencyKey: 'exec-empty',
                    toolCallId: 'tool-empty',
                    toolName: 'noop',
                    args: [],
                    orderIndex: 0,
                    maxParallelism: 1,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
        );

        $state = RunStateBuilder::running('run-extract-empty')
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(2)
            ->withPendingToolCalls(['tool-empty' => false])
            ->withActiveStepId('turn-1-empty')
            ->build();

        // Content contains only non-text parts (e.g. image_ref).
        $message = ToolCallResultBuilder::success('run-extract-empty')
            ->withTurnNo(1)
            ->withStepId('turn-1-empty')
            ->withIdempotencyKey('tool-result-empty')
            ->withToolCallId('tool-empty')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'noop',
                'content' => [
                    ['type' => 'image_ref', 'image_ref' => 'data:...'],
                ],
            ])
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertSame('tool_execution_end', $result->events[1]->type);
        $this->assertArrayHasKey('result', $result->events[1]->payload);
        $this->assertSame('', $result->events[1]->payload['result'],
            'Non-text content parts should produce empty result, triggering the "completed" fallback');
    }

    public function testExtractResultTextMalformedContent(): void
    {
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch(
            runId: 'run-extract-malformed',
            turnNo: 1,
            stepId: 'turn-1-malformed',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-extract-malformed',
                    turnNo: 1,
                    stepId: 'turn-1-malformed',
                    attempt: 1,
                    idempotencyKey: 'exec-malformed',
                    toolCallId: 'tool-malformed',
                    toolName: 'buggy',
                    args: [],
                    orderIndex: 0,
                    maxParallelism: 1,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
        );

        $state = RunStateBuilder::running('run-extract-malformed')
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(2)
            ->withPendingToolCalls(['tool-malformed' => false])
            ->withActiveStepId('turn-1-malformed')
            ->build();

        // Null result: the helper must degrade to '' without throwing.
        $message = ToolCallResultBuilder::success('run-extract-malformed')
            ->withTurnNo(1)
            ->withStepId('turn-1-malformed')
            ->withIdempotencyKey('tool-result-malformed')
            ->withToolCallId('tool-malformed')
            ->withOrderIndex(0)
            ->withResult(null)
            ->build();

        // Should not throw despite null result.
        $result = $handler->handle($message, $state);

        $this->assertSame('tool_execution_end', $result->events[1]->type);
        $this->assertArrayHasKey('result', $result->events[1]->payload);
        $this->assertSame('', $result->events[1]->payload['result'],
            'Null result should produce empty string without throwing');
    }

    public function testExtractResultTextNonArrayContentReturnsEmpty(): void
    {
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch(
            runId: 'run-extract-nonarr-content',
            turnNo: 1,
            stepId: 'turn-1-nonarr-content',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-extract-nonarr-content',
                    turnNo: 1,
                    stepId: 'turn-1-nonarr-content',
                    attempt: 1,
                    idempotencyKey: 'exec-nonarr-content',
                    toolCallId: 'tool-nonarr-content',
                    toolName: 'read',
                    args: [],
                    orderIndex: 0,
                    maxParallelism: 1,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
        );

        $state = RunStateBuilder::running('run-extract-nonarr-content')
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(2)
            ->withPendingToolCalls(['tool-nonarr-content' => false])
            ->withActiveStepId('turn-1-nonarr-content')
            ->build();

        // Content key is present but its value is a string, not an array.
        $message = ToolCallResultBuilder::success('run-extract-nonarr-content')
            ->withTurnNo(1)
            ->withStepId('turn-1-nonarr-content')
            ->withIdempotencyKey('tool-result-nonarr-content')
            ->withToolCallId('tool-nonarr-content')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'read',
                'content' => 'not-an-array',
            ])
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertSame('tool_execution_end', $result->events[1]->type);
        $this->assertArrayHasKey('result', $result->events[1]->payload);
        $this->assertSame('', $result->events[1]->payload['result'],
            'Non-array content should produce empty string without throwing');
    }

    public function testExtractResultTextNonArrayPartReturnsEmpty(): void
    {
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch(
            runId: 'run-extract-nonarr-part',
            turnNo: 1,
            stepId: 'turn-1-nonarr-part',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-extract-nonarr-part',
                    turnNo: 1,
                    stepId: 'turn-1-nonarr-part',
                    attempt: 1,
                    idempotencyKey: 'exec-nonarr-part',
                    toolCallId: 'tool-nonarr-part',
                    toolName: 'read',
                    args: [],
                    orderIndex: 0,
                    maxParallelism: 1,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
        );

        $state = RunStateBuilder::running('run-extract-nonarr-part')
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(2)
            ->withPendingToolCalls(['tool-nonarr-part' => false])
            ->withActiveStepId('turn-1-nonarr-part')
            ->build();

        // Content is an array, but the element is not an array (no 'type' key).
        $message = ToolCallResultBuilder::success('run-extract-nonarr-part')
            ->withTurnNo(1)
            ->withStepId('turn-1-nonarr-part')
            ->withIdempotencyKey('tool-result-nonarr-part')
            ->withToolCallId('tool-nonarr-part')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'read',
                'content' => ['not-an-array-element'],
            ])
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertSame('tool_execution_end', $result->events[1]->type);
        $this->assertArrayHasKey('result', $result->events[1]->payload);
        $this->assertSame('', $result->events[1]->payload['result'],
            'Non-array content part should produce empty string without throwing');
    }

    public function testExtractResultTextMissingContentKeyReturnsEmpty(): void
    {
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch(
            runId: 'run-extract-nokey',
            turnNo: 1,
            stepId: 'turn-1-nokey',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-extract-nokey',
                    turnNo: 1,
                    stepId: 'turn-1-nokey',
                    attempt: 1,
                    idempotencyKey: 'exec-nokey',
                    toolCallId: 'tool-nokey',
                    toolName: 'read',
                    args: [],
                    orderIndex: 0,
                    maxParallelism: 1,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
        );

        $state = RunStateBuilder::running('run-extract-nokey')
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(2)
            ->withPendingToolCalls(['tool-nokey' => false])
            ->withActiveStepId('turn-1-nokey')
            ->build();

        // Content key is entirely missing from the result.
        $message = ToolCallResultBuilder::success('run-extract-nokey')
            ->withTurnNo(1)
            ->withStepId('turn-1-nokey')
            ->withIdempotencyKey('tool-result-nokey')
            ->withToolCallId('tool-nokey')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'read',
            ])
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertSame('tool_execution_end', $result->events[1]->type);
        $this->assertArrayHasKey('result', $result->events[1]->payload);
        $this->assertSame('', $result->events[1]->payload['result'],
            'Missing content key should produce empty string without throwing');
    }

    public function testExtractResultTextErrorResult(): void
    {
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch(
            runId: 'run-extract-error',
            turnNo: 1,
            stepId: 'turn-1-error',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-extract-error',
                    turnNo: 1,
                    stepId: 'turn-1-error',
                    attempt: 1,
                    idempotencyKey: 'exec-error',
                    toolCallId: 'tool-error',
                    toolName: 'read',
                    args: ['path' => './missing.txt'],
                    orderIndex: 0,
                    maxParallelism: 1,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
        );

        $state = RunStateBuilder::running('run-extract-error')
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(2)
            ->withPendingToolCalls(['tool-error' => false])
            ->withActiveStepId('turn-1-error')
            ->build();

        // Error results still carry the exception message in content[0]['text'].
        $message = ToolCallResultBuilder::success('run-extract-error')
            ->withTurnNo(1)
            ->withStepId('turn-1-error')
            ->withIdempotencyKey('tool-result-error')
            ->withToolCallId('tool-error')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'read',
                'content' => [['type' => 'text', 'text' => 'File not found: ./missing.txt']],
            ])
            ->withIsError(true)
            ->build();

        // Should not throw despite isError=true.
        $result = $handler->handle($message, $state);

        $this->assertSame('tool_execution_end', $result->events[1]->type);
        $this->assertArrayHasKey('result', $result->events[1]->payload);
        $this->assertSame('File not found: ./missing.txt', $result->events[1]->payload['result'],
            'Error results should carry the error message as result text');
    }

    public function testCancellingWithPendingToolCallsSynthesizesToolMessages(): void
    {
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
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
        $this->assertSame(10, $result->nextState->lastSeq);
        $this->assertSame([], $result->nextState->pendingToolCalls);

        // Messages: original assistant + synthetic tool
        $this->assertCount(2, $result->nextState->messages);
        $this->assertSame('assistant', $result->nextState->messages[0]->role);
        $this->assertSame('tool', $result->nextState->messages[1]->role);
        $this->assertSame('tc-cat', $result->nextState->messages[1]->toolCallId);
        $this->assertFalse($result->nextState->messages[1]->isError);
        $this->assertSame('bash', $result->nextState->messages[1]->toolName);

        // Events: StaleResultIgnored + ToolCallResultReceived + ToolExecutionEnd + MessageStart + MessageEnd + ToolBatchCommitted + AgentEnd
        $this->assertCount(6, $result->events);
        $this->assertSame('tool_call_result_received', $result->events[0]->type);
        $this->assertSame('tc-cat', $result->events[0]->payload['tool_call_id']);
        $this->assertFalse($result->events[0]->payload['is_error']);
        $this->assertSame('tool_execution_end', $result->events[1]->type);
        $this->assertSame('tc-cat', $result->events[1]->payload['tool_call_id']);
        $this->assertFalse($result->events[1]->payload['is_error']);
        $this->assertSame('results', $result->events[1]->payload['result']);
        $this->assertSame('message_start', $result->events[2]->type);
        $this->assertSame('tool', $result->events[2]->payload['message_role']);
        $this->assertSame('message_end', $result->events[3]->type);
        $this->assertSame('tool', $result->events[3]->payload['message_role']);
        $this->assertSame('tc-cat', $result->events[3]->payload['tool_call_id']);
        $this->assertSame('tool_batch_committed', $result->events[4]->type);
        $this->assertSame(1, $result->events[4]->payload['count']);
        $this->assertSame('agent_end', $result->events[5]->type);
        $this->assertSame('cancelled', $result->events[5]->payload['reason']);

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

        // Only StaleResultIgnored + AgentEnd (no synthetic messages)
        $this->assertCount(2, $result->events);
        $this->assertSame('stale_result_ignored', $result->events[0]->type);
        $this->assertSame('agent_end', $result->events[1]->type);
        $this->assertSame('cancelled', $result->events[1]->payload['reason']);

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

        // Events: StaleResultIgnored + 4-per-tool×2 + ToolBatchCommitted + AgentEnd = 11
        $this->assertCount(10, $result->events);

        $this->assertSame('tool_call_result_received', $result->events[0]->type);
        $this->assertSame('tc-read-1', $result->events[0]->payload['tool_call_id']);
        $this->assertFalse($result->events[0]->payload['is_error']);
        $this->assertSame('tool_execution_end', $result->events[1]->type);
        $this->assertSame('tc-read-1', $result->events[1]->payload['tool_call_id']);
        $this->assertSame('a content', $result->events[1]->payload['result']);
        $this->assertSame('message_start', $result->events[2]->type);
        $this->assertSame('message_end', $result->events[3]->type);

        $this->assertSame('tool_call_result_received', $result->events[4]->type);
        $this->assertSame('tc-read-2', $result->events[4]->payload['tool_call_id']);
        $this->assertTrue($result->events[4]->payload['is_error']);
        $this->assertSame('tool_execution_end', $result->events[5]->type);
        $this->assertSame('tc-read-2', $result->events[5]->payload['tool_call_id']);
        $this->assertSame('message_start', $result->events[6]->type);
        $this->assertSame('message_end', $result->events[7]->type);

        $this->assertSame('tool_batch_committed', $result->events[8]->type);
        $this->assertSame(2, $result->events[8]->payload['count']);
        $this->assertSame('agent_end', $result->events[9]->type);
        $this->assertSame('cancelled', $result->events[9]->payload['reason']);

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
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
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

        $state = RunStateBuilder::running('run-cancel-partial')
            ->withStatus(RunStatus::Cancelling)
            ->withVersion(3)
            ->withTurnNo(1)
            ->withLastSeq(4)
            ->withPendingToolCalls(['tc-done' => true, 'tc-pending' => false])
            ->withActiveStepId('turn-step-1')
            ->withMessages([$assistantMsg])
            ->build();

        $message = ToolCallResultBuilder::success('run-cancel-partial')
            ->withTurnNo(1)
            ->withStepId('turn-step-1')
            ->withIdempotencyKey('tool-result-arriving')
            ->withToolCallId('tc-done')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'done']]])
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertCount(2, $result->nextState->messages);
        $this->assertSame('tool', $result->nextState->messages[1]->role);
        $this->assertSame('tc-pending', $result->nextState->messages[1]->toolCallId);

        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);
        $this->assertSame([], $result->nextState->pendingToolCalls);
    }

    public function testCancellingSyntheticMessagesPassValidator(): void
    {
        $handler = new ToolCallResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
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
        $richMessage = implode("
", [
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

        self::assertSame('tool_execution_end', $result->events[1]->type);
        self::assertSame($richMessage, $result->events[1]->payload['result']);
        self::assertSame('tool', $result->nextState->messages[1]->role);
        self::assertStringContainsString('Artifact: agent_41d4ca5566368a6b', $result->nextState->messages[1]->content[0]['text'] ?? '');
    }
}

