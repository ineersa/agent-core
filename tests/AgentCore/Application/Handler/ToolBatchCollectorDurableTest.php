<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\InMemoryToolBatchStore;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests ToolBatchCollector with a durable store.
 *
 * Verifies that registration, collect, and state transitions
 * work correctly when backed by a ToolBatchStoreInterface.
 */
final class ToolBatchCollectorDurableTest extends TestCase
{
    public function testRegisterAndCollectWithStore(): void
    {
        $store = new InMemoryToolBatchStore();
        $collector = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);

        $initial = $collector->registerExpectedBatch('run-1', 1, 'step-1', [
            $this->executeToolCall('run-1', 'step-1', 'call-1', 0, 'sequential'),
            $this->executeToolCall('run-1', 'step-1', 'call-2', 1, 'sequential'),
        ]);

        self::assertCount(1, $initial);
        self::assertSame('call-1', $initial[0]->toolCallId);

        $firstOutcome = $collector->collect($this->toolResult('run-1', 'step-1', 'call-1', 0));

        self::assertTrue($firstOutcome->accepted);
        self::assertFalse($firstOutcome->complete);
        self::assertCount(1, $firstOutcome->effectsToDispatch);
        self::assertSame('call-2', $firstOutcome->effectsToDispatch[0]->toolCallId);

        // Verify state persisted in store
        $loaded = $store->load('run-1', 1, 'step-1');
        self::assertIsArray($loaded);
        self::assertFalse($loaded['finalized']);
        self::assertCount(1, $loaded['result_data']);

        $secondOutcome = $collector->collect($this->toolResult('run-1', 'step-1', 'call-2', 1));

        self::assertTrue($secondOutcome->accepted);
        self::assertTrue($secondOutcome->complete);
        self::assertSame(['call-1', 'call-2'], array_map(
            static fn (ToolCallResult $result): string => $result->toolCallId,
            $secondOutcome->orderedResults,
        ));

