<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\Store;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\Store\DbalToolBatchStore;

/**
 * Regression: parallel tool result workers must not lose durable batch updates.
 *
 * @requires extension pdo_sqlite
 */
final class DbalToolBatchStoreConcurrencyTest extends IsolatedKernelTestCase
{
    private DbalToolBatchStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = static::getContainer()->get(DbalToolBatchStore::class);
    }

    public function testParallelCollectFinalizesWhenThreeResultsArriveOnDurableStore(): void
    {
        $collector = new ToolBatchCollector(defaultMaxParallelism: 4, store: $this->store);

        $initial = $collector->registerExpectedBatch('run-par', 2, 'step-par', [
            $this->executeToolCall('run-par', 2, 'step-par', 'call-1', 0),
            $this->executeToolCall('run-par', 2, 'step-par', 'call-2', 1),
            $this->executeToolCall('run-par', 2, 'step-par', 'call-3', 2),
        ]);

        self::assertCount(3, $initial);

        $first = $collector->collect($this->toolResult('run-par', 2, 'step-par', 'call-1', 0));
        self::assertTrue($first->accepted);
        self::assertFalse($first->complete);

        $second = $collector->collect($this->toolResult('run-par', 2, 'step-par', 'call-2', 1));
        self::assertTrue($second->accepted);
        self::assertFalse($second->complete);

        $third = $collector->collect($this->toolResult('run-par', 2, 'step-par', 'call-3', 2));
        self::assertTrue($third->accepted);
        self::assertTrue($third->complete);
        self::assertSame(['call-1', 'call-2', 'call-3'], array_map(
            static fn (ToolCallResult $result): string => $result->toolCallId,
            $third->orderedResults,
        ));

        $loaded = $this->store->load('run-par', 2, 'step-par');
        self::assertIsArray($loaded);
        self::assertTrue($loaded['finalized']);
        self::assertCount(3, $loaded['result_data']);
    }

    public function testMutateUsesPessimisticWriteLockForSameBatchKey(): void
    {
        $runId = 'run-lock';
        $turnNo = 1;
        $stepId = 'step-lock';

        $this->store->save($runId, $turnNo, $stepId, [
            'expected_order' => ['call-1' => 0, 'call-2' => 1, 'call-3' => 2],
            'call_data' => [],
            'pending_queue' => [],
            'in_flight' => ['call-1' => true, 'call-2' => true, 'call-3' => true],
            'result_data' => [],
            'finalized' => false,
            'max_parallelism' => 4,
        ]);

        $this->store->mutate($runId, $turnNo, $stepId, function (?array $current) {
            self::assertIsArray($current);
            $current['result_data']['call-1'] = ['toolCallId' => 'call-1', 'orderIndex' => 0, 'result' => 'a', 'isError' => false, 'error' => null];

            return new \Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation(null, $current);
        });

        $this->store->mutate($runId, $turnNo, $stepId, function (?array $current) {
            self::assertIsArray($current);
            self::assertArrayHasKey('call-1', $current['result_data']);
            $current['result_data']['call-2'] = ['toolCallId' => 'call-2', 'orderIndex' => 1, 'result' => 'b', 'isError' => false, 'error' => null];

            return new \Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation(null, $current);
        });

        $loaded = $this->store->load($runId, $turnNo, $stepId);
        self::assertIsArray($loaded);
        self::assertCount(2, $loaded['result_data']);
        self::assertArrayHasKey('call-1', $loaded['result_data']);
        self::assertArrayHasKey('call-2', $loaded['result_data']);
    }

    private function executeToolCall(string $runId, int $turnNo, string $stepId, string $toolCallId, int $orderIndex): ExecuteToolCall
    {
        return new ExecuteToolCall(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $toolCallId)),
            toolCallId: $toolCallId,
            toolName: 'read',
            args: ['path' => '/tmp/example'],
            orderIndex: $orderIndex,
            mode: 'parallel',
            maxParallelism: 4,
        );
    }

    private function toolResult(string $runId, int $turnNo, string $stepId, string $toolCallId, int $orderIndex): ToolCallResult
    {
        return new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $stepId, $toolCallId)),
            toolCallId: $toolCallId,
            orderIndex: $orderIndex,
            result: 'ok-'.$toolCallId,
            isError: false,
            error: null,
        );
    }
}
