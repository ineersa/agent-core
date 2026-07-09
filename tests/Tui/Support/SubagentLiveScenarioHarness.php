<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\EventListener\RuntimeExceptionPolicySubscriber;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeErrorCaptureConfig;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubagentLiveInputPolicy;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Listener\AgentsMainCommandHandler;
use Ineersa\Tui\Listener\CancelListener;
use Ineersa\Tui\Listener\PromptHistory;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveAttention;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * In-process scenario harness: real Submit/Cancel listeners, coordinator, catalog, recording client.
 */
final class SubagentLiveScenarioHarness
{
    public readonly TuiSessionState $state;
    public readonly ChatScreen $screen;
    public readonly RecordingAgentSessionClient $client;
    public readonly QuestionCoordinator $questionCoordinator;
    public readonly QuestionController $questionController;

    private readonly Tui $tui;
    private readonly PromptEditor $promptEditor;
    private readonly string $parentRunId;
    private readonly HatfieldSessionStore $sessionStore;

    private function __construct(
        TuiSessionState $state,
        ChatScreen $screen,
        RecordingAgentSessionClient $client,
        QuestionCoordinator $questionCoordinator,
        QuestionController $questionController,
        Tui $tui,
        PromptEditor $promptEditor,
        string $parentRunId,
        HatfieldSessionStore $sessionStore,
    ) {
        $this->state = $state;
        $this->screen = $screen;
        $this->client = $client;
        $this->questionCoordinator = $questionCoordinator;
        $this->questionController = $questionController;
        $this->tui = $tui;
        $this->promptEditor = $promptEditor;
        $this->parentRunId = $parentRunId;
        $this->sessionStore = $sessionStore;
    }

    public static function create(
        TestCase $testCase,
        string $parentSessionId = 'parent-session',
        string $parentRunId = 'parent-run-1',
        ?EntityManagerInterface $entityManager = null,
        ?TuiSessionSwitchServiceInterface $switchService = null,
        ?TurnTreeProviderInterface $turnTreeProvider = null,
    ): self {
        $state = new TuiSessionState($parentSessionId);
        $state->handle = new RunHandle($parentRunId);
        $state->activity = RunActivityStateEnum::Running;

        $client = new RecordingAgentSessionClient();
        $questionCoordinator = new QuestionCoordinator();
        $questionController = new QuestionController($questionCoordinator);

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('scenario'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen(
            $theme,
            $parentSessionId,
            $promptEditor,
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );

        $registry = new SlashCommandRegistry();
        foreach (['agents-main', 'agents-live', 'tasks'] as $name) {
            $registry->register(
                new CommandMetadata(name: $name, description: 'test', usage: '/'.$name),
                new class($name) implements SlashCommandHandler {
                    public function __construct(private string $name)
                    {
                    }

                    public function handle(SlashCommand $command): TranscriptMessage
                    {
                        return new TranscriptMessage('handled '.$this->name, 'system');
                    }
                },
            );
        }

        $router = new SubmissionRouter(new CommandParser(), $registry);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            cwd: '/tmp',
        );
        if (null === $entityManager || null === $switchService) {
            throw new \InvalidArgumentException('SubagentLiveScenarioHarness::create requires entityManager and switchService stubs from the TestCase');
        }

        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $entityManager,
        );

        $context = new TuiRuntimeContext(
            tui: $tui,
            client: $client,
            state: $state,
            screen: $screen,
            sessionStore: $sessionStore,
            ticks: new TuiTickDispatcher(),
            switch: $switchService,
            lifecycle: new TuiSessionLifecycleDispatcher(),
            turnTreeProvider: $turnTreeProvider ?? self::emptyTurnTreeProvider(),
        );

