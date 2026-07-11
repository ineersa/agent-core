<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class EventLogMaxSeqBootstrapReaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('max-seq-bootstrap');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testMissingLogReturnsZero(): void
    {
        $reader = new EventLogMaxSeqBootstrapReader();
        $this->assertSame(0, $reader->readMaxSeq($this->tmpDir.'/missing.jsonl'));
    }

    public function testOutOfOrderPhysicalLinesReturnMaxSeq(): void
    {
        $path = $this->tmpDir.'/events.jsonl';
        file_put_contents($path, '{"seq":2}'."\n".'{"seq":7}'."\n".'{"seq":4}'."\n");
        $reader = new EventLogMaxSeqBootstrapReader();
        $this->assertSame(7, $reader->readMaxSeq($path));
    }
}
