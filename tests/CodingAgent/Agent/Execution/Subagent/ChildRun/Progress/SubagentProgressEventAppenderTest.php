<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Progress;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
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
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SubagentProgressEventAppenderTest extends TestCase
{
    public function testProcessModeEmitsNonTerminalProgressWithoutCanonicalAppend(): void
    {
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');
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

        $appender = $this->appender($eventStore, $sink, true);
        $event = $appender->append('parent-run', 2, 'call-1', 0, 'subagent', $this->progress(RunStatus::Running));

        $this->assertSame(0, $event->seq);
    }

    public function testProcessModeKeepsTerminalProgressCanonicalForReplay(): void
    {
        $runId = 'parent-run';
        $eventStore = new InMemoryEventStore();
        $sink = $this->createMock(RuntimeEventSinkInterface::class);
        $sink->expects($this->never())->method('emit');

        $persisted = $this->appender($eventStore, $sink, true)
            ->append($runId, 2, 'call-1', 0, 'subagent', $this->progress(RunStatus::Completed));

        $this->assertSame(1, $persisted->seq);
        $this->assertSame('completed', $eventStore->allFor($runId)[0]->payload['subagent_progress']['status']);
    }

    public function testInProcessModeKeepsNonTerminalProgressCanonical(): void
    {
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('append')
            ->willReturn(new RunEvent('parent-run', 3, 2, 'tool_execution_update', []));
        $sink = $this->createMock(RuntimeEventSinkInterface::class);
        $sink->expects($this->never())->method('emit');

        $persisted = $this->appender($eventStore, $sink, false)
            ->append('parent-run', 2, 'call-1', 0, 'subagent', $this->progress(RunStatus::WaitingHuman));

        $this->assertSame(3, $persisted->seq);
    }

    private function appender(EventStoreInterface $eventStore, RuntimeEventSinkInterface $sink, bool $streamCommittedEventsToStdout): SubagentProgressEventAppender
    {
        return new SubagentProgressEventAppender(
            new CommittedRunEventAppender($eventStore, new TestMessageBus()),
            SubagentProgressSerializerTestSupport::normalizer(),
            SubagentProgressSerializerTestSupport::validator(),
            $sink,
            new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher())),
            $streamCommittedEventsToStdout,
        );
    }

    private function progress(RunStatus $status): SubagentProgressSnapshotInterface
    {
        /** @var SubagentProgressSnapshotInterface $progress */
        $progress = SubagentProgressSerializerTestSupport::denormalizer()->denormalize(
            SubagentProgressSerializerTestSupport::canonicalSingleWire(status: $status->value),
            SubagentProgressSnapshotInterface::class,
        );

        return $progress;
    }
}