        $submitListener = new SubmitListener(
            sessionStore: $sessionStore,
            submissionRouter: $router,
            blockFactory: new TranscriptBlockFactory(),
            coordinator: $questionCoordinator,
            questionController: $questionController,
            subagentLiveInputPolicy: new SubagentLiveInputPolicy(),
            logger: new NullLogger(),
            history: new PromptHistory(),
        );
        $submitListener->register($context);

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new RuntimeExceptionPolicySubscriber(
            new RuntimeErrorCaptureConfig(captureErrors: false),
            new NullLogger(),
        ));
        $boundary = new RuntimeExceptionBoundary($eventDispatcher);

        $cancelListener = new CancelListener(
            new NullLogger(),
            $boundary,
            $questionController,
            $questionCoordinator,
        );
        $cancelListener->register($context);

        return new self($state, $screen, $client, $questionCoordinator, $questionController, $tui, $promptEditor, $parentRunId, $sessionStore);
    }

    public function seedChildInCatalog(
        string $artifactId,
        string $childRunId,
        string $progressStatus,
        string $agentName = 'scout',
        string $taskSummary = 'Scenario task',
    ): void {
        $this->ingestChildProgress($artifactId, $childRunId, $progressStatus, $agentName, $taskSummary);
    }

    public function enterLiveView(
        string $artifactId,
        string $childRunId,
        RunActivityStateEnum $childActivity,
        SubagentLiveStatusEnum $status = SubagentLiveStatusEnum::Running,
    ): void {
        $child = $this->state->subagentLiveCatalog->findByArtifactId($artifactId)
            ?? new SubagentLiveChildDTO(
                agentRunId: $childRunId,
                artifactId: $artifactId,
                agentName: 'scout',
                status: $status,
                taskSummary: 'Scenario task',
                lastActivityAtMs: 1,
            );
        $this->state->subagentLiveView->enter($child);
        $this->state->subagentLiveView->childActivity = $childActivity;
    }

    public function enqueueChildHumanInputViaTickPoll(
        string $childRunId,
        string $questionId = 'q_child_scenario',
        string $prompt = 'Which file should the scout inspect next?',
    ): void {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: $childRunId,
            seq: 10,
            payload: [
                'question_id' => $questionId,
                'ui_kind' => 'text',
                'prompt' => $prompt,
                'schema' => ['type' => 'string'],
            ],
        );

        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleHumanInputRequested');
        $ref->invoke($this->runtimeQuestionHandler(), $event, $this->client, $this->questionCoordinator, $this->state, $this->screen);
    }

    public function enqueueParentHumanInputViaTickPoll(
        string $questionId = 'q_parent_scenario',
        string $prompt = 'Which docs file would you like me to inspect and summarize?',
    ): void {
        $this->state->activity = RunActivityStateEnum::WaitingHuman;
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: $this->parentRunId,
            seq: 20,
            payload: [
                'question_id' => $questionId,
                'ui_kind' => 'text',
                'prompt' => $prompt,
                'schema' => ['type' => 'string'],
            ],
        );

        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleHumanInputRequested');
        $ref->invoke($this->runtimeQuestionHandler(), $event, $this->client, $this->questionCoordinator, $this->state, $this->screen);
    }

    public function ingestChildProgress(
        string $artifactId,
        string $childRunId,
        string $status,
        string $agentName = 'scout',
        string $taskSummary = 'Scenario task',
    ): void {
        $this->state->subagentLiveCatalog->ingestRuntimeEvent($this->parentProgressEvent([
            'mode' => 'single',
            'status' => $status,
            'agent_name' => $agentName,
            'artifact_id' => $artifactId,
            'agent_run_id' => $childRunId,
            'task_summary' => $taskSummary,
        ]));
    }

    public function refreshAttentionFooter(): void
    {
        SubagentLiveAttention::refreshAttentionFooter($this->state, $this->screen);
    }

    public function agentsMain(): void
    {
        $handler = new AgentsMainCommandHandler($this->state, $this->screen);
        $handler->handle(new SlashCommand('agents-main', '', '/agents-main'));
    }

    public function submit(string $text): void
    {
        $this->promptEditor->setText($text);
        $listeners = $this->tui->getEventDispatcher()->getListeners(SubmitEvent::class);
        if ([] === $listeners) {
            throw new \RuntimeException('SubmitEvent listener not registered');
        }
        ($listeners[0])(new SubmitEvent($this->promptEditor->getWidget(), $text));
    }

    public function pressEsc(): void
    {
        $listeners = $this->tui->getEventDispatcher()->getListeners(CancelEvent::class);
        if ([] === $listeners) {
            throw new \RuntimeException('CancelEvent listener not registered');
        }
        ($listeners[0])(new CancelEvent(new TextWidget()));
    }

    public function statusText(string $key): ?string
    {
        $ref = new \ReflectionClass($this->screen);
        $providerProp = $ref->getProperty('footerDataProvider');
        $data = $providerProp->getValue($this->screen);
        /** @var array<string, string> $entries */
        $entries = $data->getStatusEntries();

        return $entries[$key] ?? null;
    }

    /** @return list<string> */
    public function pickerLabels(): array
    {
        $children = $this->state->subagentLiveCatalog->all();
        $items = $this->buildPickerItems($children, $this->screen->theme());

        return array_map(static fn (array $row): string => $row['label'], $items);
    }

    private static function emptyTurnTreeProvider(): TurnTreeProviderInterface
    {
        return new class implements TurnTreeProviderInterface {
            public function forSession(string $runId): TurnTreeView
            {
                return new TurnTreeView(
                    runId: $runId,
                    nodesByTurnNo: [],
                    rootTurnNos: [],
                    currentLeafTurnNo: null,
                    activePathTurnNos: [],
                );
            }
        };
    }

    private function runtimeQuestionHandler(): RuntimeQuestionEventHandler
    {
        return new RuntimeQuestionEventHandler();
    }

    /**
     * @param list<SubagentLiveChildDTO> $children
     *
     * @return list<array{value: string, label: string}>
     */
    private function buildPickerItems(array $children, TuiTheme $theme, int $selectedIndex = -1): array
    {
        $picker = new SubagentLivePickerController(
            new SubagentLiveChildViewPoller(
                new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
                new NullLogger(),
            ),
            $this->sessionStore,
            new SessionEventsExportService(),
            ContextUsageTestAppConfig::withContextWindow(),
        );
        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'buildItems');

        return $method->invoke($picker, $children, $theme, $selectedIndex);
    }

    /** @param array<string, mixed> $progress */
    private function parentProgressEvent(array $progress): RuntimeEvent
    {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: $this->parentRunId,
            seq: 1,
            payload: [
                'tool_call_id' => 'tc_subagent',
                'tool_name' => 'subagent',
                'delta' => '',
                'subagent_progress' => $progress,
            ],
        );
    }
}
