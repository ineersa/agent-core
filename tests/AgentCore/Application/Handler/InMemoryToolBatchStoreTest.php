<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\InMemoryToolBatchStore;
use PHPUnit\Framework\TestCase;

final class InMemoryToolBatchStoreTest extends TestCase
{
    private InMemoryToolBatchStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryToolBatchStore();
    }

    public function testLoadReturnsNullForUnknownBatch(): void
    {
        self::assertNull($this->store->load('run-1', 1, 'step-1'));
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $state = [
            'expected_order' => ['call-1' => 0, 'call-2' => 1],
            'call_data' => [
                'call-1' => ['toolCallId' => 'call-1', 'toolName' => 'read', 'args' => [], 'orderIndex' => 0],
                'call-2' => ['toolCallId' => 'call-2', 'toolName' => 'write', 'args' => [], 'orderIndex' => 1],
            ],
            'pending_queue' => ['call-1', 'call-2'],
            'in_flight' => [],
            'result_data' => [],
            'finalized' => false,
            'max_parallelism' => 4,
        ];

        $this->store->save('run-1', 1, 'step-1', $state);
        $loaded = $this->store->load('run-1', 1, 'step-1');

        self::assertIsArray($loaded);
        self::assertSame($state, $loaded);
    }

    public function testSaveOverwritesExistingBatch(): void
    {
        $initial = ['expected_order' => ['call-1' => 0], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 1];
        $updated = ['expected_order' => ['call-1' => 0, 'call-2' => 1], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 2];

        $this->store->save('run-1', 1, 'step-1', $initial);
        $this->store->save('run-1', 1, 'step-1', $updated);

        $loaded = $this->store->load('run-1', 1, 'step-1');
        self::assertSame(2, $loaded['max_parallelism']);
    }

    public function testDeleteRemovesBatch(): void
    {
        $state = ['expected_order' => ['call-1' => 0], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 1];
        $this->store->save('run-1', 1, 'step-1', $state);
        $this->store->delete('run-1', 1, 'step-1');

        self::assertNull($this->store->load('run-1', 1, 'step-1'));
    }

    public function testDeleteDoesNotAffectOtherBatches(): void
    {
        $stateA = ['expected_order' => ['call-1' => 0], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 1];
        $stateB = ['expected_order' => ['call-2' => 0], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 2];
        $this->store->save('run-1', 1, 'step-1', $stateA);
        $this->store->save('run-1', 1, 'step-2', $stateB);

        $this->store->delete('run-1', 1, 'step-1');

        self::assertNull($this->store->load('run-1', 1, 'step-1'));
        self::assertIsArray($this->store->load('run-1', 1, 'step-2'));
    }
}
