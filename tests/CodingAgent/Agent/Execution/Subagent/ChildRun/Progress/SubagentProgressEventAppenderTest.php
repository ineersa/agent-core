<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Progress;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SubagentProgressEventAppenderTest extends TestCase
{
    public function testProcessModeEmitsNonTerminalProgressWithoutCanonicalAppendOrStateCas(): void
    {
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');
        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->expects($this->never())->method('get');
        $runStore->expects($this->never())->method('compareAndSwap');
        $sink = $this->createMock(RuntimeEventSinkInterface::class);
        $sink->expects($this->once())
            ->method('emit')
            ->with($this->callback(function (RuntimeEvent $event): bool {
                $this->assertSame(RuntimeEventTypeEnum::ToolExecutionOutputDelta->value, $event->type);
                $this->assertSame(0, $event->seq);
                $this->assertSame('parent-run', $event->runId);
                $this->assertSame('running', $event->payload['subagent_progress']['status']);

                return true;
            }));

        $appender = new SubagentProgressEventAppender(
            new CommittedRunEventAppender($eventStore, $runStore, new NullLogger()),
            SubagentProgressSerializerTestSupport::normalizer(),
            SubagentProgressSerializerTestSupport::validator(),
            $sink,
            new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher())),
            true,
        );
        /** @var SubagentProgressSnapshotInterface $progress */
        $progress = SubagentProgressSerializerTestSupport::denormalizer()->denormalize(
            SubagentProgressSerializerTestSupport::canonicalSingleWire(status: RunStatus::Running->value),
            SubagentProgressSnapshotInterface::class,
        );

        $event = $appender->append('parent-run', 2, 'call-1', 0, 'subagent', $progress);

        $this->assertSame(0, $event->seq);
    }

    public function testProcessModeKeepsTerminalProgressCanonicalForReplay(): void
    {
        $runId = 'parent-run';
        $eventStore = new InMemoryEventStore();
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(runId: $runId, status: RunStatus::Running, version: 1, model: 'test-model'), 0);
        $sink = $this->createMock(RuntimeEventSinkInterface::class);
        $sink->expects($this->never())->method('emit');

        $appender = new SubagentProgressEventAppender(
            new CommittedRunEventAppender($eventStore, $runStore, new NullLogger()),
            SubagentProgressSerializerTestSupport::normalizer(),
            SubagentProgressSerializerTestSupport::validator(),
            $sink,
            new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher())),
            true,
        );
        /** @var SubagentProgressSnapshotInterface $progress */
        $progress = SubagentProgressSerializerTestSupport::denormalizer()->denormalize(
            SubagentProgressSerializerTestSupport::canonicalSingleWire(status: RunStatus::Completed->value),
            SubagentProgressSnapshotInterface::class,
        );

        $persisted = $appender->append($runId, 2, 'call-1', 0, 'subagent', $progress);

        $this->assertSame(1, $persisted->seq);
        $this->assertSame('completed', $eventStore->allFor($runId)[0]->payload['subagent_progress']['status']);
        $this->assertSame(1, $runStore->get($runId)?->lastSeq);
    }

    public function testInProcessModeKeepsNonTerminalProgressCanonical(): void
    {
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('append')
            ->willReturn(new RunEvent('parent-run', 3, 2, 'tool_execution_update', []));
        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->expects($this->once())
            ->method('get')
            ->willReturn(new RunState(runId: 'parent-run', status: RunStatus::Running, version: 1, lastSeq: 2, model: 'test-model'));
        $runStore->expects($this->once())
            ->method('compareAndSwap')
            ->willReturn(true);
        $sink = $this->createMock(RuntimeEventSinkInterface::class);
        $sink->expects($this->never())->method('emit');

        $appender = new SubagentProgressEventAppender(
            new CommittedRunEventAppender($eventStore, $runStore, new NullLogger()),
            SubagentProgressSerializerTestSupport::normalizer(),
            SubagentProgressSerializerTestSupport::validator(),
            $sink,
            new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher())),
            false,
        );
        /** @var SubagentProgressSnapshotInterface $progress */
        $progress = SubagentProgressSerializerTestSupport::denormalizer()->denormalize(
            SubagentProgressSerializerTestSupport::canonicalSingleWire(status: RunStatus::WaitingHuman->value),
            SubagentProgressSnapshotInterface::class,
        );

        $persisted = $appender->append('parent-run', 2, 'call-1', 0, 'subagent', $progress);

        $this->assertSame(3, $persisted->seq);
    }
}
