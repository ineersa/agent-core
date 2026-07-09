<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\SequencedEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\CodingAgent\Session\SequencedRunEventAppender;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class SequencedRunEventAppenderTest extends TestCase
{
    public function testAppendSyncsParentLastSeq(): void
    {
        $runId = 'parent-1';
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(runId: $runId, status: RunStatus::Running, version: 1, lastSeq: 3), 0);

        $eventStore = $this->createMock(SequencedEventStoreInterface::class);
        $eventStore->method('appendWithNextSeq')->willReturn(new RunEvent($runId, 4, 1, 'tool_execution_update', []));

        $appender = new SequencedRunEventAppender($eventStore, $runStore, new NullLogger());
        $appender->append(new RunEvent($runId, 0, 1, 'tool_execution_update', []));

        $state = $runStore->get($runId);
        $this->assertNotNull($state);
        $this->assertSame(4, $state->lastSeq);
    }
}
