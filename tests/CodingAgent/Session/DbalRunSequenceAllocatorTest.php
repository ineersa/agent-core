<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\DBAL\Connection;
use Ineersa\CodingAgent\Session\DbalRunSequenceAllocator;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * @requires extension pdo_sqlite
 */
final class DbalRunSequenceAllocatorTest extends IsolatedKernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection->executeStatement('DELETE FROM hatfield_run_sequence');
    }

    public function testAllocateNextReturnsIncreasingValuesForSameRun(): void
    {
        $allocator = static::getContainer()->get(DbalRunSequenceAllocator::class);

        $first = $allocator->allocateNext('run-a');
        $second = $allocator->allocateNext('run-a');

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
    }

    public function testAllocateBlockReturnsContiguousRange(): void
    {
        $allocator = static::getContainer()->get(DbalRunSequenceAllocator::class);

        $block = $allocator->allocateBlock('run-b', 3);

        $this->assertSame([1, 2, 3], $block);
        $this->assertSame(4, $allocator->allocateNext('run-b'));
    }

    public function testBootstrapUsesExistingMaxSeqOnlyOnce(): void
    {
        $allocator = static::getContainer()->get(DbalRunSequenceAllocator::class);

        $first = $allocator->allocateNext('run-c', static fn (): int => 7);
        $second = $allocator->allocateNext('run-c', static fn (): int => 99);

        $this->assertSame(8, $first);
        $this->assertSame(9, $second);
    }
}
