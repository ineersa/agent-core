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
        file_put_contents(
            $path,
            '{"schema_version":"1.0.0","run_id":"6","seq":2,"turn_no":1,"type":"x","payload":{},"ts":"2026-01-01T00:00:00+00:00"}'."\n"
            .'{"schema_version":"1.0.0","run_id":"6","seq":7,"turn_no":1,"type":"x","payload":{},"ts":"2026-01-01T00:00:00+00:00"}'."\n"
            .'{"schema_version":"1.0.0","run_id":"6","seq":4,"turn_no":1,"type":"x","payload":{},"ts":"2026-01-01T00:00:00+00:00"}'."\n",
        );
        $reader = new EventLogMaxSeqBootstrapReader();
        $this->assertSame(7, $reader->readMaxSeq($path));
    }

    public function testIgnoresMalformedWhitespaceAndNonPositiveSeq(): void
    {
        $path = $this->tmpDir.'/events.jsonl';
        file_put_contents(
            $path,
            '  {"schema_version":"1.0.0","run_id":"6","seq": 3,"turn_no":1,"type":"x","payload":{},"ts":"2026-01-01T00:00:00+00:00"}  '."\n"
            .'not json at all'."\n"
            .'{"schema_version":"1.0.0","run_id":"6","seq":0,"turn_no":1,"type":"x","payload":{},"ts":"2026-01-01T00:00:00+00:00"}'."\n"
            .'{"schema_version":"1.0.0","run_id":"6","seq":5,"turn_no":1,"type":"x","payload":{},"ts":"2026-01-01T00:00:00+00:00"}'."\n",
        );
        $reader = new EventLogMaxSeqBootstrapReader();
        $this->assertSame(5, $reader->readMaxSeq($path));
    }

    public function testIgnoresNestedPayloadSeqHigherThanTopLevel(): void
    {
        $path = $this->tmpDir.'/events.jsonl';
        file_put_contents(
            $path,
            '{"schema_version":"1.0.0","run_id":"6","seq":4,"turn_no":1,"type":"x","payload":{"seq":99,"turn_no":0},"ts":"2026-01-01T00:00:00+00:00"}'."\n",
        );
        $reader = new EventLogMaxSeqBootstrapReader();
        $this->assertSame(4, $reader->readMaxSeq($path));
    }

    public function testNestedPayloadSeqBeforeTopLevelOnSameLineCannotOvercount(): void
    {
        $path = $this->tmpDir.'/events.jsonl';
        file_put_contents(
            $path,
            '{"schema_version":"1.0.0","run_id":"6","seq":4,"turn_no":1,"type":"x","payload":{"seq":99},"ts":"2026-01-01T00:00:00+00:00"}'."\n",
        );
        $reader = new EventLogMaxSeqBootstrapReader();
        $this->assertSame(4, $reader->readMaxSeq($path));
    }
}
