<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Listener\PromptHistory;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Picker\FavoritePickerController;
use Ineersa\Tui\Picker\HistoryPickerController;
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionOverlayPromptRenderer;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Tui\Tui;

/**
 * Composes the per-session TUI service scope for one loop iteration.
 *
 * Every call to {@see create()} returns a fresh {@see TuiSessionServices}
 * whose stateful controllers, question/history state, command registry,
 * switch service, and parent/child transcript projectors are new
 * instances — nothing from a previous session is reused.
 *
 * Fresh projectors are obtained per call from a Symfony service locator
 * holding non-shared `tui.session.{parent,child}_transcript_projector`
 * services, so parent runtime polling and subagent child-view polling
 * never share projection state with each other or a prior session.
 *
 * PromptHistory is seeded by InteractiveMode after the initial transcript
 * is rebuilt with this scope's parent applier (the transcript does not
 * exist at composition time).
 */
final class TuiSessionCompositionFactory
{
    public function __construct(
        /** @var ContainerInterface Service locator: 'parent' + 'child' → fresh TranscriptProjectorInterface */
        private readonly ContainerInterface $projectors,
        private readonly SlashCommandCatalog $commandCatalog,
        private readonly DenormalizerInterface $denormalizer,
        private readonly RuntimeExceptionBoundary $boundary,
        private readonly SessionTranscriptProviderInterface $sessionTranscriptProvider,
        private readonly ModelSelectionService $modelService,
        private readonly AppConfig $appConfig,
        private readonly LoggerInterface $logger,
        private readonly HatfieldSessionStore $sessionStore,
        private readonly HistoryProviderInterface $historyProvider,
        private readonly ChildRunTranscriptSnapshotProviderInterface $childSnapshotProvider,
        private readonly ChildAgentEventsPathResolverInterface $childEventsPathResolver,
        private readonly SessionEventsExportService $exportService,
        private readonly RuntimeQuestionEventHandler $runtimeQuestionEventHandler,
        private readonly QuestionOverlayPromptRenderer $questionPromptRenderer,
    ) {
    }

    /**
     * Create the fresh per-session service scope.
     *
     * @param AgentSessionClient $client The runtime session client for this iteration
     */
    public function create(
        Tui $tui,
        ChatScreen $screen,
        TuiSessionState $state,
        AgentSessionClient $client,
    ): TuiSessionServices {
        // ── Fresh transcript projection scope (parent + child) ──
        /** @var TranscriptProjectorInterface $parentProjector */
        $parentProjector = $this->projectors->get('parent');
        /** @var TranscriptProjectorInterface $childProjector */
        $childProjector = $this->projectors->get('child');

        $parentEventApplier = new TuiRuntimeEventApplier($parentProjector, $this->denormalizer);
        $parentPoller = new RuntimeEventPoller(
            $parentEventApplier,
            $this->logger,
            $this->boundary,
            $this->sessionTranscriptProvider,
        );
        $childPoller = new SubagentLiveChildViewPoller(
            $childProjector,
            $this->logger,
            $this->denormalizer,
        );

        // ── Fresh question/history state ──
        $questionCoordinator = new QuestionCoordinator();
        $questionController = new QuestionController($questionCoordinator, $screen, $this->questionPromptRenderer);

        // PromptHistory is seeded by InteractiveMode after the initial
        // transcript is rebuilt with this scope's parent applier (the
        // transcript does not exist at composition time).
        $promptHistory = new PromptHistory();

        // ── Fresh session switch service (constructor-bound iteration refs) ──
        $switch = new TuiSessionSwitchService($tui, $client, $state, $this->logger);

        // ── Fresh command registry (session-local handler bindings) ──
        $commandRegistry = new SlashCommandRegistry($this->commandCatalog);
        $submissionRouter = new SubmissionRouter(new CommandParser(), $commandRegistry);

        // ── Fresh picker controllers ──
        $modelPicker = new ModelPickerController(
            $tui,
            $screen,
            $state,
            $this->modelService,
            $this->appConfig,
            $this->logger,
        );
        $favoritePicker = new FavoritePickerController(
            $tui,
            $screen,
            $this->modelService,
            $this->logger,
        );
        $sessionPicker = new SessionPickerController(
            $tui,
            $screen,
            $this->sessionStore,
            $switch,
        );
        $historyPicker = new HistoryPickerController(
            $tui,
            $screen,
            $state,
            $this->historyProvider,
            $switch,
        );

        $eventHandler = $this->runtimeQuestionEventHandler;
        $subagentLivePicker = new SubagentLivePickerController(
            $tui,
            $screen,
            $state,
            $client,
            $childPoller,
            $this->childSnapshotProvider,
            $this->childEventsPathResolver,
            $this->exportService,
            onHumanInputRequested: static function (RuntimeEvent $event) use ($eventHandler, $client, $questionCoordinator, $state, $screen): void {
                $eventHandler->handleHumanInputRequested($event, $client, $questionCoordinator, $state, $screen);
            },
            onToolQuestionRequested: static function (RuntimeEvent $event) use ($eventHandler, $client, $questionCoordinator, $state): void {
                $eventHandler->handleToolQuestionRequested($event, $client, $questionCoordinator, $state);
            },
            onToolTerminal: static function (RuntimeEvent $event) use ($eventHandler, $questionCoordinator, $questionController): void {
                $eventHandler->handleToolTerminal($event, $questionCoordinator, $questionController);
            },
            onLeavingChildRun: static function (string $childRunId) use ($questionCoordinator, $questionController): void {
                $questionCoordinator->removeForRun($childRunId);
                $questionController->close();
            },
        );

        return new TuiSessionServices(
            switch: $switch,
            commandRegistry: $commandRegistry,
            submissionRouter: $submissionRouter,
            questionCoordinator: $questionCoordinator,
            questionController: $questionController,
            promptHistory: $promptHistory,
            modelPicker: $modelPicker,
            favoritePicker: $favoritePicker,
            sessionPicker: $sessionPicker,
            historyPicker: $historyPicker,
            subagentLivePicker: $subagentLivePicker,
            parentEventApplier: $parentEventApplier,
            parentPoller: $parentPoller,
            childPoller: $childPoller,
        );
    }
}
