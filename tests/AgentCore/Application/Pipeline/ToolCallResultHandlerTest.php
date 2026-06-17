<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
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

        // Content key is missing, 'content' is not an array, and result is null —
        // all cases the helper must degrade to '' without throwing.
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
            'Null or malformed result should produce empty string without throwing');
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
}
