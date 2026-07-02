<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Command\SubagentLiveInputPolicy;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Tui;

final class SubmitListenerSubagentLiveInputTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private TuiSessionState $state;
    /** @var AgentSessionClient&\PHPUnit\Framework\MockObject\MockObject */
    private AgentSessionClient $client;
    private SlashCommandRegistry $registry;
    private SubmissionRouter $router;
    private QuestionCoordinator $questionCoordinator;
    private QuestionController $questionController;

    /** @var array<string, int> */
    private array $handlerCalls = [];

    protected function setUp(): void
    {
        $this->state = new TuiSessionState('parent-session');
        $this->state->handle = new RunHandle('parent-run-1');
        $this->client = $this->createMock(AgentSessionClient::class);
        $this->questionCoordinator = new QuestionCoordinator();
        $this->questionController = new QuestionController($this->questionCoordinator);
        $this->handlerCalls = [];

        $this->registry = new SlashCommandRegistry();
        foreach (['new', 'resume', 'tasks', 'rename', 'agents-main', 'agents-live'] as $name) {
            $this->registerCountingHandler($name);
        }

        $this->router = new SubmissionRouter(new CommandParser(), $this->registry);
        $this->enterLiveView(childRunId: 'child-run-1', childActivity: RunActivityStateEnum::Running);
    }

    #[Test]
    public function liveViewPlainTextSendsSteerToChildRunNotParent(): void
    {
        $this->client->expects($this->once())
            ->method('send')
            ->with(
                'child-run-1',
                $this->callback(static fn (UserCommand $cmd): bool => 'steer' === $cmd->type && 'next step please' === $cmd->text),
            );

        $screen = $this->dispatchSubmit('next step please');

        self::assertStringContainsString('Sent steer to subagent scout', $this->agentsLiveStatus($screen));
        self::assertSame([], $this->state->transcript, 'Child-directed text must not echo into parent transcript');
    }

    #[Test]
    public function liveViewTerminalChildSendsFollowUpAndSetsChildStarting(): void
    {
        $this->state->subagentLiveView->childActivity = RunActivityStateEnum::Completed;

        $this->client->expects($this->once())
            ->method('send')
            ->with(
                'child-run-1',
                $this->callback(static fn (UserCommand $cmd): bool => 'follow_up' === $cmd->type),
            );

        $screen = $this->dispatchSubmit('continue after completion');

        self::assertSame(RunActivityStateEnum::Starting, $this->state->subagentLiveView->childActivity);
        self::assertStringContainsString('Sent follow_up to subagent scout', $this->agentsLiveStatus($screen));
    }

    #[Test]
    public function liveViewBlocksNewResumeTasksAndShellWithoutInvokingHandlers(): void
    {
        $this->client->expects($this->never())->method('send');
        $this->client->expects($this->never())->method('start');

        foreach (['/new', '/resume sid', '/tasks', '/rename x', '!pwd'] as $text) {
            $screen = $this->dispatchSubmit($text);
            self::assertStringContainsString('/agents-main', $this->agentsLiveStatus($screen), $text);
            self::assertSame(0, $this->handlerCalls[$text] ?? 0, $text);
        }
    }

    #[Test]
    public function liveViewAllowsAgentsMainAndAgentsLiveHandlers(): void
    {
        $this->client->expects($this->never())->method('send');

        $this->dispatchSubmit('/agents-main');
        self::assertGreaterThan(0, $this->handlerCalls['/agents-main'] ?? 0);

        $this->enterLiveView('child-run-1', RunActivityStateEnum::Running);
        $this->dispatchSubmit('/agents-live');
        self::assertGreaterThan(0, $this->handlerCalls['/agents-live'] ?? 0);
    }

    #[Test]
    public function mainViewSlashStillRoutesNormallyWhenLiveViewInactive(): void
    {
        $this->state->subagentLiveView->exit();

        $this->client->expects($this->never())->method('send');

        $this->dispatchSubmit('/tasks');
        self::assertGreaterThan(0, $this->handlerCalls['/tasks'] ?? 0);
    }

    private function enterLiveView(string $childRunId, RunActivityStateEnum $childActivity): void
    {
        $child = new SubagentLiveChildDTO(
            agentRunId: $childRunId,
            artifactId: 'agent_fixture',
            agentName: 'scout',
            status: SubagentLiveStatusEnum::Running,
            taskSummary: 'Inspect routing',
            lastActivityAtMs: 1,
        );
        $this->state->subagentLiveView->enter($child);
        $this->state->subagentLiveView->childActivity = $childActivity;
        $this->state->subagentLiveView->childTranscript = [];
    }

    private function registerCountingHandler(string $name): void
    {
        $test = $this;
        $this->registry->register(
            new CommandMetadata(name: $name, description: 'test', usage: '/'.$name),
            new class($test, $name) implements SlashCommandHandler {
                public function __construct(
                    private SubmitListenerSubagentLiveInputTest $test,
                    private string $name,
                ) {
                }

                public function handle(SlashCommand $command): TranscriptMessage
                {
                    $this->test->recordHandlerCall($command->originalText);

                    return new TranscriptMessage('handled '.$this->name, 'system');
                }
            },
        );
    }

    public function recordHandlerCall(string $text): void
    {
        $this->handlerCalls[$text] = ($this->handlerCalls[$text] ?? 0) + 1;
    }

    private function agentsLiveStatus(ChatScreen $screen): string
    {
        $ref = new \ReflectionClass(ChatScreen::class);
        $prop = $ref->getProperty('footerDataProvider');
        $provider = $prop->getValue($screen);

        return $provider->getStatusEntries()['agents-live'] ?? '';
    }

    private function dispatchSubmit(string $text): ChatScreen
    {
        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $this->state->sessionId, $promptEditor);
        $promptEditor->setText($text);

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($this->client)
            ->withState($this->state)
            ->withScreen($screen)
            ->build();

        $listener = new SubmitListener(
            sessionStore: $context->sessionStore,
            submissionRouter: $this->router,
            blockFactory: new TranscriptBlockFactory(),
            coordinator: $this->questionCoordinator,
            questionController: $this->questionController,
            subagentLiveInputPolicy: new SubagentLiveInputPolicy(),
            logger: new NullLogger(),
        );
        $listener->register($context);

        $listeners = $tui->getEventDispatcher()->getListeners(SubmitEvent::class);
        self::assertNotEmpty($listeners);
        ($listeners[0])(new SubmitEvent($promptEditor->getWidget(), $text));

        return $screen;
    }
}
