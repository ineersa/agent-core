<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\ProcessReloadState;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Application\InteractiveMode;
use Ineersa\Tui\Application\SessionInitializer;
use Ineersa\Tui\Application\TranscriptDisplayConfigMapper;
use Ineersa\Tui\Application\TuiSessionCompositionFactory;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\QuitListener;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Listener\TickPollListener;
use Ineersa\Tui\Listener\TuiListenerRegistrar;
use Ineersa\Tui\Question\QuestionOverlayPromptRenderer;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use Ineersa\Tui\Tests\Support\SessionEventsExportServiceFactory;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\ThemeRegistry;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Tui\Event\QuitEvent;

/**
 * Proves throwing ProcessMemorySnapshotLogger::checkpoint cannot break
 * InteractiveMode mount / tick / switch / reload wiring through the real
 * InteractiveMode::run() path. Uses the production Tui constructor (no
 * test-only TerminalInterface injection).
 */
#[CoversClass(InteractiveMode::class)]
#[CoversClass(ProcessMemorySnapshotLogger::class)]
final class InteractiveModeMemoryCheckpointWiringTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            TestDirectoryIsolation::removeDirectory($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    public function testThrowingCheckpointAllowsMountTickAndQuit(): void
    {
        $calls = 0;
        $snapshot = $this->throwingSnapshot($calls);
        $errorLog = $this->redirectErrorLog();
        $ended = [];
        $mode = $this->createInteractiveMode($snapshot, [
            new class($ended) implements TuiListenerRegistrar {
                /** @param list<string> $ended */
                public function __construct(private array &$ended)
                {
                }

                public function register(TuiRuntimeContext $context): void
                {
                    $tui = $context->tui;
                    $ended = &$this->ended;
                    $context->lifecycle->subscribe(static function (TuiSessionLifecycleEventTypeEnum $type) use (&$ended): void {
                        if (TuiSessionLifecycleEventTypeEnum::SessionEnded === $type) {
                            $ended[] = 'ended';
                        }
                    });
                    $context->ticks->add(static function () use ($tui): ?bool {
                        $tui->stop();

                        return null;
                    });
                    $context->tui->addListener(static function (QuitEvent $event) use ($tui): void {
                        $tui->stop();
                    });
                }
            },
        ]);

        $client = $this->createStub(AgentSessionClient::class);

        try {
            $exit = $mode->run(
                client: $client,
                theme: new DefaultTheme(new ThemePalette('test')),
            );

            $this->assertSame(0, $exit);
            $this->assertSame(['ended'], $ended);
            $this->assertGreaterThanOrEqual(2, $calls, 'Mount and shutdown checkpoints must still be attempted');
        } finally {
            $this->restoreErrorLog($errorLog);
        }
    }

    public function testThrowingCheckpointAllowsSessionSwitchContinuation(): void
    {
        $calls = 0;
        $snapshot = $this->throwingSnapshot($calls);
        $errorLog = $this->redirectErrorLog();
        $iterations = 0;
        $mode = $this->createInteractiveMode($snapshot, [
            new class($iterations) implements TuiListenerRegistrar {
                public function __construct(private int &$iterations)
                {
                }

                public function register(TuiRuntimeContext $context): void
                {
                    $tui = $context->tui;
                    $switch = $context->sessionServices->switch;
                    $context->ticks->add(function () use ($tui, $switch): ?bool {
                        ++$this->iterations;
                        if (1 === $this->iterations) {
                            $switch->requestNewDraft();

                            return null;
                        }
                        $tui->stop();

                        return null;
                    });
                }
            },
            new QuitListener(),
        ]);

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('shutdown');

        try {
            $exit = $mode->run(
                client: $client,
                theme: new DefaultTheme(new ThemePalette('test')),
            );

            $this->assertSame(0, $exit);
            $this->assertGreaterThanOrEqual(2, $iterations);
            $this->assertGreaterThanOrEqual(3, $calls, 'Mount+shutdown on first session and mount on second must attempt checkpoints');
        } finally {
            $this->restoreErrorLog($errorLog);
        }
    }

    public function testThrowingCheckpointAllowsReloadExitBranch(): void
    {
        $calls = 0;
        $snapshot = $this->throwingSnapshot($calls);
        $mode = $this->createInteractiveMode($snapshot, [
            new class implements TuiListenerRegistrar {
                public function register(TuiRuntimeContext $context): void
                {
                    $tui = $context->tui;
                    $switch = $context->sessionServices->switch;
                    $sessionId = $context->state->sessionId;
                    $context->ticks->add(static function () use ($tui, $switch, $sessionId): ?bool {
                        $switch->requestReload('' !== $sessionId ? $sessionId : 'draft-reload');
                        $tui->stop();

                        return null;
                    });
                }
            },
        ]);

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('shutdown');

        try {
            ProcessReloadState::consume();
            $errorLog = $this->redirectErrorLog();
            $exit = $mode->run(
                client: $client,
                theme: new DefaultTheme(new ThemePalette('test')),
            );
            $this->assertSame(ProcessReloadState::EXIT_CODE, $exit);
            $this->assertNotNull(ProcessReloadState::consume());
            $this->assertGreaterThanOrEqual(2, $calls);
        } finally {
            if (isset($errorLog)) {
                $this->restoreErrorLog($errorLog);
            }
            ProcessReloadState::consume();
        }
    }

    /**
     * @param list<TuiListenerRegistrar> $extraRegistrars
     */
    private function createInteractiveMode(
        ProcessMemorySnapshotLogger $snapshot,
        array $extraRegistrars,
    ): InteractiveMode {
        $cwd = TestDirectoryIsolation::createProjectTempDir('interactive-mem-');
        $this->tempDirs[] = $cwd;
        TestDirectoryIsolation::createHatfieldTree($cwd);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            cwd: $cwd,
        );
        $sessionStore = new HatfieldSessionStore(
            $appConfig,
            $this->createStub(EntityManagerInterface::class),
            dispatcher: new EventDispatcher(),
        );
        $historyProvider = $this->createStub(HistoryProviderInterface::class);
        $eventStore = new SessionRunEventStore(
            hatfieldSessionStore: $sessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );
        $sessionInit = new SessionInitializer(
            sessionStore: $sessionStore,
            eventStore: $eventStore,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            historyProvider: $historyProvider,
            sessionTranscriptProvider: $this->createStub(SessionTranscriptProviderInterface::class),
        );
        $modelService = new ModelSelectionService(
            $appConfig,
            new ModelResolver($appConfig, $sessionStore, new NullLogger()),
            new SettingsOverrideWriter(
                new SettingsPathResolver($cwd),
                PropertyAccess::createPropertyAccessor(),
                new Filesystem(),
            ),
            $sessionStore,
        );
        $composition = new TuiSessionCompositionFactory(
            projectors: new ServiceLocator([
                'parent' => static fn (): TranscriptProjector => new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
                'child' => static fn (): TranscriptProjector => new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
            ]),
            commandCatalog: new SlashCommandCatalog(),
            denormalizer: SubagentProgressSerializerTestSupport::denormalizer(),
            boundary: new RuntimeExceptionBoundary(new EventDispatcher()),
            sessionTranscriptProvider: $this->createStub(SessionTranscriptProviderInterface::class),
            modelService: $modelService,
            appConfig: $appConfig,
            logger: new NullLogger(),
            sessionStore: $sessionStore,
            historyProvider: $historyProvider,
            childSnapshotProvider: $this->createStub(ChildRunTranscriptSnapshotProviderInterface::class),
            childEventsPathResolver: $this->createStub(ChildAgentEventsPathResolverInterface::class),
            exportService: SessionEventsExportServiceFactory::create(),
            runtimeQuestionEventHandler: new RuntimeQuestionEventHandler(),
            questionPromptRenderer: new QuestionOverlayPromptRenderer(),
        );
        $themeRegistry = new ThemeRegistry(
            $appConfig,
            new AppResourceLocator(ProjectDir::get()),
            new NullLogger(),
        );

        return new InteractiveMode(
            sessionStore: $sessionStore,
            themeRegistry: $themeRegistry,
            sessionInit: $sessionInit,
            listenerRegistrars: [
                new TickPollListener(new RuntimeQuestionEventHandler(), $snapshot),
                ...$extraRegistrars,
            ],
            promptEditor: new PromptEditor(),
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            appConfig: $appConfig,
            transcriptConfigMapper: new TranscriptDisplayConfigMapper(),
            historyProvider: $historyProvider,
            commandCatalog: new SlashCommandCatalog(),
            catalogRegistrars: [],
            compositionFactory: $composition,
            memorySnapshotLogger: $snapshot,
        );
    }

    private function throwingSnapshot(int &$calls): ProcessMemorySnapshotLogger
    {
        $logger = new class($calls) extends AbstractLogger {
            public function __construct(private int &$calls)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ++$this->calls;
                throw new \RuntimeException('checkpoint sink unavailable');
            }
        };

        return new ProcessMemorySnapshotLogger($logger);
    }

    /**
     * @return array{previous: string|false, path: string}
     */
    private function redirectErrorLog(): array
    {
        $previous = \ini_get('error_log');
        $dir = TestDirectoryIsolation::createProjectTempDir('mem-err-');
        $this->tempDirs[] = $dir;
        $path = $dir.'/error.log';
        ini_set('error_log', $path);

        return ['previous' => $previous, 'path' => $path];
    }

    /**
     * @param array{previous: string|false, path: string} $redirect
     */
    private function restoreErrorLog(array $redirect): void
    {
        ini_set('error_log', false === $redirect['previous'] ? '' : $redirect['previous']);
    }
}
