<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Tool\Store\DbalToolBatchStore;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension pdo_sqlite
 */
final class DbalToolBatchStoreTest extends TestCase
{
    private Connection $connection;
    private DbalToolBatchStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->store = new DbalToolBatchStore($this->connection);
    }

    public function testLoadReturnsNullForUnknownBatch(): void
    {
        $this->assertNull($this->store->load('run-1', 1, 'step-1'));
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $state = [
            'expected_order' => ['call-1' => 0, 'call-2' => 1],
            'call_data' => [
                'call-1' => ['toolCallId' => 'call-1', 'toolName' => 'read'],
                'call-2' => ['toolCallId' => 'call-2', 'toolName' => 'write'],
            ],
            'pending_queue' => ['call-1', 'call-2'],
            'in_flight' => [],
            'result_data' => [],
            'finalized' => false,
            'max_parallelism' => 4,
        ];

        $this->store->save('run-1', 1, 'step-1', $state);
        $loaded = $this->store->load('run-1', 1, 'step-1');

        $this->assertIsArray($loaded);
        $this->assertSame($state, $loaded);
    }

    public function testSaveOverwritesExistingBatch(): void
    {
        $this->store->save('run-1', 1, 'step-1', ['expected_order' => ['call-1' => 0], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 1]);
        $this->store->save('run-1', 1, 'step-1', ['expected_order' => ['call-1' => 0, 'call-2' => 1], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 2]);

        $loaded = $this->store->load('run-1', 1, 'step-1');
        $this->assertSame(2, $loaded['max_parallelism']);
    }

    public function testDeleteRemovesBatch(): void
    {
        $state = ['expected_order' => ['call-1' => 0], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 1];
        $this->store->save('run-1', 1, 'step-1', $state);
        $this->store->delete('run-1', 1, 'step-1');

        $this->assertNull($this->store->load('run-1', 1, 'step-1'));
    }

    public function testDeleteDoesNotAffectOtherBatches(): void
    {
        $this->store->save('run-1', 1, 'step-1', ['expected_order' => ['call-1' => 0], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 1]);
        $this->store->save('run-1', 1, 'step-2', ['expected_order' => ['call-2' => 0], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 2]);

        $this->store->delete('run-1', 1, 'step-1');

        $this->assertNull($this->store->load('run-1', 1, 'step-1'));
        $this->assertIsArray($this->store->load('run-1', 1, 'step-2'));
    }

    public function testTableIsCreatedLazily(): void
    {
        // Should not throw despite no table pre-creation
        $state = ['expected_order' => ['call-1' => 0], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 1];
        $this->store->save('run-1', 1, 'step-1', $state);
        $loaded = $this->store->load('run-1', 1, 'step-1');
        $this->assertIsArray($loaded);
    }

    public function testIsolationOfDifferentRunTurnAndStep(): void
    {
        $stateA = ['expected_order' => ['call-1' => 0], 'call_data' => [], 'pending_queue' => ['call-1'], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 1];
        $stateB = ['expected_order' => ['call-2' => 0], 'call_data' => [], 'pending_queue' => ['call-2'], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 2];

        $this->store->save('run-1', 1, 'step-1', $stateA);
        $this->store->save('run-1', 2, 'step-1', $stateB);
        $this->store->save('run-2', 1, 'step-1', $stateB);

        $this->assertSame(1, $this->store->load('run-1', 1, 'step-1')['max_parallelism']);
        $this->assertSame(2, $this->store->load('run-1', 2, 'step-1')['max_parallelism']);
        $this->assertSame(2, $this->store->load('run-2', 1, 'step-1')['max_parallelism']);
    }
}
