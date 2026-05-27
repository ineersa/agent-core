<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\RunMessageStateTools;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
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
            stateTools: new RunMessageStateTools(new \Ineersa\AgentCore\Domain\Event\EventFactory(), new \Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor()),
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

        $this->assertSame([], $result->effects);
        $this->assertCount(1, $result->postCommitEffects);
        $this->assertInstanceOf(ExecuteToolCall::class, $result->postCommitEffects[0]);
        $this->assertSame('tool-b', $result->postCommitEffects[0]->toolCallId);
        $this->assertSame([], $result->postCommit);
        $this->assertTrue($result->markHandled);
    }
}
