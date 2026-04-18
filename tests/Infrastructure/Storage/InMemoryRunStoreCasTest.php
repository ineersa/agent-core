<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\Storage;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use PHPUnit\Framework\TestCase;

final class InMemoryRunStoreCasTest extends TestCase
{
    public function testCompareAndSwapRejectsStaleWriterAfterTakeover(): void
    {
        $store = new InMemoryRunStore();

        $initialState = new RunState(
            runId: 'run-cas-1',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 1,
        );
        self::assertTrue($store->compareAndSwap($initialState, expectedVersion: 0));

        // Writer B acquires lock after takeover and commits version=2.
        $writerBState = new RunState(
            runId: 'run-cas-1',
            status: RunStatus::Running,
            version: 2,
            turnNo: 2,
            lastSeq: 2,
        );
        self::assertTrue($store->compareAndSwap($writerBState, expectedVersion: 1));

        // Writer A still holds stale expectedVersion=1 and must be rejected.
        $writerAStaleState = new RunState(
            runId: 'run-cas-1',
            status: RunStatus::Running,
            version: 2,
            turnNo: 99,
            lastSeq: 99,
        );
        self::assertFalse($store->compareAndSwap($writerAStaleState, expectedVersion: 1));

        $currentState = $store->get('run-cas-1');
        self::assertNotNull($currentState);
        self::assertSame(2, $currentState->version);
        self::assertSame(2, $currentState->turnNo);
    }
}
