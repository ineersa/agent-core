<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BashBackgroundCandidateDTO;
use Ineersa\CodingAgent\Runtime\Contract\RepairResult;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveMainReturn;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\SessionEventsExportServiceFactory;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** @covers \Ineersa\Tui\Picker\SubagentLivePickerController */
final class SubagentLivePickerObservationLifecycleTest extends TestCase
{
    public function testEnterLiveViewBeginsObservationBeforeSnapshotReplay(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'obs-before-snapshot');
        $state = new TuiSessionState('obs-before-snapshot');
        $child = new SubagentLiveChildDTO('child-run-1', 'art-1', 'scout', SubagentLiveStatusEnum::Completed, 'task', 1, 'deepseek/deepseek-v4-flash', 'medium');
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($state->subagentLiveCatalog, new RuntimeEvent(
            type: 'tool_execution.output_delta',
            runId: 'obs-before-snapshot',
            seq: 1,
            payload: [
                'tool_call_id' => 'tc1',
                'tool_name' => 'subagent',
                'delta' => '',
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'completed',
                    'agent_name' => 'scout',
                    'artifact_id' => 'art-1',
                    'agent_run_id' => 'child-run-1',
                    'task_summary' => 'task',
                    'model' => 'deepseek/deepseek-v4-flash',
                    'reasoning' => 'medium', ],
            ],
        ));

        $spy = new ObservingSpyClient();
        $snapshotProvider = new FixedChildRunTranscriptSnapshotProvider(
            new ChildRunTranscriptSnapshotDTO([], [], 0),
        );

        $questionCoordinator = new QuestionCoordinator();
        $questionController = new QuestionController($questionCoordinator, $harness->screen());
        $picker = new SubagentLivePickerController(
            $harness->tui(),
            $harness->screen(),
            $state,
            $spy,
            new SubagentLiveChildViewPoller(new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()), new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer()),
            $snapshotProvider,
            $this->createStub(ChildAgentEventsPathResolverInterface::class),
            SessionEventsExportServiceFactory::create(),
            $questionCoordinator,
            $questionController,
        );

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'enterLiveView');
        $method->invoke($picker, $child, $state, $harness->screen());

        $this->assertSame(['begin:child-run-1'], $spy->calls);
        $this->assertTrue($state->subagentLiveView->active);
    }

    /**
     * Leave silently drops coordinator questions; reopening reconstructs from the
     * child snapshot and rediscovers unresolved HITL.
     */
    #[Test]
    public function testReentryRereadsSnapshotAndReenqueuesUnresolvedHitl(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'hitl-reentry');
        $state = new TuiSessionState('hitl-reentry');
        $childRunId = 'child-run-waiting';
        $child = new SubagentLiveChildDTO(
            $childRunId,
            'art-waiting',
            'scout',
            SubagentLiveStatusEnum::WaitingHuman,
            'needs human',
            1,
            'deepseek/deepseek-v4-flash',
            'medium',
        );
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($state->subagentLiveCatalog, new RuntimeEvent(
            type: 'tool_execution.output_delta',
            runId: 'hitl-reentry',
            seq: 1,
            payload: [
                'tool_call_id' => 'tc1',
                'tool_name' => 'subagent',
                'delta' => '',
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'waiting_human',
                    'agent_name' => 'scout',
                    'artifact_id' => 'art-waiting',
                    'agent_run_id' => $childRunId,
                    'task_summary' => 'needs human',
                    'model' => 'deepseek/deepseek-v4-flash',
                    'reasoning' => 'medium',
                ],
            ],
        ));

        $hitlEvent = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: $childRunId,
            seq: 5,
            payload: [
                'question_id' => 'q_reentry',
                'ui_kind' => 'text',
                'prompt' => 'Which path should the scout inspect?',
                'schema' => ['type' => 'string'],
            ],
        );
        $snapshot = new ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: [
                new TranscriptBlock(
                    'b-hitl',
                    TranscriptBlockKindEnum::Progress,
                    $childRunId,
                    5,
                    'Which path should the scout inspect?',
                ),
            ],
            replayEvents: [$hitlEvent],
            maxSeq: 5,
        );

        $coordinator = new QuestionCoordinator();
        $handler = new RuntimeQuestionEventHandler();
        $client = new ObservingSpyClient();
        $onHuman = static function (RuntimeEvent $event) use ($handler, $client, $coordinator, $state, $harness): void {
            $handler->handleHumanInputRequested($event, $client, $coordinator, $state, $harness->screen());
        };
        $controller = new QuestionController($coordinator, $harness->screen());

        $snapshotProvider = $this->createMock(ChildRunTranscriptSnapshotProviderInterface::class);
        $snapshotProvider->expects($this->exactly(3))->method('snapshot')->willReturnMap([
            [$childRunId, $snapshot],
            ['child-other', new ChildRunTranscriptSnapshotDTO([], [], 0)],
        ]);
        $poller = new SubagentLiveChildViewPoller(new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()), new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $picker = new SubagentLivePickerController(
            $harness->tui(),
            $harness->screen(),
            $state,
            $client,
            $poller,
            $snapshotProvider,
            $this->createStub(ChildAgentEventsPathResolverInterface::class),
            SessionEventsExportServiceFactory::create(),
            $coordinator,
            $controller,
            onHumanInputRequested: $onHuman,
        );

        $enter = new \ReflectionMethod(SubagentLivePickerController::class, 'enterLiveView');
        $enter->invoke($picker, $child, $state, $harness->screen());

        $requestId = 'hitl_'.substr(hash('sha256', $childRunId.'|q_reentry'), 0, 16);

        $this->assertTrue($coordinator->actionRequired());
        $this->assertSame($childRunId, $coordinator->activeRequest()?->runId);
        $this->assertTrue($coordinator->hasRequest($requestId));

        // Production leave: silent remove + exit (cache keeps transcript + lastSeq + replayEvents).
        SubagentLiveMainReturn::returnToMain($state, $harness->screen(), $coordinator, $controller, $poller, $client);
        $this->assertFalse($state->subagentLiveView->active);
        $this->assertFalse($coordinator->actionRequired());
        $this->assertFalse($coordinator->hasRequest($requestId));
        $this->assertSame([], $state->subagentLiveView->childTranscript());
        $this->assertSame(0, $state->subagentLiveView->childLastSeq);

        // Re-entry always rereads the durable snapshot and rediscovers unresolved HITL.
        $enter->invoke($picker, $child, $state, $harness->screen());

        $this->assertTrue($state->subagentLiveView->active);
        $this->assertTrue($coordinator->actionRequired());
        $this->assertSame($childRunId, $coordinator->activeRequest()?->runId);
        $this->assertTrue($coordinator->hasRequest($requestId));
        $this->assertSame(5, $state->subagentLiveView->childLastSeq);

        $request = $coordinator->activeRequest();
        $this->assertNotNull($request);
        $controller->open($request);
        $other = new SubagentLiveChildDTO('child-other', 'art-other', 'reviewer', SubagentLiveStatusEnum::Completed, 'other task', 2, 'deepseek/deepseek-v4-flash', 'medium');
        $enter->invoke($picker, $other, $state, $harness->screen());

        $this->assertTrue($state->subagentLiveView->active);
        $this->assertSame('child-other', $state->subagentLiveView->selected?->agentRunId);
        $this->assertFalse($coordinator->hasRequest($requestId));
        $this->assertFalse($controller->isOpen());
        $this->assertSame([
            'begin:'.$childRunId,
            'end:'.$childRunId,
            'begin:'.$childRunId,
            'end:'.$childRunId,
            'begin:child-other',
        ], $client->calls);
    }
}

