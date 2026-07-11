<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\SequencedEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use PHPUnit\Framework\TestCase;

final class RunEventStoreSequencingTest extends TestCase
{
    public function testAppendWithNextSeqAssignsMonotonicSeqWhenInputSeqIsZero(): void
    {
        $store = new RunEventStore();
        $runId = 'run-mem';
        $first = $store->appendWithNextSeq(new RunEvent($runId, 0, 0, 'run_started', []));
        $second = $store->appendWithNextSeq(new RunEvent($runId, 0, 1, 'turn_advanced', []));

        $this->assertSame(1, $first->seq);
        $this->assertSame(2, $second->seq);
        $this->assertInstanceOf(SequencedEventStoreInterface::class, $store);
    }
}
