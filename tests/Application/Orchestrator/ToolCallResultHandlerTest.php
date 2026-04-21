<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Orchestrator\RunMessageStateTools;
use Ineersa\AgentCore\Application\Orchestrator\ToolCallResultHandler;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
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
            stateTools: new RunMessageStateTools(),
        );

        $state = new RunState(
            runId: 'run-tool-handler-1',
            status: RunStatus::Running,
            version: 5,
            turnNo: 1,
            lastSeq: 6,
            pendingToolCalls: [
                'tool-a' => false,
                'tool-b' => false,
            ],
            activeStepId: 'turn-1-step',
        );

        $message = new ToolCallResult(
            runId: 'run-tool-handler-1',
            turnNo: 1,
            stepId: 'turn-1-step',
            attempt: 1,
            idempotencyKey: 'tool-result-a',
            toolCallId: 'tool-a',
            orderIndex: 0,
            result: [
                'tool_name' => 'alpha',
                'content' => [['type' => 'text', 'text' => 'A']],
            ],
            isError: false,
            error: null,
        );

        $result = $handler->handle($message, $state);

        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Running, $result->nextState->status);
        self::assertSame(6, $result->nextState->version);
        self::assertSame(8, $result->nextState->lastSeq);
        self::assertSame([
            'tool-a' => true,
            'tool-b' => false,
        ], $result->nextState->pendingToolCalls);

        self::assertCount(2, $result->events);
        self::assertSame('tool_call_result_received', $result->events[0]->type);
        self::assertSame('tool_execution_end', $result->events[1]->type);

        self::assertSame([], $result->effects);
        self::assertCount(1, $result->postCommitEffects);
        self::assertInstanceOf(ExecuteToolCall::class, $result->postCommitEffects[0]);
        self::assertSame('tool-b', $result->postCommitEffects[0]->toolCallId);
        self::assertSame([], $result->postCommit);
        self::assertTrue($result->markHandled);
    }
}
