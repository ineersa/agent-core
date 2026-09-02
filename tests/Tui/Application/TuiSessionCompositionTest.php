<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

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
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Application\TuiSessionCompositionFactory;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Question\QuestionOverlayPromptRenderer;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Command\FixedMessageTestHandler;
use Ineersa\Tui\Tests\Support\SessionEventsExportServiceFactory;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Tui\Tui;

/**
 * Per-session composition regression: two sequential scopes must be fully
 * independent (controllers, histories, handler maps, question state, switch
 * service, pollers) and parent/child transcript projections must never bleed
 * between scopes.
 */
#[CoversClass(TuiSessionCompositionFactory::class)]
final class TuiSessionCompositionTest extends TestCase
{
    private TuiSessionCompositionFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            cwd: '/tmp'
        );
        $sessionStore = new HatfieldSessionStore(
            $appConfig,
            $this->createStub(EntityManagerInterface::class),
            dispatcher: new EventDispatcher()
        );
        $modelService = new ModelSelectionService(
            $appConfig,
            new ModelResolver($appConfig, $sessionStore),
            new SettingsOverrideWriter(
                new SettingsPathResolver('/tmp'),
                PropertyAccess::createPropertyAccessor(),
                new Filesystem()
            ),
            $sessionStore
        );

        $this->factory = new TuiSessionCompositionFactory(
            projectors: new ServiceLocator([
                'parent' => fn (): TranscriptProjector => $this->makeProjector(),
                'child' => fn (): TranscriptProjector => $this->makeProjector(),
            ]),
            commandCatalog: new \Ineersa\Tui\Command\SlashCommandCatalog(),
            denormalizer: SubagentProgressSerializerTestSupport::denormalizer(),
            boundary: new RuntimeExceptionBoundary(new EventDispatcher()),
            sessionTranscriptProvider: $this->createStub(SessionTranscriptProviderInterface::class),
            modelService: $modelService,
            appConfig: $appConfig,
            logger: new NullLogger(),
            sessionStore: $sessionStore,
            historyProvider: $this->createStub(HistoryProviderInterface::class),
            childSnapshotProvider: $this->createStub(ChildRunTranscriptSnapshotProviderInterface::class),
            childEventsPathResolver: $this->createStub(ChildAgentEventsPathResolverInterface::class),
            exportService: SessionEventsExportServiceFactory::create(),
            runtimeQuestionEventHandler: new RuntimeQuestionEventHandler(),
            questionPromptRenderer: new QuestionOverlayPromptRenderer()
        );
    }

    #[Test]
    public function twoSequentialScopesHaveIndependentStatefulServices(): void
    {
        $scope1 = $this->factory->create($this->tui(), $this->screen('s1'), new TuiSessionState('s1'), $this->createStub(AgentSessionClient::class));
        $scope2 = $this->factory->create($this->tui(), $this->screen('s2'), new TuiSessionState('s2'), $this->createStub(AgentSessionClient::class));

        // Identity is the ownership contract: every stateful service is fresh.
        $this->assertNotSame($scope1->switch, $scope2->switch);
        $this->assertNotSame($scope1->commandRegistry, $scope2->commandRegistry);
        $this->assertNotSame($scope1->submissionRouter, $scope2->submissionRouter);
        $this->assertNotSame($scope1->questionCoordinator, $scope2->questionCoordinator);
        $this->assertNotSame($scope1->questionController, $scope2->questionController);
        $this->assertNotSame($scope1->promptHistory, $scope2->promptHistory);
        $this->assertNotSame($scope1->modelPicker, $scope2->modelPicker);
        $this->assertNotSame($scope1->favoritePicker, $scope2->favoritePicker);
        $this->assertNotSame($scope1->sessionPicker, $scope2->sessionPicker);
        $this->assertNotSame($scope1->historyPicker, $scope2->historyPicker);
        $this->assertNotSame($scope1->subagentLivePicker, $scope2->subagentLivePicker);
        $this->assertNotSame($scope1->parentPoller, $scope2->parentPoller);
        $this->assertNotSame($scope1->childPoller, $scope2->childPoller);
        $this->assertNotSame($scope1->parentEventApplier, $scope2->parentEventApplier);

        // Handler maps are independent: a handler bound in scope 1 does not
        // resolve in scope 2.
        $scope1->commandRegistry->bind('clear', new FixedMessageTestHandler('scope-one'));
        $this->assertSame(
            'scope-one',
            $scope1->commandRegistry->execute(new \Ineersa\Tui\Command\SlashCommand('clear', '', '/clear'))->text
        );
        $this->assertInstanceOf(
            \Ineersa\Tui\Command\ClearTranscript::class,
            $scope2->commandRegistry->execute(new \Ineersa\Tui\Command\SlashCommand('clear', '', '/clear'))
        );

        // Question state is independent.
        $scope1->questionCoordinator->enqueue(new \Ineersa\Tui\Question\QuestionRequest(
            requestId: 'q1',
            source: \Ineersa\Tui\Question\QuestionSource::AgentCore,
            kind: \Ineersa\Tui\Question\QuestionKind::Text,
            prompt: 'Scope one question?'
        ));
        $this->assertTrue($scope1->questionCoordinator->actionRequired());
        $this->assertFalse($scope2->questionCoordinator->actionRequired());

        // Prompt history is independent (list + navigation cursor).
        $scope1->promptHistory->append('scope one prompt');
        $this->assertSame(['scope one prompt'], self::historyPrompts($scope1->promptHistory));
        $this->assertSame([], self::historyPrompts($scope2->promptHistory));
        $this->assertSame('scope one prompt', $scope1->promptHistory->previous());
        $this->assertNull($scope2->promptHistory->previous());
        $this->assertTrue($scope1->promptHistory->isNavigating());
        $this->assertFalse($scope2->promptHistory->isNavigating());
    }

    private function makeProjector(): TranscriptProjector
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());

        return new TranscriptProjector($dispatcher, new TranscriptProjectionState());
    }

    private function tui(): Tui
    {
        return new Tui();
    }

    private function screen(string $sessionId): ChatScreen
    {
        return new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            $sessionId,
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState()
        );
    }

    /** @return list<string> */
    private static function historyPrompts(\Ineersa\Tui\Listener\PromptHistory $history): array
    {
        $ref = new \ReflectionProperty($history, 'prompts');

        return $ref->getValue($history);
    }
}