        // Verify finalized in store
        $finalized = $store->load('run-1', 1, 'step-1');
        self::assertTrue($finalized['finalized']);
    }

    public function testCrossProcessRecoveryWithStore(): void
    {
        $store = new InMemoryToolBatchStore();
        $registrar = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);

        // Process 1: register the batch
        $initial = $registrar->registerExpectedBatch('run-2', 1, 'step-1', [
            $this->executeToolCall('run-2', 'step-1', 'call-1', 0, 'parallel', maxParallelism: 2),
            $this->executeToolCall('run-2', 'step-1', 'call-2', 1, 'parallel', maxParallelism: 2),
        ]);

        self::assertCount(2, $initial);

        // Drop the original collector (simulates process restart)
        unset($registrar);

        // Process 2: new collector in a different "process" with the same store
        $recovering = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);

        // Collect first result - should find batch from store
        $firstOutcome = $recovering->collect($this->toolResult('run-2', 'step-1', 'call-1', 0));
        self::assertTrue($firstOutcome->accepted);
        self::assertFalse($firstOutcome->complete);
        // The other call (call-2) is already in_flight from registration, so no new effects
        self::assertEmpty($firstOutcome->effectsToDispatch);

        $secondOutcome = $recovering->collect($this->toolResult('run-2', 'step-1', 'call-2', 1));
        self::assertTrue($secondOutcome->accepted);
        self::assertTrue($secondOutcome->complete);
        self::assertSame(['call-1', 'call-2'], array_map(
            static fn (ToolCallResult $result): string => $result->toolCallId,
            $secondOutcome->orderedResults,
        ));
    }

    public function testCrossProcessRecoveryDispatchesPendingCalls(): void
    {
        $store = new InMemoryToolBatchStore();
        $registrar = new ToolBatchCollector(defaultMaxParallelism: 2, store: $store);

        // Process 1: register with 3 parallel calls (max 2 at a time)
        $initial = $registrar->registerExpectedBatch('run-3', 1, 'step-1', [
            $this->executeToolCall('run-3', 'step-1', 'call-1', 0, 'parallel', maxParallelism: 2),
            $this->executeToolCall('run-3', 'step-1', 'call-2', 1, 'parallel', maxParallelism: 2),
            $this->executeToolCall('run-3', 'step-1', 'call-3', 2, 'parallel', maxParallelism: 2),
        ]);

        self::assertCount(2, $initial); // call-1 and call-2 dispatched

        // Drop original collector (simulates crash of one process)
        unset($registrar);

        // Process 2: new collector, collects call-1 result
        $recovering = new ToolBatchCollector(defaultMaxParallelism: 2, store: $store);
        $firstOutcome = $recovering->collect($this->toolResult('run-3', 'step-1', 'call-1', 0));

        self::assertTrue($firstOutcome->accepted);
        self::assertFalse($firstOutcome->complete);
        // call-3 should now be dispatchable (1 in_flight, max 2)
        // call-2 is still in_flight from original registration
        // actually, call-2 was dispatched initially. When call-1 completes,
        // only 1 remains in_flight (call-2), so call-3 can be dispatched
        self::assertCount(1, $firstOutcome->effectsToDispatch);
        self::assertSame('call-3', $firstOutcome->effectsToDispatch[0]->toolCallId);
    }

    public function testRejectedWhenStoreIsEmpty(): void
    {
        $store = new InMemoryToolBatchStore();
        $collector = new ToolBatchCollector(store: $store);

        $outcome = $collector->collect($this->toolResult('run-nonexistent', 'step-1', 'call-1', 0));
        self::assertFalse($outcome->accepted);
        self::assertFalse($outcome->duplicate);
    }

    public function testDuplicateResultWithStore(): void
    {
        $store = new InMemoryToolBatchStore();
        $collector = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);

        $collector->registerExpectedBatch('run-4', 1, 'step-1', [
            $this->executeToolCall('run-4', 'step-1', 'call-1', 0, 'sequential'),
        ]);

        $firstOutcome = $collector->collect($this->toolResult('run-4', 'step-1', 'call-1', 0));
        self::assertTrue($firstOutcome->accepted);

        // Duplicate collect
        $dupOutcome = $collector->collect($this->toolResult('run-4', 'step-1', 'call-1', 0));
        self::assertTrue($dupOutcome->duplicate);
    }

    public function testCrossProcessParallelDispatchRecovery(): void
    {
        $store = new InMemoryToolBatchStore();
        $registrar = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);

        // Register sequential call-1 (dispatched), then parallel call-2,3 (pending)
        $initial = $registrar->registerExpectedBatch('run-5', 1, 'step-1', [
            $this->executeToolCall('run-5', 'step-1', 'call-1', 0, 'sequential'),
            $this->executeToolCall('run-5', 'step-1', 'call-2', 1, 'parallel', maxParallelism: 4),
            $this->executeToolCall('run-5', 'step-1', 'call-3', 2, 'parallel', maxParallelism: 4),
        ]);

        self::assertCount(1, $initial); // only call-1 (sequential blocks parallel)
        unset($registrar);

        // Process 2: collect call-1 result → should dispatch call-2
        $recovering = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);
        $firstOutcome = $recovering->collect($this->toolResult('run-5', 'step-1', 'call-1', 0));

        self::assertTrue($firstOutcome->accepted);
        self::assertFalse($firstOutcome->complete);
        // After call-1 completes, call-2 and call-3 are both parallel and dispatchable
        self::assertCount(2, $firstOutcome->effectsToDispatch);
        self::assertSame('call-2', $firstOutcome->effectsToDispatch[0]->toolCallId);
        self::assertSame('call-3', $firstOutcome->effectsToDispatch[1]->toolCallId);
    }

    public function testFailedDurableSaveDoesNotDirtyInMemoryCache(): void
    {
        $store = new class implements ToolBatchStoreInterface {
            /** @var array<string, array> */
            public array $states = [];
            public bool $failNextMutate = false;

            public function load(string $runId, int $turnNo, string $stepId): ?array
            {
                return $this->states[$this->key($runId, $turnNo, $stepId)] ?? null;
            }

            public function save(string $runId, int $turnNo, string $stepId, array $batchState): void
            {
                $this->states[$this->key($runId, $turnNo, $stepId)] = $batchState;
            }

            public function delete(string $runId, int $turnNo, string $stepId): void
            {
                unset($this->states[$this->key($runId, $turnNo, $stepId)]);
            }

            public function mutate(string $runId, int $turnNo, string $stepId, callable $callback): mixed
            {
                if ($this->failNextMutate) {
                    $this->failNextMutate = false;

                    throw new \RuntimeException('Simulated durable write failure.');
                }

                $key = $this->key($runId, $turnNo, $stepId);
                $current = $this->states[$key] ?? null;
                $outcome = $callback($current);
                if (!$outcome instanceof ToolBatchStoreMutation) {
                    throw new \LogicException('Tool batch store mutate callback must return ToolBatchStoreMutation.');
                }

                if (null !== $outcome->nextSerializedState) {
                    $this->states[$key] = $outcome->nextSerializedState;
                }

                return $outcome->returnValue;
            }

            private function key(string $runId, int $turnNo, string $stepId): string
            {
                return \sprintf('%s|%d|%s', $runId, $turnNo, $stepId);
            }
        };

        $collector = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);
        $collector->registerExpectedBatch('run-6', 1, 'step-1', [
            $this->executeToolCall('run-6', 'step-1', 'call-1', 0, 'sequential'),
        ]);

        $store->failNextMutate = true;

        try {
            $collector->collect($this->toolResult('run-6', 'step-1', 'call-1', 0));
            self::fail('Expected simulated durable write failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Simulated durable write failure.', $e->getMessage());
        }

        $retryOutcome = $collector->collect($this->toolResult('run-6', 'step-1', 'call-1', 0));

        self::assertTrue($retryOutcome->accepted);
        self::assertFalse($retryOutcome->duplicate);
        self::assertTrue($retryOutcome->complete);
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
