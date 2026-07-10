<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Session\EventLogLastSeqReader;
use Ineersa\CodingAgent\Session\EventLogLastSequenceException;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class EventLogLastSeqReaderTest extends TestCase
{
    private string $dir;
    private EventLogLastSeqReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = TestDirectoryIsolation::createOsTempDir('event-log-last-seq');
        $this->reader = new EventLogLastSeqReader();
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->dir);
        parent::tearDown();
    }

    public function testEmptyFileReturnsZero(): void
    {
        $path = $this->dir.'/empty.jsonl';
        touch($path);
        $this->assertSame(0, $this->reader->readLastSeqLocked($path));
    }

    public function testTrailingNewlineUsesLastEventLine(): void
    {
        $path = $this->dir.'/trail.jsonl';
        file_put_contents($path, '{"seq":1,"type":"a"}'."\n".'{"seq":42,"type":"b"}'."\n\n");
        $this->assertSame(42, $this->reader->readLastSeqLocked($path));
    }

    public function testLargeFileReadsOnlyTail(): void
    {
        $path = $this->dir.'/large.jsonl';
        $lines = [];
        for ($i = 1; $i <= 500; ++$i) {
            $lines[] = json_encode(['seq' => $i, 'type' => 'tick'], \JSON_THROW_ON_ERROR);
        }
        file_put_contents($path, implode("\n", $lines)."\n");
        $this->assertSame(500, $this->reader->readLastSeqLocked($path));
    }

    public function testInvalidJsonOnLastLineThrows(): void
    {
        $path = $this->dir.'/bad.jsonl';
        file_put_contents($path, '{"seq":1}'."\n".'not-json'."\n");
        $this->expectException(EventLogLastSequenceException::class);
        $this->reader->readLastSeqLocked($path);
    }

    public function testMissingSeqOnLastLineThrows(): void
    {
        $path = $this->dir.'/no-seq.jsonl';
        file_put_contents($path, '{"type":"agent_end"}'."\n");
        $this->expectException(EventLogLastSequenceException::class);
        $this->reader->readLastSeqLocked($path);
    }
}
