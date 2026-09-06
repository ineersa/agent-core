<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Listener\TickPollListener;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\SessionEventsExportServiceFactory;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Tui;

/** @covers \Ineersa\Tui\Listener\TickPollListener */
final class TickPollListenerSubagentLiveTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    public function testCompletedCatalogChildMapsToIdleWorkingMessage(): void
    {
        $parentRun = 'session-200';
        $client = $this->createStub(AgentSessionClient::class);

        $parentProjector = new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState());
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($parentProjector, SubagentProgressSerializerTestSupport::denormalizer()),
            new TestLogger(),
            new RuntimeExceptionBoundary(new EventDispatcher()),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $state = new TuiSessionState($parentRun);
        $state->handle = null;
        $state->lastSeq = 0;
        $state->activity = RunActivityStateEnum::Completed;

        $child = new SubagentLiveChildDTO('child-300', 'art1', 'scout', SubagentLiveStatusEnum::Running, 'task', 1, 'deepseek/deepseek-v4-flash', 'medium');
        $state->subagentLiveView->enter($child);
        $state->subagentLiveView->childActivity = RunActivityStateEnum::Running;
        $state->subagentLiveView->childTranscript = [
            new TranscriptBlock('c1', TranscriptBlockKindEnum::Progress, 'child-300', 1, 'child live'),
        ];

        SubagentProgressSerializerTestSupport::ingestCatalogEvent($state->subagentLiveCatalog, new RuntimeEvent(
            'tool_execution_update',
            $parentRun,
            2,
            [
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'completed',
                    'agent_name' => 'scout',
                    'artifact_id' => 'art1',
                    'agent_run_id' => 'child-300',
                    'task_summary' => 'done',
                    'model' => 'deepseek/deepseek-v4-flash',
                    'reasoning' => 'medium',
                ],
            ],
        ));

        $childPoller = new SubagentLiveChildViewPoller(
            new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
            new \Psr\Log\NullLogger(),
            SubagentProgressSerializerTestSupport::denormalizer(),
        );

        $tui = new Tui();
        $screen = new ChatScreen(new DefaultTheme(new ThemePalette('test')), $parentRun, new PromptEditor(), new TranscriptDisplayConfig(), new TranscriptDisplayState());
        $screen->setTranscriptBlocks($state->subagentLiveView->childTranscript);

        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            parentPoller: $poller,
            childPoller: $childPoller,
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener = new TickPollListener(new RuntimeQuestionEventHandler());
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        ($handlerRef->getValue($context->ticks)[0])();

        $this->assertSame(RunActivityStateEnum::Completed, $state->subagentLiveView->childActivity);
        $this->assertNull($state->subagentLiveView->lastLiveWorkingMessage);
        $this->assertSame('', $screen->workingMessage());
        $this->assertFalse($this->workingWidget($screen)->isRunning());
        $this->assertSame('idle', $this->workingWidget($screen)->getMessage());
    }

    public function testActiveLiveChildKeepsWorkingSpinnerMessage(): void
    {
        $parentRun = 'session-201';
        $client = $this->createStub(AgentSessionClient::class);

        $parentProjector = new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState());
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($parentProjector, SubagentProgressSerializerTestSupport::denormalizer()),
            new TestLogger(),
            new RuntimeExceptionBoundary(new EventDispatcher()),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $state = new TuiSessionState($parentRun);
        $state->handle = null;
        $state->lastSeq = 0;
        $state->activity = RunActivityStateEnum::Completed;

        $child = new SubagentLiveChildDTO('child-301', 'art2', 'scout', SubagentLiveStatusEnum::Running, 'task', 1, 'deepseek/deepseek-v4-flash', 'medium');
        $state->subagentLiveView->enter($child);
        $state->subagentLiveView->childActivity = RunActivityStateEnum::Running;
        $state->subagentLiveView->childTranscript = [
            new TranscriptBlock('c1', TranscriptBlockKindEnum::Progress, 'child-301', 1, 'child live'),
        ];

        SubagentProgressSerializerTestSupport::ingestCatalogEvent($state->subagentLiveCatalog, new RuntimeEvent(
            'tool_execution_update',
            $parentRun,
            2,
            [
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'running',
                    'agent_name' => 'scout',
                    'artifact_id' => 'art2',
                    'agent_run_id' => 'child-301',
                    'task_summary' => 'task',
                    'model' => 'deepseek/deepseek-v4-flash',
                    'reasoning' => 'medium',
                ],
            ],
        ));

        $childPoller = new SubagentLiveChildViewPoller(
            new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
            new \Psr\Log\NullLogger(),
            SubagentProgressSerializerTestSupport::denormalizer(),
        );

        $tui = new Tui();
        $screen = new ChatScreen(new DefaultTheme(new ThemePalette('test')), $parentRun, new PromptEditor(), new TranscriptDisplayConfig(), new TranscriptDisplayState());
        $screen->setTranscriptBlocks($state->subagentLiveView->childTranscript);

        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            parentPoller: $poller,
            childPoller: $childPoller,
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener = new TickPollListener(new RuntimeQuestionEventHandler());
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        ($handlerRef->getValue($context->ticks)[0])();

        $this->assertSame(RunActivityStateEnum::Running, $state->subagentLiveView->childActivity);
        $this->assertSame('Child agent working...', $state->subagentLiveView->lastLiveWorkingMessage);
        $this->assertSame('Child agent working...', $screen->workingMessage());
        $this->assertTrue($this->workingWidget($screen)->isRunning());
    }

    public function testResumeCacheDoesNotMarkCatalogCompletedUntilCurrentTaskFinishes(): void
    {
        $parentRun = 'session-resume-cache';
        $artifactId = 'agent_resume';
        $childRunId = 'child-run-resume';
        $client = $this->createStub(AgentSessionClient::class);

        $parentProjector = new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState());
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($parentProjector, SubagentProgressSerializerTestSupport::denormalizer()),
            new TestLogger(),
            new RuntimeExceptionBoundary(new EventDispatcher()),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $harness = new VirtualTuiHarness(sessionId: $parentRun);
        $state = new TuiSessionState($parentRun);
        $state->handle = null;
        $state->lastSeq = 0;
        $state->activity = RunActivityStateEnum::Completed;
        $screen = $harness->screen();

        $taskA = $this->liveChild($childRunId, $artifactId, SubagentLiveStatusEnum::Completed, 'Task A');
        $state->subagentLiveView->enter($taskA);
        $state->subagentLiveView->childTranscript = [
            new TranscriptBlock('c-a', TranscriptBlockKindEnum::AssistantMessage, $childRunId, 4, 'task a done'),
        ];
        $state->subagentLiveView->childLastSeq = 4;
        $state->subagentLiveView->childActivity = RunActivityStateEnum::Completed;
        $state->subagentLiveView->exit();

        $this->ingestProgress($state, $parentRun, $artifactId, $childRunId, 'completed', 'Task A', 1);
        $this->ingestProgress($state, $parentRun, $artifactId, $childRunId, 'running', 'Task B', 2);

        $taskB = $state->subagentLiveCatalog->findByArtifactId($artifactId);
        $this->assertNotNull($taskB);
        $this->assertSame(SubagentLiveStatusEnum::Running, $taskB->status);
        $this->assertSame('Task B', $taskB->taskSummary);

        $childPoller = new SubagentLiveChildViewPoller(
            new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
            new \Psr\Log\NullLogger(),
            SubagentProgressSerializerTestSupport::denormalizer(),
        );
        $picker = new \Ineersa\Tui\Picker\SubagentLivePickerController(
            $harness->tui(),
            $screen,
            $state,
            $client,
            $childPoller,
            new class implements \Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface {
                public function snapshot(string $childRunId): \Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO
                {
                    return new \Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO([], [], 0);
                }
            },
            $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface::class),
            SessionEventsExportServiceFactory::create(),
        );

        $enter = new \ReflectionMethod(\Ineersa\Tui\Picker\SubagentLivePickerController::class, 'enterLiveView');
        $enter->invoke($picker, $taskB, $state, $screen);

        $this->assertSame(RunActivityStateEnum::Running, $state->subagentLiveView->childActivity);
        $this->assertSame('Child agent working...', $screen->workingMessage());

        $services = $this->createSessionServices(
            tui: $harness->tui(),
            state: $state,
            screen: $screen,
            parentPoller: $poller,
            childPoller: $childPoller,
            subagentLivePicker: $picker,
        );
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener = new TickPollListener(new RuntimeQuestionEventHandler());
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        ($handlerRef->getValue($context->ticks)[0])();

        $this->assertSame(RunActivityStateEnum::Running, $state->subagentLiveView->childActivity);
        $this->assertSame(SubagentLiveStatusEnum::Running, $state->subagentLiveCatalog->findByArtifactId($artifactId)?->status);

        \Ineersa\Tui\Runtime\SubagentLiveMainReturn::returnToMain($state, $screen, $client);
        $this->assertFalse($state->subagentLiveView->active);

        $picker->open();
        $this->assertStringContainsString('[running]', $harness->plainScreenText());
        $picker->closePicker();
        $picker->open();
        $this->assertStringContainsString('[running]', $harness->plainScreenText());
        $this->assertStringNotContainsString('[completed]', $harness->plainScreenText());

        $this->ingestProgress($state, $parentRun, $artifactId, $childRunId, 'completed', 'Task B', 3);
        $picker->closePicker();
        $picker->open();
        $screenText = $harness->plainScreenText();
        $this->assertStringContainsString('[completed]', $screenText);
        $this->assertStringNotContainsString('[running]', $screenText);
    }

    private function closedSubagentLivePicker(): \Ineersa\Tui\Picker\SubagentLivePickerController
    {
        $picker = (new \ReflectionClass(\Ineersa\Tui\Picker\SubagentLivePickerController::class))->newInstanceWithoutConstructor();
        $overlay = new \Ineersa\Tui\Picker\PickerOverlay();
        $overlayRef = new \ReflectionProperty(\Ineersa\Tui\Picker\SubagentLivePickerController::class, 'overlay');
        $overlayRef->setValue($picker, $overlay);
        $openRef = new \ReflectionProperty(\Ineersa\Tui\Picker\PickerOverlay::class, 'isOpen');
        $openRef->setValue($overlay, false);

        return $picker;
    }

    private function workingWidget(ChatScreen $screen): \Symfony\Component\Tui\Widget\LoaderWidget
    {
        $property = new \ReflectionProperty($screen, 'workingWidget');
        $widget = $property->getValue($screen);
        $this->assertInstanceOf(\Symfony\Component\Tui\Widget\LoaderWidget::class, $widget);

        return $widget;
    }

    private function liveChild(
        string $runId,
        string $artifactId,
        SubagentLiveStatusEnum $status,
        string $taskSummary,
    ): SubagentLiveChildDTO {
        return new SubagentLiveChildDTO(
            $runId,
            $artifactId,
            'reviewer',
            $status,
            $taskSummary,
            1,
            'deepseek/deepseek-v4-flash',
            'medium',
        );
    }

    private function ingestProgress(
        TuiSessionState $state,
        string $parentRun,
        string $artifactId,
        string $childRunId,
        string $status,
        string $taskSummary,
        int $seq,
    ): void {
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($state->subagentLiveCatalog, new RuntimeEvent(
            'tool_execution_update',
            $parentRun,
            $seq,
            [
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => $status,
                    'agent_name' => 'reviewer',
                    'artifact_id' => $artifactId,
                    'agent_run_id' => $childRunId,
                    'task_summary' => $taskSummary,
                    'model' => 'deepseek/deepseek-v4-flash',
                    'reasoning' => 'medium',
                ],
            ],
        ));
    }
}
final class ParentEventClient implements AgentSessionClient
{
    private bool $yielded = false;

    public function __construct(private string $parentRun, private RuntimeEvent $event)
    {
    }

    public function start(StartRunRequest $request): RunHandle
    {
        throw new \BadMethodCallException();
    }

    public function attach(string $runId): RunHandle
    {
        return new RunHandle($runId, 'attached');
    }

    public function send(string $runId, UserCommand $command): void
    {
    }

    public function beginObservingChildRun(string $childRunId): void
    {
    }

    public function endObservingChildRun(string $childRunId): void
    {
    }

    public function events(string $runId, int $afterSeq = 0): iterable
    {
        if ($runId === $this->parentRun && !$this->yielded) {
            $this->yielded = true;
            yield $this->event;
        }
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
        throw new \BadMethodCallException();
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }
}
