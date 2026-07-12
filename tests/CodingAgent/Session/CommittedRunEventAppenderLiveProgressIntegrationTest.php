<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareEventStore;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\StreamingCommittedRuntimeEventStore;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Session\SessionRunStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;

/**
 * Regression: CommittedRunEventAppender must append through EventStoreInterface
 * (StreamingCommittedRuntimeEventStore), not ChildAwareEventStore directly.
 *
 * Direct ChildAware wiring persists tool_execution_update but never emits mapped
 * RuntimeEvents for the controller/TUI live path.
 */
final class CommittedRunEventAppenderLiveProgressIntegrationTest extends PerMethodIsolatedKernelTestCase
{
    private RecordingRuntimeEventSink $recordingSink;

    public function testAppendSubagentProgressPersistsAndEmitsMappedRuntimeEvent(): void
    {
        $runId = 'parent-live-progress-'.bin2hex(random_bytes(4));
        $toolCallId = 'call_subagent_live_001';

        /** @var SessionRunStore $parentRunStore */
        $parentRunStore = self::getContainer()->get(SessionRunStore::class);
        $parentRunStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Running,
            version: 0,
            turnNo: 1,
            lastSeq: 0,
        ), 0);

        $resolvedEventStore = self::getContainer()->get(EventStoreInterface::class);
        $this->assertInstanceOf(
            StreamingCommittedRuntimeEventStore::class,
            $resolvedEventStore,
            'EventStoreInterface must resolve to the streaming decorator in the live progress path',
        );

        /** @var CommittedRunEventAppender $appender */
        $appender = self::getContainer()->get(CommittedRunEventAppender::class);

        $progress = [
            'mode' => 'parallel',
            'status' => 'running',
            'agent_name' => 'scout',
            'artifact_id' => 'agent_live_regression',
            'agent_run_id' => $runId.'_child',
            'completed' => 1,
            'total' => 3,
        ];

        $persisted = $appender->append(new RunEvent(
            runId: $runId,
            seq: 0,
            turnNo: 1,
            type: RunEventTypeEnum::ToolExecutionUpdate->value,
            payload: [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'subagent',
                'delta' => '',
                'order_index' => 0,
                'subagent_progress' => $progress,
            ],
        ));

        $this->assertGreaterThan(0, $persisted->seq);
        $this->assertSame($runId, $persisted->runId);
        $this->assertSame(RunEventTypeEnum::ToolExecutionUpdate->value, $persisted->type);

        $onDisk = $resolvedEventStore->allFor($runId);
        $this->assertCount(1, $onDisk);
        $this->assertSame($persisted->seq, $onDisk[0]->seq);
        $this->assertArrayHasKey('subagent_progress', $onDisk[0]->payload);

        $this->assertCount(1, $this->recordingSink->emitted);
        $runtime = $this->recordingSink->emitted[0];
        $this->assertInstanceOf(RuntimeEvent::class, $runtime);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionOutputDelta->value, $runtime->type);
        $this->assertSame($runId, $runtime->runId);
        $this->assertSame($persisted->seq, $runtime->seq);
        $this->assertSame($toolCallId, $runtime->payload['tool_call_id'] ?? null);
        $this->assertSame('subagent', $runtime->payload['tool_name'] ?? null);
        $this->assertIsArray($runtime->payload['subagent_progress'] ?? null);
        $this->assertSame('scout', $runtime->payload['subagent_progress']['agent_name'] ?? null);
    }

    protected function afterKernelBoot(): void
    {
        $this->recordingSink = new RecordingRuntimeEventSink();

        $container = self::getContainer();
        $inner = $container->get(ChildAwareEventStore::class);
        $mapper = $container->get(RuntimeEventMapper::class);
        $streaming = new StreamingCommittedRuntimeEventStore(
            $inner,
            $mapper,
            $this->recordingSink,
            true,
        );
        // Both EventStoreInterface and EventStoreInterface alias this concrete
        // service; replace once so CommittedRunEventAppender and generic callers share the recording sink.
        $container->set(StreamingCommittedRuntimeEventStore::class, $streaming);
    }
}

/**
 * @internal
 */
final class RecordingRuntimeEventSink implements RuntimeEventSinkInterface
{
    /** @var list<RuntimeEvent> */
    public array $emitted = [];

    public function emit(RuntimeEvent $event): void
    {
        $this->emitted[] = $event;
    }
}
