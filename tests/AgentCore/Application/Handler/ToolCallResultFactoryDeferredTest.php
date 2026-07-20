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
    }

    public function testOutcomeVariantsHaveDistinctDeterministicIdempotencyKeys(): void
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
        $requestOther = PendingHumanInputRequestDTO::toolCallFromPayload(
            ['question_id' => 'q-2', 'prompt' => 'Allow other?'],
            ['run_id' => 'run-1', 'turn_no' => 1, 'step_id' => 'step-1', 'tool_call_id' => 'call-1'],
        );

        $suspension = ToolCallResultFactory::fromExecuteToolCallAndHumanInputSuspension(
            $execute,
            new ToolExecutionHumanInputSuspension($request),
        );
        $suspensionDup = ToolCallResultFactory::fromExecuteToolCallAndHumanInputSuspension(
            $execute,
            new ToolExecutionHumanInputSuspension($request),
        );
        $suspensionOther = ToolCallResultFactory::fromExecuteToolCallAndHumanInputSuspension(
            $execute,
            new ToolExecutionHumanInputSuspension($requestOther),
        );
        $terminal = ToolCallResultFactory::fromExecuteToolCallAndToolResult(
            $execute,
            new ToolResult('call-1', 'write', [['type' => 'text', 'text' => 'ok']], isError: false),
        );
        $terminalDup = ToolCallResultFactory::fromExecuteToolCallAndToolResult(
            $execute,
            new ToolResult('call-1', 'write', [['type' => 'text', 'text' => 'ok']], isError: false),
        );
        $throwable = ToolCallResultFactory::fromExecuteToolCallAndThrowable(
            $execute,
            new \RuntimeException('boom'),
        );

        $this->assertNotSame($execute->idempotencyKey(), $suspension->idempotencyKey());
        $this->assertNotSame($suspension->idempotencyKey(), $terminal->idempotencyKey());
        $this->assertSame($suspension->idempotencyKey(), $suspensionDup->idempotencyKey());
        $this->assertNotSame($suspension->idempotencyKey(), $suspensionOther->idempotencyKey());
        $this->assertSame($terminal->idempotencyKey(), $terminalDup->idempotencyKey());
        $this->assertSame($terminal->idempotencyKey(), $throwable->idempotencyKey());

        $deferred = ToolCallResultFactory::fromDeferredCorrelationAndCompletion(
            new DeferredToolCompletionCorrelation(
                deferredId: 'd1',
                runId: 'run-1',
                turnNo: 1,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'exec-key',
                toolCallId: 'call-1',
                toolName: 'write',
                arguments: [],
                orderIndex: 0,
            ),
            [['type' => 'text', 'text' => 'ok']],
        );
        $this->assertSame($terminal->idempotencyKey(), $deferred->idempotencyKey());
    }
}
