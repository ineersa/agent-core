<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use PHPUnit\Framework\TestCase;

final class ToolBatchCollectorTest extends TestCase
{
    public function testSequentialModeDispatchesOneCallAtATimeInAssistantOrder(): void
    {
        $collector = new ToolBatchCollector(defaultMaxParallelism: 4);

        $initial = $collector->registerExpectedBatch('run-1', 1, 'step-1', [
            $this->executeToolCall('run-1', 'step-1', 'call-1', 0, 'sequential'),
            $this->executeToolCall('run-1', 'step-1', 'call-2', 1, 'sequential'),
        ]);

        $this->assertCount(1, $initial);
        $this->assertSame('call-1', $initial[0]->toolCallId);

        $firstOutcome = $collector->collect($this->toolResult('run-1', 'step-1', 'call-1', 0));

        $this->assertTrue($firstOutcome->accepted);
        $this->assertFalse($firstOutcome->complete);
        $this->assertCount(1, $firstOutcome->effectsToDispatch);
        $this->assertSame('call-2', $firstOutcome->effectsToDispatch[0]->toolCallId);

        $secondOutcome = $collector->collect($this->toolResult('run-1', 'step-1', 'call-2', 1));

        $this->assertTrue($secondOutcome->accepted);
        $this->assertTrue($secondOutcome->complete);
        $this->assertSame(['call-1', 'call-2'], array_map(
            static fn (ToolCallResult $result): string => $result->toolCallId,
            $secondOutcome->orderedResults,
        ));
    }

    public function testParallelModeRespectsMaxParallelismAndKeepsOrderedCommit(): void
    {
        $collector = new ToolBatchCollector(defaultMaxParallelism: 2);

        $initial = $collector->registerExpectedBatch('run-2', 1, 'step-2', [
            $this->executeToolCall('run-2', 'step-2', 'call-1', 0, 'parallel', maxParallelism: 2),
            $this->executeToolCall('run-2', 'step-2', 'call-2', 1, 'parallel', maxParallelism: 2),
            $this->executeToolCall('run-2', 'step-2', 'call-3', 2, 'parallel', maxParallelism: 2),
        ]);

        $this->assertCount(2, $initial);
        $this->assertSame(['call-1', 'call-2'], array_map(
            static fn (ExecuteToolCall $call): string => $call->toolCallId,
            $initial,
        ));

        $firstOutcome = $collector->collect($this->toolResult('run-2', 'step-2', 'call-1', 0));
        $this->assertFalse($firstOutcome->complete);
        $this->assertCount(1, $firstOutcome->effectsToDispatch);
        $this->assertSame('call-3', $firstOutcome->effectsToDispatch[0]->toolCallId);

        $secondOutcome = $collector->collect($this->toolResult('run-2', 'step-2', 'call-2', 1));
        $this->assertFalse($secondOutcome->complete);
        $this->assertSame([], $secondOutcome->effectsToDispatch);

        $complete = $collector->collect($this->toolResult('run-2', 'step-2', 'call-3', 2));

        $this->assertTrue($complete->complete);
        $this->assertSame(['call-1', 'call-2', 'call-3'], array_map(
            static fn (ToolCallResult $result): string => $result->toolCallId,
            $complete->orderedResults,
        ));
    }

