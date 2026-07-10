<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class FileRunSequenceAllocatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('file-seq-alloc');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testMissingCounterAndMissingLogAllocatesOne(): void
    {
        $counter = $this->tmpDir.'/sequence.cursor';
        $allocator = new FileRunSequenceAllocator();

        $this->assertSame(1, $allocator->allocateNext($counter));
        $this->assertSame("1\n", file_get_contents($counter));
        $this->assertSame(2, $allocator->allocateNext($counter));
    }

    public function testBootstrapFromExistingLogMaxFiveAllocatesSix(): void
    {
        $dir = $this->tmpDir.'/run';
        mkdir($dir, 0777, true);
        $events = $dir.'/events.jsonl';
        file_put_contents($events, '{"seq":1}'."\n".'{"seq":5}'."\n".'{"seq":3}'."\n");
        $counter = FileRunSequenceAllocator::counterPathForEventsLog($events);
        $allocator = new FileRunSequenceAllocator();

        $this->assertSame(6, $allocator->allocateNext($counter, static fn (): int => 5));
        $this->assertSame("6\n", file_get_contents($counter));
    }

    public function testExistingCounterTenDoesNotCallBootstrap(): void
    {
        $counter = $this->tmpDir.'/sequence.cursor';
        file_put_contents($counter, "10\n");
        $allocator = new FileRunSequenceAllocator();
        $bootstrapCalls = 0;

        $next = $allocator->allocateNext($counter, static function () use (&$bootstrapCalls): int {
            ++$bootstrapCalls;

            return 99;
        });

        $this->assertSame(11, $next);
        $this->assertSame(0, $bootstrapCalls);
        $this->assertSame("11\n", file_get_contents($counter));
    }

    public function testAllocateBlockWritesFinalHighWater(): void
    {
        $counter = $this->tmpDir.'/sequence.cursor';
        file_put_contents($counter, "10\n");
        $allocator = new FileRunSequenceAllocator();

        $block = $allocator->allocateBlock($counter, 3);

        $this->assertSame([11, 12, 13], $block);
        $this->assertSame("13\n", file_get_contents($counter));
    }

    public function testCorruptCounterThrows(): void
    {
        $counter = $this->tmpDir.'/sequence.cursor';
        file_put_contents($counter, "not-a-number\n");
        $allocator = new FileRunSequenceAllocator();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('corrupt');
        $allocator->allocateNext($counter);
    }
}
