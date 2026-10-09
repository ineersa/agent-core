<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\AgentCore\Tests\Support\TestToolBatchStore;
use PHPUnit\Framework\TestCase;

/**
 * Regression: finalized durable schedule must replay acceptedComplete on redelivery
 * when canonical commit never happened (old behavior returned duplicate and stalled).
 */
final class ToolBatchCollectorFinalizedRedeliveryTest extends TestCase
{
    public function testFinalizedSnapshotRedeliveryReplaysAcceptedComplete(): void
    {
        $store = $this->createStore();
        $collector = new ToolBatchCollector($store, 4);

        TestToolBatchRegistration::register($collector, $store, 'run-1', 1, 'step-1', [
            $this->executeToolCall('call-1', 0),
        ]);

        $result = $this->toolResult('call-1', 0);
        $first = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $store, $result);
        $this->assertTrue($first->complete);

        $redelivery = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $store, $result);
        $this->assertTrue($redelivery->accepted);
        $this->assertFalse($redelivery->duplicate);
        $this->assertTrue($redelivery->complete);
        $this->assertSame(['call-1'], array_map(static fn (ToolCallResult $r): string => $r->toolCallId, $redelivery->orderedResults));
        $this->assertSame([], $redelivery->effectsToDispatch);
    }

    private function executeToolCall(string $toolCallId, int $orderIndex): ExecuteToolCall
    {
        return new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: hash('sha256', 'call-'.$toolCallId),
            toolCallId: $toolCallId,
            toolName: 'read',
            args: [],
            orderIndex: $orderIndex,
        );
    }

    private function createStore(): ToolBatchStoreInterface
    {
        return new TestToolBatchStore();
    }

    private function toolResult(string $toolCallId, int $orderIndex): ToolCallResult
    {
        return new ToolCallResult(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: hash('sha256', 'result-'.$toolCallId),
            toolCallId: $toolCallId,
            orderIndex: $orderIndex,
            result: ['ok' => true],
            isError: false,
            error: null,
        );
    }
}
