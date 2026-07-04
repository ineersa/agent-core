<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Tui;

final class SubmitListenerDispatchRuntimeTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private TuiSessionState $state;
    /** @var AgentSessionClient&\PHPUnit\Framework\MockObject\MockObject */
    private AgentSessionClient $client;
    private LoggerInterface $logger;
    private SlashCommandRegistry $registry;
    private SubmissionRouter $router;
    private QuestionCoordinator $questionCoordinator;
    private QuestionController $questionController;
    private string $tempCwd;

    protected function setUp(): void
    {
        $this->tempCwd = TestDirectoryIsolation::createOsTempDir('hatfield-dispatch');

        $this->state = new TuiSessionState('test-session');
        $this->client = $this->createMock(AgentSessionClient::class);
        $this->logger = new NullLogger();
        $this->questionCoordinator = new QuestionCoordinator();
        $this->questionController = new QuestionController($this->questionCoordinator);

        // Build a registry with a template command returning DispatchRuntime
        $this->registry = new SlashCommandRegistry();
        $this->registry->register(
            new CommandMetadata(
                name: 'review',
                description: 'Review code',
                usage: '/review <args>',
                acceptsArguments: true,
            ),
            new class implements SlashCommandHandler {
                public function handle(SlashCommand $command): DispatchRuntime
                {
                    return new DispatchRuntime($command->originalText);
                }
            },
        );

        $parser = new CommandParser();
        $this->router = new SubmissionRouter($parser, $this->registry);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempCwd);
        parent::tearDown();
    }

    // ── DispatchRuntime starts new run ─────────────────────────────

    #[Test]
    public function dispatchRuntimeStartsNewRunWhenNoHandle(): void
    {
        $this->state->handle = null;
        $this->state->sessionId = 'test-session';
        $this->state->activity = RunActivityStateEnum::Idle;

        $expectedHandle = new RunHandle('run-1');

        $this->client->expects($this->once())
            ->method('start')
            ->with($this->callback(function (StartRunRequest $req): bool {
                return $req->prompt === '/review foo bar';
            }))
            ->willReturn($expectedHandle);

        $this->dispatchSubmit('/review foo bar');

        self::assertSame($expectedHandle, $this->state->handle);
        self::assertSame(RunActivityStateEnum::Starting, $this->state->activity);
    }

    #[Test]
    public function dispatchRuntimeSetsPromptInRequest(): void
    {
        $this->state->handle = null;
        $this->state->sessionId = 'test-session';
        $this->state->activity = RunActivityStateEnum::Idle;

        $this->client->expects($this->once())
            ->method('start')
            ->with($this->callback(function (StartRunRequest $req): bool {
                return $req->prompt === '/review expanded-target';
            }))
            ->willReturn(new RunHandle('run-1'));

        $this->dispatchSubmit('/review expanded-target');

        self::assertNotNull($this->state->request);
        self::assertSame('/review expanded-target', $this->state->request->prompt);
    }

    // ── DispatchRuntime sends steer while active ────────────────────

    #[Test]
    public function dispatchRuntimeSendsSteerWhileActive(): void
    {
        $this->state->handle = new RunHandle('run-1');
        $this->state->activity = RunActivityStateEnum::Running;
        $this->state->sessionId = 'test-session';

        $this->client->expects($this->once())
            ->method('send')
            ->with(
                'run-1',
                $this->callback(function (UserCommand $cmd): bool {
                    return 'steer' === $cmd->type && '/review foo' === $cmd->text;
                }),
            );

        $this->dispatchSubmit('/review foo');
    }

    #[Test]
    public function dispatchRuntimeSendsSteerWhileStarting(): void
    {
        $this->state->handle = new RunHandle('run-1');
        $this->state->activity = RunActivityStateEnum::Starting;
        $this->state->sessionId = 'test-session';

        $this->client->expects($this->once())
            ->method('send')
            ->with('run-1', $this->callback(function (UserCommand $cmd): bool {
                return 'steer' === $cmd->type;
            }));

        $this->dispatchSubmit('/review steer');
    }

    // ── DispatchRuntime sends follow_up while idle/completed ────────

    #[Test]
    public function dispatchRuntimeSendsFollowUpWhileCompleted(): void
    {
        $this->state->handle = new RunHandle('run-1');
        $this->state->activity = RunActivityStateEnum::Completed;
        $this->state->sessionId = 'test-session';

        $this->client->expects($this->once())
            ->method('send')
            ->with(
                'run-1',
                $this->callback(function (UserCommand $cmd): bool {
                    return 'follow_up' === $cmd->type && '/review follow' === $cmd->text;
                }),
            );

        $this->dispatchSubmit('/review follow');

        // Activity transitions to Starting after follow_up
        self::assertSame(RunActivityStateEnum::Starting, $this->state->activity);
    }

    #[Test]
    public function dispatchRuntimeSendsFollowUpWhileIdle(): void
    {
        $this->state->handle = new RunHandle('run-1');
        $this->state->activity = RunActivityStateEnum::Idle;
        $this->state->sessionId = 'test-session';

        $this->client->expects($this->once())
            ->method('send')
            ->with('run-1', $this->callback(function (UserCommand $cmd): bool {
                return 'follow_up' === $cmd->type;
            }));

        $this->dispatchSubmit('/review idle');
    }

    // ── Runtime error handling ─────────────────────────────────────

    #[Test]
    public function dispatchRuntimeErrorSetsFailedState(): void
    {
        $this->state->handle = null;
        $this->state->sessionId = 'test-session';
        $this->state->activity = RunActivityStateEnum::Idle;

        $this->client->expects($this->once())
            ->method('start')
            ->willThrowException(new \RuntimeException('Connection lost'));

        $screen = $this->dispatchSubmit('/review error');

        self::assertSame(RunActivityStateEnum::Failed, $this->state->activity);
    }

    #[Test]
    public function dispatchRuntimeErrorAddsErrorBlock(): void
    {
        $this->state->handle = null;
        $this->state->sessionId = 'test-session';
        $this->state->activity = RunActivityStateEnum::Idle;

        $this->client->expects($this->once())
            ->method('start')
            ->willThrowException(new \RuntimeException('Connection lost'));

        $screen = $this->dispatchSubmit('/review error');

        self::assertNotEmpty($this->state->transcript);
        $lastBlock = $this->state->transcript[count($this->state->transcript) - 1];
        self::assertStringContainsString('Runtime error:', $lastBlock->text);
        self::assertStringContainsString('Connection lost', $lastBlock->text);
    }

    #[Test]
    public function dispatchRuntimeErrorSetsFailedActivity(): void
    {
        $this->state->handle = null;
        $this->state->sessionId = 'test-session';
        $this->state->activity = RunActivityStateEnum::Idle;

        $this->client->expects($this->once())
            ->method('start')
            ->willThrowException(new \RuntimeException('Boom'));

        $this->dispatchSubmit('/review error');

        // The SubmitListener sets activity to Failed and adds an error
        // transcript block on runtime exceptions. The working message
        // is also cleared but there is no public ChatScreen API to query
        // it directly — the visual outcome is covered by the combination
        // of Failed state (this test) + error block (dispatchRuntimeErrorAddsErrorBlock).
        self::assertSame(RunActivityStateEnum::Failed, $this->state->activity);
    }

    // ── Draft session promotion for DispatchRuntime ─────────────

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function dispatchRuntimePromotesDraftSession(): void
    {
        $this->state->sessionId = '';
        $this->state->handle = null;
        $this->state->activity = RunActivityStateEnum::Idle;

        // Set up a mock EntityManager that simulates auto-increment ID
        // assignment on flush(), so createSession() can return a real ID
        // without requiring a full SQLite database.
        $nextId = 1;
        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted = $entity;
        });
        $em->method('flush')->willReturnCallback(function () use (&$persisted, &$nextId) {
            if ($persisted instanceof HatfieldSession) {
                $persisted->id = $nextId++;
                $persisted = null;
            }
        });
        // find() returns null (no existing sessions)
        $em->method('find')->willReturn(null);

        $sessionStore = new HatfieldSessionStore(
            appConfig: new \Ineersa\CodingAgent\Config\AppConfig(
                tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'),
                logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
                sessions: new \Ineersa\CodingAgent\Config\SessionsConfig(),
                cwd: $this->tempCwd,
            ),
            entityManager: $em,
        );

        $this->client->expects($this->once())
            ->method('start')
            ->with($this->callback(function (StartRunRequest $req): bool {
                return '/review draft' === $req->prompt;
            }))
            ->willReturn(new RunHandle('draft-run-1'));

        $screen = $this->dispatchSubmit('/review draft', $sessionStore);

        self::assertNotSame('', $this->state->sessionId, 'Draft sessionId should be promoted');
        self::assertSame(RunActivityStateEnum::Starting, $this->state->activity);
        self::assertNotNull($this->state->handle);
    }

    // ── Shell restart path for DispatchRuntime ─────────────────

    #[Test]
    public function dispatchRuntimeStartsNewRunAfterShellRun(): void
    {
        // After a first-input ! shell command, isShellRun=true and the
        // previous run is terminal. A new normal prompt (or DispatchRuntime)
        // should start a fresh LLM run, not send follow_up on the shell-only
        // handle whose runner was never initialized via start().
        $this->state->sessionId = 'test-session';
        $this->state->handle = new RunHandle('shell-run');
        $this->state->activity = RunActivityStateEnum::Completed;
        $this->state->isShellRun = true;

        $this->client->expects($this->once())
            ->method('start')
            ->with($this->callback(function (StartRunRequest $req): bool {
                return '/review restart' === $req->prompt;
            }))
            ->willReturn(new RunHandle('fresh-run'));

        $this->client->expects($this->never())
            ->method('send');

        $this->dispatchSubmit('/review restart');

        self::assertFalse($this->state->isShellRun);
        self::assertSame('fresh-run', $this->state->handle->runId);
        self::assertSame(RunActivityStateEnum::Starting, $this->state->activity);
    }

    // ── Normal prompt still works after refactor ────────────────────

    #[Test]
    public function normalPromptStartsNewRun(): void
    {
        $this->state->handle = null;
        $this->state->sessionId = 'test-session';
        $this->state->activity = RunActivityStateEnum::Idle;

        $this->client->expects($this->once())
            ->method('start')
            ->with($this->callback(function (StartRunRequest $req): bool {
                return $req->prompt === 'hello world';
            }))
            ->willReturn(new RunHandle('run-1'));

        $this->dispatchSubmit('hello world');

        self::assertNotNull($this->state->handle);
        self::assertSame(RunActivityStateEnum::Starting, $this->state->activity);
    }

    #[Test]
    public function normalPromptSendsFollowUpWhenIdle(): void
    {
        $this->state->handle = new RunHandle('run-1');
        $this->state->activity = RunActivityStateEnum::Idle;
        $this->state->sessionId = 'test-session';

        $this->client->expects($this->once())
            ->method('send')
            ->with('run-1', $this->callback(function (UserCommand $cmd): bool {
                return 'follow_up' === $cmd->type && 'hello again' === $cmd->text;
            }));

        $this->dispatchSubmit('hello again');
    }


    // ── Subsequent shell on terminal run ───────────────────────────

    #[Test]
    public function testSubsequentShellOnCompletedRunSendsStandaloneShellCommand(): void
    {
        $this->state->sessionId = 'test-session';
        $this->state->handle = new RunHandle('run-completed');
        $this->state->activity = RunActivityStateEnum::Completed;
        $this->state->isShellRun = true;

        $this->client->expects($this->once())
            ->method('send')
            ->with(
                'run-completed',
                $this->callback(function (UserCommand $cmd): bool {
                    return 'shell_command' === $cmd->type
                        && 'ls -1' === $cmd->text
                        && true === ($cmd->payload['standalone'] ?? false);
                }),
            );

        $this->dispatchSubmit('!ls -1');
    }

    #[Test]
    public function testSubsequentShellWhileRunningOmitsStandaloneFlag(): void
    {
        $this->state->sessionId = 'test-session';
        $this->state->handle = new RunHandle('run-active');
        $this->state->activity = RunActivityStateEnum::Running;

        $this->client->expects($this->once())
            ->method('send')
            ->with(
                'run-active',
                $this->callback(function (UserCommand $cmd): bool {
                    return 'shell_command' === $cmd->type
                        && 'pwd' === $cmd->text
                        && [] === $cmd->payload;
                }),
            );

        $this->dispatchSubmit('!pwd');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Register the SubmitListener, extract its SubmitEvent handler,
     * and invoke it with text set in the editor.
     *
     * Accepts an optional sessionStore override for tests that need
     * a custom HatfieldSessionStore (e.g. draft promotion with mock EM).
     *
     * @return ChatScreen the screen after dispatch (for state inspection)
     */
    private function dispatchSubmit(string $text, ?HatfieldSessionStore $sessionStore = null): ChatScreen
    {
        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $this->state->sessionId, $promptEditor);

        // Set the text in the editor (will be extracted by SubmitListener)
        $promptEditor->setText($text);

        $builder = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($this->client)
            ->withState($this->state)
            ->withScreen($screen);

        if (null !== $sessionStore) {
            $builder = $builder->withSessionStore($sessionStore);
        }

        $context = $builder->build();

        $listener = new SubmitListener(
            sessionStore: $context->sessionStore,
            submissionRouter: $this->router,
            blockFactory: new \Ineersa\Tui\Transcript\TranscriptBlockFactory(),
            coordinator: $this->questionCoordinator,
            questionController: $this->questionController,
            subagentLiveInputPolicy: new SubagentLiveInputPolicy(),
            logger: $this->logger,
        );
        $listener->register($context);

        // Extract and invoke the SubmitEvent handler.
        // SubmitEvent expects an AbstractWidget target (the editor widget)
        // and a string value. The SubmitListener closure reads text via
        // $screen->extract() which reads from the PromptEditor, so the
        // value argument is secondary but required.
        $dispatcher = $tui->getEventDispatcher();
        $listeners = $dispatcher->getListeners(SubmitEvent::class);
        self::assertNotEmpty($listeners, 'SubmitEvent listener was not registered');

        ($listeners[0])(new SubmitEvent($promptEditor->getWidget(), $text));

        return $screen;
    }

}
