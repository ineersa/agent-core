<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use PHPUnit\Framework\TestCase;

final class ToolCallResultFactoryDeferredTest extends TestCase
{
    public function testFromDeferredCorrelationPromotesDetailsToolIdempotencyKey(): void
    {
        $correlation = new DeferredToolCompletionCorrelation(
            deferredId: 'd1',
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'msg-key',
            toolCallId: 'call-1',
            toolName: 'subagent',
            arguments: [],
            orderIndex: 0,
            toolIdempotencyKey: 'stored-key',
        );

        $result = ToolCallResultFactory::fromDeferredCorrelationAndCompletion(
            $correlation,
            [['type' => 'text', 'text' => 'done']],
            details: ['tool_idempotency_key' => 'custom-key'],
        );

        $this->assertSame('custom-key', $result->result['tool_idempotency_key']);
        $this->assertSame($correlation->idempotencyKey, $result->idempotencyKey());
    }

    public function testOutcomesRetainTheirAuthorizedInvocationIdentity(): void
    {
        $execute = new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'exec-key',
            toolCallId: 'call-1',
            toolName: 'write',
            args: ['path' => '../x.txt', 'content' => 'hello'],
            orderIndex: 0,
        );
        $request = PendingHumanInputRequestDTO::toolCallFromPayload(
            ['question_id' => 'q-1', 'prompt' => 'Allow?'],
            ['run_id' => 'run-1', 'turn_no' => 1, 'step_id' => 'step-1', 'tool_call_id' => 'call-1'],
        );

        $suspension = ToolCallResultFactory::fromExecuteToolCallAndHumanInputSuspension(
            $execute,
            new ToolExecutionHumanInputSuspension($request),
        );
        $approved = new ExecuteToolCall(
            'run-1', 1, 'step-1', 2, 'approved-key', 'call-1', 'write', $execute->args, 0,
        );
        $terminal = ToolCallResultFactory::fromExecuteToolCallAndToolResult(
            $approved,
            new ToolResult('call-1', 'write', [['type' => 'text', 'text' => 'ok']], isError: false),
        );
        $throwable = ToolCallResultFactory::fromExecuteToolCallAndThrowable(
            $approved,
            new \RuntimeException('boom'),
        );

        $this->assertSame($execute->idempotencyKey(), $suspension->idempotencyKey());
        $this->assertSame($request, $suspension->pendingHumanInput);
        $this->assertNotSame($suspension->idempotencyKey(), $terminal->idempotencyKey());
        $this->assertSame($approved->idempotencyKey(), $terminal->idempotencyKey());
        $this->assertSame($approved->idempotencyKey(), $throwable->idempotencyKey());
        $this->assertSame($approved->attempt(), $terminal->attempt());
        $this->assertSame($suspension->toolCallId, $terminal->toolCallId);
    }
}
