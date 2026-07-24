<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class CommittedRunEventAppenderTest extends TestCase
{
    public function testAppendSyncsParentLastSeqFromPersistedEvent(): void
    {
        $runId = 'parent-1';
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(runId: $runId, status: RunStatus::Running, version: 1, lastSeq: 3, model: 'test-model'), 0);

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->method('append')->willReturn(new RunEvent($runId, 4, 1, 'tool_execution_update', []));

        $appender = new CommittedRunEventAppender($eventStore, $runStore, new NullLogger());
        $appender->append(new RunEvent($runId, 0, 1, 'tool_execution_update', []));

        $state = $runStore->get($runId);
        $this->assertNotNull($state);
        $this->assertSame(4, $state->lastSeq);
    }
}
