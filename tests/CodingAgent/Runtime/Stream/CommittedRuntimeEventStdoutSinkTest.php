<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\CommittedRuntimeEventStdoutSink;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Stream\CommittedRuntimeEventStdoutSink
 */
final class CommittedRuntimeEventStdoutSinkTest extends TestCase
{
    public function testEmitNoopsWhenStdoutIsNotPipe(): void
    {
        $logger = new TestLogger();
        $sink = new CommittedRuntimeEventStdoutSink($logger);

        $sink->emit(new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'run-a', 3, []));

        $this->assertSame([], $logger->records);
    }
}
