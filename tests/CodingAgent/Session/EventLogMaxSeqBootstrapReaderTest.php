<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use PHPUnit\Framework\TestCase;

final class EventLogMaxSeqBootstrapReaderTest extends TestCase
{
    public function testReadMaxSeqReturnsZeroForMissingFile(): void
    {
        $reader = new EventLogMaxSeqBootstrapReader();
        $this->assertSame(0, $reader->readMaxSeq('/tmp/does-not-exist-'.bin2hex(random_bytes(4)).'.jsonl'));
    }

    public function testReadMaxSeqIgnoresOutOfOrderDiskLines(): void
    {
        $path = sys_get_temp_dir().'/bootstrap-'.bin2hex(random_bytes(4)).'.jsonl';
        file_put_contents($path, '{"seq":1}
{"seq":2}
{"seq":5}
{"seq":3}
');

        $reader = new EventLogMaxSeqBootstrapReader();
        $this->assertSame(5, $reader->readMaxSeq($path));
        @unlink($path);
    }
}
