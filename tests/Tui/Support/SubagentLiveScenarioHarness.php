<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\EventListener\RuntimeExceptionPolicySubscriber;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeErrorCaptureConfig;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubagentLiveInputPolicy;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\AgentsMainCommandHandler;
use Ineersa\Tui\Listener\CancelListener;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveAttention;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
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

    private function __construct(
        TuiSessionState $state,
        ChatScreen $screen,
        RecordingAgentSessionClient $client,
        QuestionCoordinator $questionCoordinator,
        QuestionController $questionController,
        Tui $tui,
        PromptEditor $promptEditor,
        string $parentRunId,
    ) {
        $this->state = $state;
        $this->screen = $screen;
        $this->client = $client;
        $this->questionCoordinator = $questionCoordinator;
        $this->questionController = $questionController;
        $this->tui = $tui;
        $this->promptEditor = $promptEditor;
        $this->parentRunId = $parentRunId;
    }

    public static function create(
        TestCase $testCase,
        string $parentSessionId = 'parent-session',
        string $parentRunId = 'parent-run-1',
        ?EntityManagerInterface $entityManager = null,
        ?TuiSessionSwitchServiceInterface $switchService = null,
        ?HistoryProviderInterface $historyProvider = null,
    ): self {
        $state = new TuiSessionState($parentSessionId);
        $state->handle = new RunHandle($parentRunId);
        $state->activity = RunActivityStateEnum::Running;

        $client = new RecordingAgentSessionClient();
        $questionCoordinator = new QuestionCoordinator();

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('scenario'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen(
            $theme,
            $parentSessionId,
            $promptEditor,
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState());

        $catalog = new SlashCommandCatalog();
        foreach (['agents-main', 'agents-live', 'tasks'] as $name) {
            $catalog->register(
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

        $questionController = new QuestionController($questionCoordinator, $screen);
        $services = $testCase->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            client: $client,
            questionCoordinator: $questionCoordinator,
            questionController: $questionController,
            submissionRouter: new SubmissionRouter(new CommandParser(), new SlashCommandRegistry($catalog)),
        );
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
            dispatcher: new EventDispatcher(),
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
            historyProvider: $historyProvider ?? self::emptyHistoryProvider(),
            sessionServices: $services,
        );

        $submitListener = new SubmitListener(
            sessionStore: $sessionStore,
            blockFactory: new TranscriptBlockFactory(),
            subagentLiveInputPolicy: new SubagentLiveInputPolicy(),
            logger: new NullLogger(),
            pastedImageSubmissionService: new \Ineersa\Tui\ImagePaste\PastedImageSubmissionService(
                new \Ineersa\Tui\ImagePaste\PastedImageValidationService(new \Ineersa\CodingAgent\Config\ImageToolConfig(), new \Ineersa\AgentCore\Tests\Support\TestLogger()),
                $context->sessionStore,
                new AppConfig(
                    tui: new TuiConfig(theme: 'default'),
                    logging: new LoggingConfig(),
                    sessions: new SessionsConfig(),
                    cwd: getcwd() ?: '/tmp',
                ),
                new TranscriptBlockFactory(),
                new \Ineersa\AgentCore\Tests\Support\TestLogger(),
            ),
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
        );
        $cancelListener->register($context);

        return new self($state, $screen, $client, $questionCoordinator, $questionController, $tui, $promptEditor, $parentRunId);
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
                model: 'deepseek/deepseek-v4-flash',
                reasoning: 'medium',
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
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($this->state->subagentLiveCatalog, $this->parentProgressEvent([
            'mode' => 'single',
            'status' => $status,
            'agent_name' => $agentName,
            'artifact_id' => $artifactId,
            'agent_run_id' => $childRunId,
            'task_summary' => $taskSummary,
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'medium',
        ]));
    }

    public function refreshAttentionFooter(): void
    {
        SubagentLiveAttention::refreshAttentionFooter($this->state, $this->screen);
    }

    public function agentsMain(): void
    {
        $handler = new AgentsMainCommandHandler(
            $this->state,
            $this->screen,
            $this->questionCoordinator,
            $this->questionController,
            new \Ineersa\Tui\Runtime\SubagentLiveChildViewPoller(
                new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector(
                    new EventDispatcher(),
                    new \Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState(),
                ),
                new NullLogger(),
                SubagentProgressSerializerTestSupport::denormalizer(),
            ),
        );
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
        $ref = new \ReflectionProperty(ChatScreen::class, 'statusEntries');
        $entries = $ref->getValue($this->screen);

        return $entries[$key] ?? null;
    }

    /** @return list<string> */
    public function pickerLabels(): array
    {
        $children = $this->state->subagentLiveCatalog->all();
        $items = SubagentLivePickerController::buildItems($children);

        return array_map(static fn (array $row): string => $row['label'], $items);
    }

    private static function emptyHistoryProvider(): HistoryProviderInterface
    {
        return new class implements HistoryProviderInterface {
            public function forSession(string $runId): HistoryView
            {
                return new HistoryView(prompts: [], positionTurnNo: 0);
            }
        };
    }

    private function runtimeQuestionHandler(): RuntimeQuestionEventHandler
    {
        return new RuntimeQuestionEventHandler();
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
