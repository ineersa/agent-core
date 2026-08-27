<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Application\TuiSessionServices;
use Ineersa\Tui\Application\TuiSessionSwitchService;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Listener\PromptHistory;
use Ineersa\Tui\Picker\FavoritePickerController;
use Ineersa\Tui\Picker\HistoryPickerController;
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Tui\Tui;

/**
 * Builds a {@see TuiSessionServices} with sensible defaults for listener tests.
 *
 * Compose into a PHPUnit TestCase (or into another trait composed into one)
 * and call {@see createSessionServices()}.  Real objects are used where
 * construction is cheap (question state, pickers, pollers, registry, switch
 * service, prompt history); process dependencies are PHPUnit stubs created
 * through the TestCase's own createStub().  Individual services can be
 * overridden by the caller through the parameters (extend as needed).
 */
trait TuiSessionServicesFactoryTrait
{
    /**
     * @param TuiSessionSwitchServiceInterface|null $switch optional override;
     *                                                      defaults to a real per-iteration switch service
     */
    public function createSessionServices(
        ?Tui $tui = null,
        ?ChatScreen $screen = null,
        ?TuiSessionState $state = null,
        ?AgentSessionClient $client = null,
        ?TuiSessionSwitchServiceInterface $switch = null,
        ?SlashCommandCatalog $catalog = null,
        ?TranscriptProjectorInterface $parentProjector = null,
        ?TranscriptProjectorInterface $childProjector = null,
        ?QuestionCoordinator $questionCoordinator = null,
        ?QuestionController $questionController = null,
        ?RuntimeEventPoller $parentPoller = null,
        ?SubagentLiveChildViewPoller $childPoller = null,
        ?SubagentLivePickerController $subagentLivePicker = null,
        ?SubmissionRouter $submissionRouter = null,
        ?PromptHistory $promptHistory = null,
    ): TuiSessionServices {
        \assert($this instanceof \PHPUnit\Framework\TestCase,
            'TuiSessionServicesFactoryTrait can only be used in PHPUnit TestCase classes');

        $tui ??= new Tui();
        $state ??= new TuiSessionState('test-session');
        $screen ??= new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            $state->sessionId,
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
        $client ??= $this->createStub(AgentSessionClient::class);
        $switch ??= new TuiSessionSwitchService($tui, $client, $state, new NullLogger());

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            cwd: '/tmp',
        );
        $sessionStore = new HatfieldSessionStore(
            $appConfig,
            $this->createStub(EntityManagerInterface::class),
            dispatcher: new EventDispatcher(),
        );
        $modelService = new ModelSelectionService(
            $appConfig,
            new ModelResolver($appConfig, $sessionStore),
            new SettingsOverrideWriter(
                new SettingsPathResolver('/tmp'),
                PropertyAccess::createPropertyAccessor(),
                new Filesystem(),
            ),
            $sessionStore,
        );

        $catalog ??= new SlashCommandCatalog();
        $registry = new SlashCommandRegistry($catalog);

        $denormalizer = $this->createStub(DenormalizerInterface::class);
        $parentProjector ??= $this->createStub(TranscriptProjectorInterface::class);
        $childProjector ??= $this->createStub(TranscriptProjectorInterface::class);
        $parentApplier = new TuiRuntimeEventApplier($parentProjector, $denormalizer);
        $parentPoller ??= new RuntimeEventPoller(
            $parentApplier,
            new NullLogger(),
            new RuntimeExceptionBoundary(new EventDispatcher()),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );
        $childPoller ??= new SubagentLiveChildViewPoller($childProjector, new NullLogger(), $denormalizer);

        $questionCoordinator ??= new QuestionCoordinator();
        $questionController ??= new QuestionController($questionCoordinator, $screen);
        $promptHistory ??= new PromptHistory();
        $promptHistory->seedFrom($state->transcript);

        $modelPicker = new ModelPickerController($tui, $screen, $state, $modelService, $appConfig, new NullLogger());
        $favoritePicker = new FavoritePickerController($tui, $screen, $modelService, new NullLogger());
        $sessionPicker = new SessionPickerController($tui, $screen, $sessionStore, $switch);
        $historyPicker = new HistoryPickerController(
            $tui,
            $screen,
            $state,
            $this->createStub(HistoryProviderInterface::class),
            $switch,
        );
        $subagentLivePicker ??= new SubagentLivePickerController(
            $tui,
            $screen,
            $state,
            $client,
            $childPoller,
            $this->createStub(ChildRunTranscriptSnapshotProviderInterface::class),
            $this->createStub(ChildAgentEventsPathResolverInterface::class),
            new SessionEventsExportService(),
        );

        return new TuiSessionServices(
            switch: $switch,
            commandRegistry: $registry,
            submissionRouter: $submissionRouter ?? new SubmissionRouter(new CommandParser(), $registry),
            questionCoordinator: $questionCoordinator,
            questionController: $questionController,
            promptHistory: $promptHistory,
            modelPicker: $modelPicker,
            favoritePicker: $favoritePicker,
            sessionPicker: $sessionPicker,
            historyPicker: $historyPicker,
            subagentLivePicker: $subagentLivePicker,
            parentEventApplier: $parentApplier,
            parentPoller: $parentPoller,
            childPoller: $childPoller,
        );
    }
}
