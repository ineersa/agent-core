<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Session\EventLogLastSequenceException;
use Ineersa\CodingAgent\Session\EventLogMaxSeqReader;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class EventLogMaxSeqReaderTest extends TestCase
{
    private string $dir;
    private EventLogMaxSeqReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = TestDirectoryIsolation::createOsTempDir('event-log-max-seq');
        $this->reader = new EventLogMaxSeqReader();
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
        $this->assertSame(0, $this->reader->readMaxSeqLocked($path));
    }

    public function testOutOfOrderLinesReturnHighestSeqNotLastLine(): void
    {
        $path = $this->dir.'/out-of-order.jsonl';
        file_put_contents(
            $path,
            '{"seq":1,"type":"a"}'."\n".
            '{"seq":2,"type":"b"}'."\n".
            '{"seq":5,"type":"c"}'."\n".
            '{"seq":3,"type":"d"}'."\n",
        );
        $this->assertSame(5, $this->reader->readMaxSeqLocked($path));
    }

    public function testInvalidJsonThrows(): void
    {
        $path = $this->dir.'/bad.jsonl';
        file_put_contents($path, '{"seq":1}'."\n".'not-json'."\n");
        $this->expectException(EventLogLastSequenceException::class);
        $this->reader->readMaxSeqLocked($path);
    }
}
