<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
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
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Application\SessionInitializer;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

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

    public function testApplierAndSessionInitializerReplayProduceMatchingState(): void
    {
        $runId = 'equiv-'.bin2hex(random_bytes(4));
        $this->projectDir = sys_get_temp_dir().'/hatfield-equiv-'.getmypid();
        mkdir($this->projectDir.'/.hatfield/sessions/'.$runId, 0777, true);

        $events = $this->canonicalFixtureLines($runId);
        file_put_contents($this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl', implode('', $events));

        // The initializer must replay through its own session-scoped applier
        // (fresh projector), independent of the direct-apply applier below.
        $initializerApplier = $this->buildApplier();
        $initializer = $this->buildInitializer();
        $resumeState = new TuiSessionState($runId, true);
        $resumeBlocks = $initializer->buildInitialTranscript($resumeState, $initializerApplier);

        $applierState = new TuiSessionState($runId, true);
        $applier = $this->buildApplier();
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())));
        $store = $this->buildEventStore();

        foreach ($store->allFor($runId) as $runEvent) {
            $runtimeEvent = $mapper->toRuntimeEvent($runEvent);
            if (null === $runtimeEvent) {
                continue;
            }
            $applier->apply($applierState, $runtimeEvent, replayMode: true);
        }
        $applierBlocks = $applier->projectedBlocks();

        $this->assertSame($resumeState->activity, $applierState->activity);
        $this->assertSame($resumeState->usage->inputTokens, $applierState->usage->inputTokens);
        $this->assertSame($resumeState->usage->outputTokens, $applierState->usage->outputTokens);
        $this->assertSame($resumeState->usage->latestInputTokens, $applierState->usage->latestInputTokens);
        $this->assertSame(0.0, $applierState->usage->turnStartTime, 'Replay contract: no wall-clock t/s timing');
        $this->assertSame($resumeState->queuedUserMessages, $applierState->queuedUserMessages);

        $this->assertSame(
            array_map(static fn ($b) => [$b->kind->value, $b->text, $b->streaming], $resumeBlocks),
            array_map(static fn ($b) => [$b->kind->value, $b->text, $b->streaming], $applierBlocks),
        );

        $userCount = \count(array_filter($resumeBlocks, static fn ($b) => TranscriptBlockKindEnum::UserMessage === $b->kind));
        $this->assertGreaterThanOrEqual(1, $userCount);
        $this->assertSame(RunActivityStateEnum::Cancelled, $resumeState->activity);
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

    /** @return list<string> */
    private function canonicalFixtureLines(string $runId): array
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        // Canonical resume needs TurnAdvanced so HistoryProjector retains tip=1.
        // Without it, fail-closed resume uses position 0 and drops turn content/usage.
        $rows = [
            ['seq' => 1, 'turn_no' => 0, 'type' => 'run_started', 'payload' => ['step_id' => 's1', 'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Resume me']]]]]]],
            ['seq' => 2, 'turn_no' => 1, 'type' => 'turn_advanced', 'payload' => ['turn_no' => 1]],
            ['seq' => 3, 'turn_no' => 1, 'type' => 'history_position_set', 'payload' => ['position_turn_no' => 1, 'reason' => 'continue']],
            ['seq' => 4, 'turn_no' => 1, 'type' => 'llm_step_completed', 'payload' => ['step_id' => 's2', 'assistant_message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_sub_1', 'name' => 'subagent', 'arguments' => ['task' => 'x']]]], 'usage' => ['input_tokens' => 12, 'output_tokens' => 4]]],
            ['seq' => 5, 'turn_no' => 1, 'type' => 'tool_execution_start', 'payload' => ['tool_call_id' => 'call_sub_1', 'tool_name' => 'subagent', 'order_index' => 0]],
            ['seq' => 6, 'turn_no' => 1, 'type' => 'tool_execution_update', 'payload' => ['tool_call_id' => 'call_sub_1', 'tool_name' => 'subagent', 'delta' => '', 'subagent_progress' => ['mode' => 'single', 'status' => 'running', 'agent_name' => 'scout', 'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'task', 'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium']]],
            ['seq' => 7, 'turn_no' => 1, 'type' => 'tool_execution_end', 'payload' => [
                'tool_result' => [
                    'run_id' => $runId,
                    'turn_no' => 1,
                    'step_id' => 's2',
                    'attempt' => 1,
                    'idempotency_key' => 'result-call_sub_1',
                    'tool_call_id' => 'call_sub_1',
                    'order_index' => 0,
                    'result' => ['tool_name' => 'subagent', 'content' => [['type' => 'text', 'text' => 'Final subagent handoff text']]],
                    'is_error' => false,
                    'error' => null,
                    'pending_human_input' => null,
                ],
            ]],
            ['seq' => 8, 'turn_no' => 1, 'type' => 'agent_command_applied', 'payload' => ['kind' => 'cancel']],
            ['seq' => 9, 'turn_no' => 1, 'type' => 'agent_end', 'payload' => ['reason' => 'cancelled']],
        ];
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = json_encode([
                'schema_version' => '1.0',
                'run_id' => $runId,
                'seq' => $row['seq'],
                'turn_no' => $row['turn_no'],
                'type' => $row['type'],
                'payload' => $row['payload'],
                'ts' => $now,
            ], \JSON_THROW_ON_ERROR)."\n";
        }

        return $lines;
    }

    private function buildApplier(): TuiRuntimeEventApplier
    {
        return new TuiRuntimeEventApplier($this->buildProjector(), SubagentProgressSerializerTestSupport::denormalizer());
    }

    private function buildInitializer(): SessionInitializer
    {
        $projector = $this->buildProjector();
        $appConfig = new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $this->projectDir);
        $sessionStore = new HatfieldSessionStore($appConfig, $this->createStub(\Doctrine\ORM\EntityManagerInterface::class), new EventDispatcher());
        $eventStore = $this->buildEventStore();
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())));

        return new SessionInitializer(
            sessionStore: $sessionStore,
            eventStore: $eventStore,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            historyProvider: new \Ineersa\CodingAgent\Session\SessionHistoryProvider($eventStore, new \Ineersa\CodingAgent\Session\History\HistoryProjector()),
            sessionTranscriptProvider: new \Ineersa\CodingAgent\Session\SessionTranscriptProvider(
                eventStore: $eventStore,
                replayFilter: new \Ineersa\CodingAgent\Session\History\HistoryReplayFilter(new \Ineersa\CodingAgent\Session\History\HistoryProjector()),
                eventMapper: $mapper,
                transcriptProjector: $projector,
            )
        );
    }

    private function buildEventStore(): SessionRunEventStore
    {
        $appConfig = new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $this->projectDir);
        $sessionStore = new HatfieldSessionStore($appConfig, $this->createStub(\Doctrine\ORM\EntityManagerInterface::class), new EventDispatcher());

        return new SessionRunEventStore(
            hatfieldSessionStore: $sessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );
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