final class ObservingSpyClient implements AgentSessionClient
{
    /** @var list<string> */
    public array $calls = [];

    public function beginObservingChildRun(string $childRunId): void
    {
        $this->calls[] = 'begin:'.$childRunId;
    }

    public function endObservingChildRun(string $childRunId): void
    {
        $this->calls[] = 'end:'.$childRunId;
    }

    /** @return list<BashBackgroundCandidateDTO> */
    public function foregroundBash(string $runId): array
    {
        return [];
    }

    /** @return list<never> */
    public function foregroundChildren(string $runId): array
    {
        return [];
    }

    public function backgroundWork(string $rootRunId, ?int $observedProcessId = null, array $observedChildRunIds = [], bool $includeChildHistory = false): BackgroundWorkSnapshotDTO
    {
        return new BackgroundWorkSnapshotDTO([], []);
    }

    public function stopBackgroundProcess(string $runId, int $recordId): bool
    {
        throw new \LogicException('Unexpected background stop.');
    }

    public function start(StartRunRequest $request): RunHandle
    {
        throw new \RuntimeException('not used');
    }

    public function acknowledgeBootstrap(array $cut): void
    {
    }

    public function cancelBootstrap(string $runId): void
    {
    }

    public function attach(string $runId): RunHandle
    {
        throw new \RuntimeException('not used');
    }

    public function send(string $runId, UserCommand $command): void
    {
    }

    public function events(string $runId, int $afterSeq = 0): iterable
    {
        return [];
    }

    /**
     * No-op shutdown: this spy owns no runtime to tear down.
     */
    public function shutdown(): void
    {
    }

    public function refreshMcpCatalog(string $runId): void
    {
    }

    public function cancel(string $runId): void
    {
    }

    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle
    {
        throw new \RuntimeException('not used');
    }

    public function repair(string $runId, bool $apply = true): RepairResult
    {
        return new RepairResult(false, false, 'No repairable corruption detected.');
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }
}

final class FixedChildRunTranscriptSnapshotProvider implements ChildRunTranscriptSnapshotProviderInterface
{
    public int $calls = 0;

    public function __construct(private readonly ChildRunTranscriptSnapshotDTO $snapshot)
    {
    }

    public function snapshot(string $childRunId): ChildRunTranscriptSnapshotDTO
    {
        ++$this->calls;

        return $this->snapshot;
    }
}
