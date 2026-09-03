<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CancellationProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\HitlProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\RunLifecycleProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * @covers \Ineersa\Tui\Runtime\TuiRuntimeEventApplier
 */
final class TuiRuntimeEventApplierTest extends TestCase
{
    private string $projectDir = '';

    protected function tearDown(): void
    {
        if ('' !== $this->projectDir && is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
        parent::tearDown();
    }

    public function testRunHistoryPositionChangedClearsStaleQueuedUserMessages(): void
    {
        // Thesis: without clearing queuedUserMessages on RunHistoryPositionChanged, history select/resume
        // leaves discarded-tail ⏳ pending lines visible above the editor.
        $applier = $this->buildApplier();
        $state = new TuiSessionState('run-history-position', true);
        $state->queuedUserMessages = ['ik-discarded' => 'Want to test bash in parallel'];

        $applier->apply($state, new \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent(
            type: \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::RunHistoryPositionChanged->value,
            runId: 'run-history-position',
            seq: 10,
            payload: ['turn_no' => 2],
        ), replayMode: true);

        $this->assertSame([], $state->queuedUserMessages);
        $this->assertSame(RunActivityStateEnum::Idle, $state->activity);
    }

    public function testRunCancelledClearsPendingQueuedUserMessages(): void
    {
        // Thesis: cancel terminalizes the turn; still-queued commands must not linger as ⏳.
        $applier = $this->buildApplier();
        $state = new TuiSessionState('run-cancel', true);
        $state->queuedUserMessages = ['ik-pending' => 'queued during active run'];

        $applier->apply($state, new \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent(
            type: \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::RunCancelled->value,
            runId: 'run-cancel',
            seq: 5,
            payload: [],
        ), replayMode: true);

        $this->assertSame([], $state->queuedUserMessages);
        $this->assertSame(RunActivityStateEnum::Cancelled, $state->activity);
    }

    public function testPostCancelSeqZeroToolCallDoesNotAddGhostTranscriptBlock(): void
    {
        $applier = $this->buildApplier();
        $state = new TuiSessionState('run-ghost', true);

        $applier->apply($state, new \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent(
            type: \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::RunCancelled->value,
            runId: 'run-ghost',
            seq: 10,
            payload: ['reason' => 'cancelled'],
        ));
        $this->assertSame(RunActivityStateEnum::Cancelled, $state->activity);
        $applier->drainProjectedChanges();

        $applier->apply($state, new \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent(
            type: \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::ToolCallStarted->value,
            runId: 'run-ghost',
            seq: 0,
            payload: [
                'tool_call_id' => 'call_ghost',
                'tool_name' => 'bash',
            ],
        ));

        $this->assertSame(RunActivityStateEnum::Cancelled, $state->activity);
        $changes = $applier->drainProjectedChanges();
        $this->assertSame([], $changes->upserts);
        $this->assertSame([], $changes->removals);
    }

    public function testIdleFollowUpQueuedEventDoesNotPopulatePendingQueue(): void
    {
        // Thesis: idle follow_up should not emit user.message_queued (no ⏳ flicker).
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())));
        $runEvent = new RunEvent(
            runId: 'run-fu',
            seq: 2,
            turnNo: 1,
            type: 'agent_command_queued',
            payload: [
                'kind' => 'follow_up',
                'idempotency_key' => 'ik-follow',
                'text' => 'Next prompt',
            ],
        );

        $this->assertNull($mapper->toRuntimeEvent($runEvent));
    }

    public function testPresentMalformedSubagentProgressPropagates(): void
    {
        $applier = $this->buildApplier();
        $state = new TuiSessionState('run-progress-bad', true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('subagent_progress payload must be an array when present.');
        $applier->apply($state, new \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent(
            type: \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: 'run-progress-bad',
            seq: 1,
            payload: [
                'tool_call_id' => 'call_bad',
                'tool_name' => 'subagent',
                'subagent_progress' => 'nope',
            ],
        ));
    }

    public function testPresentCanonicalProgressIngestsLiveCatalog(): void
    {
        $applier = $this->buildApplier();
        $state = new TuiSessionState('run-progress-ok', true);

        $applier->apply($state, new \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent(
            type: \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: 'run-progress-ok',
            seq: 1,
            payload: [
                'tool_call_id' => 'call_ok',
                'tool_name' => 'subagent',
                'subagent_progress' => SubagentProgressSerializerTestSupport::canonicalSingleWire(
                    agentName: 'scout',
                    artifactId: 'agent_live',
                    agentRunId: 'child-live-1',
                    taskSummary: 'Live catalog',
                ),
            ],
        ));

        $items = $state->subagentLiveCatalog->all();
        $this->assertCount(1, $items);
        $this->assertSame('agent_live', $items[0]->artifactId);
        $this->assertSame('scout', $items[0]->agentName);
        $this->assertSame('test/model', $items[0]->model);
        $this->assertSame('medium', $items[0]->reasoning);
    }

    private function buildApplier(): TuiRuntimeEventApplier
    {
        return new TuiRuntimeEventApplier($this->buildProjector(), SubagentProgressSerializerTestSupport::denormalizer());
    }

    private function buildProjector(): TranscriptProjector
    {
        $dispatcher = new EventDispatcher();
        $state = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new ToolProjectionSubscriber(new SubagentProgressDisplayFormatter(), SubagentProgressSerializerTestSupport::denormalizer()));
        $dispatcher->addSubscriber(new HitlProjectionSubscriber());
        $dispatcher->addSubscriber(new CancellationProjectionSubscriber());
        $dispatcher->addSubscriber(new RunLifecycleProjectionSubscriber());

        return new TranscriptProjector($dispatcher, $state);
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