    public function testMixedParallelAndSequentialDispatch(): void
    {
        $collector = new ToolBatchCollector(defaultMaxParallelism: 4);

        // [read(parallel, idx=0), read(parallel, idx=1), bash(sequential, idx=2)]
        $initial = $collector->registerExpectedBatch('run-3', 1, 'step-3', [
            $this->executeToolCall('run-3', 'step-3', 'call-1', 0, 'parallel', maxParallelism: 4),
            $this->executeToolCall('run-3', 'step-3', 'call-2', 1, 'parallel', maxParallelism: 4),
            $this->executeToolCall('run-3', 'step-3', 'call-3', 2, 'sequential', maxParallelism: 4),
        ]);

        // Both parallel tools dispatch immediately; sequential tool is blocked
        $this->assertCount(2, $initial);
        $this->assertSame(['call-1', 'call-2'], array_map(
            static fn (ExecuteToolCall $call): string => $call->toolCallId,
            $initial,
        ));

        // Complete call-1 — still in-flight (call-2), sequential barrier holds call-3
        $firstOutcome = $collector->collect($this->toolResult('run-3', 'step-3', 'call-1', 0));
        $this->assertFalse($firstOutcome->complete);
        $this->assertSame([], $firstOutcome->effectsToDispatch);

        // Complete call-2 — no more in-flight, now sequential call-3 dispatches
        $secondOutcome = $collector->collect($this->toolResult('run-3', 'step-3', 'call-2', 1));
        $this->assertFalse($secondOutcome->complete);
        $this->assertCount(1, $secondOutcome->effectsToDispatch);
        $this->assertSame('call-3', $secondOutcome->effectsToDispatch[0]->toolCallId);

        // Complete call-3 — batch done
        $complete = $collector->collect($this->toolResult('run-3', 'step-3', 'call-3', 2));
        $this->assertTrue($complete->complete);
        $this->assertSame(['call-1', 'call-2', 'call-3'], array_map(
            static fn (ToolCallResult $result): string => $result->toolCallId,
            $complete->orderedResults,
        ));
    }

    public function testSequentialBarrierBlocksLaterParallelToolsUntilInFlightsClear(): void
    {
        $collector = new ToolBatchCollector(defaultMaxParallelism: 4);

        // [read(parallel, idx=0), bash(sequential, idx=1), read(parallel, idx=2)]
        $initial = $collector->registerExpectedBatch('run-4', 1, 'step-4', [
            $this->executeToolCall('run-4', 'step-4', 'call-1', 0, 'parallel', maxParallelism: 4),
            $this->executeToolCall('run-4', 'step-4', 'call-2', 1, 'sequential', maxParallelism: 4),
            $this->executeToolCall('run-4', 'step-4', 'call-3', 2, 'parallel', maxParallelism: 4),
        ]);

        // Only call-1 (parallel) dispatches; sequential blocks everything behind it
        $this->assertCount(1, $initial);
        $this->assertSame('call-1', $initial[0]->toolCallId);

        // Complete call-1 → sequential call-2 dispatches alone
        $outcome1 = $collector->collect($this->toolResult('run-4', 'step-4', 'call-1', 0));
        $this->assertFalse($outcome1->complete);
        $this->assertCount(1, $outcome1->effectsToDispatch);
        $this->assertSame('call-2', $outcome1->effectsToDispatch[0]->toolCallId);

        // Complete call-2 → parallel call-3 dispatches
        $outcome2 = $collector->collect($this->toolResult('run-4', 'step-4', 'call-2', 1));
        $this->assertFalse($outcome2->complete);
        $this->assertCount(1, $outcome2->effectsToDispatch);
        $this->assertSame('call-3', $outcome2->effectsToDispatch[0]->toolCallId);

        // Complete call-3 → batch done
        $complete = $collector->collect($this->toolResult('run-4', 'step-4', 'call-3', 2));
        $this->assertTrue($complete->complete);
    }

    private function executeToolCall(
        string $runId,
        string $stepId,
        string $toolCallId,
        int $orderIndex,
        string $mode,
        int $maxParallelism = 4,
    ): ExecuteToolCall {
        return new ExecuteToolCall(
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $toolCallId)),
            toolCallId: $toolCallId,
            toolName: 'web_search',
            args: ['query' => $toolCallId],
            orderIndex: $orderIndex,
            mode: $mode,
            maxParallelism: $maxParallelism,
        );
    }

    private function toolResult(string $runId, string $stepId, string $toolCallId, int $orderIndex): ToolCallResult
    {
        return new ToolCallResult(
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s|result', $runId, $toolCallId)),
            toolCallId: $toolCallId,
            orderIndex: $orderIndex,
            result: ['tool_name' => 'web_search', 'content' => [['type' => 'text', 'text' => 'ok']]],
            isError: false,
            error: null,
        );
    }
}
