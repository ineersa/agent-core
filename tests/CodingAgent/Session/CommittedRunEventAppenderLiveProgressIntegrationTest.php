<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareEventStore;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\StreamingCommittedRuntimeEventStore;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;

/**
 * Regression: committed subagent progress must append through the streaming
 * canonical store. Bypassing that decorator would persist the update but never
 * make the mapped progress event visible to the controller/TUI runtime path.
 */
final class CommittedRunEventAppenderLiveProgressIntegrationTest extends PerMethodIsolatedKernelTestCase
{
    private RecordingRuntimeEventSink $recordingSink;

    public function testAppendSubagentProgressPersistsCanonicallyAndEmitsMappedRuntimeEvent(): void
    {
        $runId = 'parent-live-progress-'.bin2hex(random_bytes(4));
        $toolCallId = 'call_subagent_live_001';

        /** @var EventStoreInterface $eventStore */
        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        $this->assertInstanceOf(
            StreamingCommittedRuntimeEventStore::class,
            $eventStore,
            'EventStoreInterface must resolve to the streaming decorator in the live progress path',
        );

        // A parent run is represented only by canonical evidence; no snapshot
        // state is seeded for this side-event append path.
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 0,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: ['payload' => ['messages' => []]],
        ));
        $this->recordingSink->emitted = [];

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

        $this->assertSame(2, $persisted->seq);
        $this->assertSame($runId, $persisted->runId);
        $this->assertSame(RunEventTypeEnum::ToolExecutionUpdate->value, $persisted->type);

        $canonical = $eventStore->allFor($runId);
        $this->assertCount(2, $canonical);
        $this->assertSame($persisted->seq, $canonical[1]->seq);
        $this->assertSame($progress, $canonical[1]->payload['subagent_progress']);

        $this->assertCount(1, $this->recordingSink->emitted);
        $runtime = $this->recordingSink->emitted[0];
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionOutputDelta->value, $runtime->type);
        $this->assertSame($runId, $runtime->runId);
        $this->assertSame($persisted->seq, $runtime->seq);
        $this->assertSame($toolCallId, $runtime->payload['tool_call_id'] ?? null);
        $this->assertSame('subagent', $runtime->payload['tool_name'] ?? null);
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
        $container->set(StreamingCommittedRuntimeEventStore::class, $streaming);
    }
}

/** @internal */
final class RecordingRuntimeEventSink implements RuntimeEventSinkInterface
{
    /** @var list<RuntimeEvent> */
    public array $emitted = [];

    public function emit(RuntimeEvent $event): void
    {
        $this->emitted[] = $event;
    }
}
