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

    public function testIgnoresMalformedWhitespaceAndNonPositiveSeq(): void
    {
        $path = $this->tmpDir.'/events.jsonl';
        file_put_contents(
            $path,
            '  {"seq": 3}  '."\n"
            .'not json at all'."\n"
            .'{"seq":0}'."\n"
            .'{"seq":5}'."\n",
        );
        $reader = new EventLogMaxSeqBootstrapReader();
        $this->assertSame(5, $reader->readMaxSeq($path));
    }

    public function testIgnoresNestedPayloadSeqHigherThanTopLevel(): void
    {
        $path = $this->tmpDir.'/events.jsonl';
        file_put_contents(
            $path,
            '{"seq":4,"payload":{"note":"seq":99}}'.'
',
        );
        $reader = new EventLogMaxSeqBootstrapReader();
        $this->assertSame(4, $reader->readMaxSeq($path));
    }
}
