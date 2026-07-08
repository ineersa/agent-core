<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubagentLiveInputPolicy;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\PromptHistory;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
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
            ->with($this->callback(static function (StartRunRequest $req): bool {
                return '/review foo bar' === $req->prompt;
            }))
            ->willReturn($expectedHandle);

        $this->dispatchSubmit('/review foo bar');

        $this->assertSame($expectedHandle, $this->state->handle);
        $this->assertSame(RunActivityStateEnum::Starting, $this->state->activity);
    }

    #[Test]
    public function dispatchRuntimeSetsPromptInRequest(): void
    {
        $this->state->handle = null;
        $this->state->sessionId = 'test-session';
        $this->state->activity = RunActivityStateEnum::Idle;

        $this->client->expects($this->once())
            ->method('start')
            ->with($this->callback(static function (StartRunRequest $req): bool {
                return '/review expanded-target' === $req->prompt;
            }))
            ->willReturn(new RunHandle('run-1'));

        $this->dispatchSubmit('/review expanded-target');

        $this->assertNotNull($this->state->request);
        $this->assertSame('/review expanded-target', $this->state->request->prompt);
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
                $this->callback(static function (UserCommand $cmd): bool {
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
            ->with('run-1', $this->callback(static function (UserCommand $cmd): bool {
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
                $this->callback(static function (UserCommand $cmd): bool {
                    return 'follow_up' === $cmd->type && '/review follow' === $cmd->text;
                }),
            );

        $this->dispatchSubmit('/review follow');

        // Activity transitions to Starting after follow_up
        $this->assertSame(RunActivityStateEnum::Starting, $this->state->activity);
    }

    #[Test]
    public function dispatchRuntimeSendsFollowUpWhileIdle(): void
    {
        $this->state->handle = new RunHandle('run-1');
        $this->state->activity = RunActivityStateEnum::Idle;
        $this->state->sessionId = 'test-session';

        $this->client->expects($this->once())
            ->method('send')
            ->with('run-1', $this->callback(static function (UserCommand $cmd): bool {
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

        $this->assertSame(RunActivityStateEnum::Failed, $this->state->activity);
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

        $this->assertNotEmpty($this->state->transcript);
        $lastBlock = $this->state->transcript[\count($this->state->transcript) - 1];
        $this->assertStringContainsString('Runtime error:', $lastBlock->text);
        $this->assertStringContainsString('Connection lost', $lastBlock->text);
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
        $this->assertSame(RunActivityStateEnum::Failed, $this->state->activity);
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
        $em->method('persist')->willReturnCallback(static function ($entity) use (&$persisted) {
            $persisted = $entity;
        });
        $em->method('flush')->willReturnCallback(static function () use (&$persisted, &$nextId) {
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
            ->with($this->callback(static function (StartRunRequest $req): bool {
                return '/review draft' === $req->prompt;
            }))
            ->willReturn(new RunHandle('draft-run-1'));

        $screen = $this->dispatchSubmit('/review draft', $sessionStore);

        $this->assertNotSame('', $this->state->sessionId, 'Draft sessionId should be promoted');
        $this->assertSame(RunActivityStateEnum::Starting, $this->state->activity);
        $this->assertNotNull($this->state->handle);
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
            ->with($this->callback(static function (StartRunRequest $req): bool {
                return '/review restart' === $req->prompt;
            }))
            ->willReturn(new RunHandle('fresh-run'));

        $this->client->expects($this->never())
            ->method('send');

        $this->dispatchSubmit('/review restart');

        $this->assertFalse($this->state->isShellRun);
        $this->assertSame('fresh-run', $this->state->handle->runId);
        $this->assertSame(RunActivityStateEnum::Starting, $this->state->activity);
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
            ->with($this->callback(static function (StartRunRequest $req): bool {
                return 'hello world' === $req->prompt;
            }))
            ->willReturn(new RunHandle('run-1'));

        $this->dispatchSubmit('hello world');

        $this->assertNotNull($this->state->handle);
        $this->assertSame(RunActivityStateEnum::Starting, $this->state->activity);
    }

    #[Test]
    public function normalPromptSendsFollowUpWhenIdle(): void
    {
        $this->state->handle = new RunHandle('run-1');
        $this->state->activity = RunActivityStateEnum::Idle;
        $this->state->sessionId = 'test-session';

        $this->client->expects($this->once())
            ->method('send')
            ->with('run-1', $this->callback(static function (UserCommand $cmd): bool {
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
                $this->callback(static function (UserCommand $cmd): bool {
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
                $this->callback(static function (UserCommand $cmd): bool {
                    return 'shell_command' === $cmd->type
                        && 'pwd' === $cmd->text
                        && [] === $cmd->payload;
                }),
            );

        $this->dispatchSubmit('!pwd');
    }

    #[Test]
    public function parentHumanInputAnswerRoutesToParentRunOnMainView(): void
    {
        $parentRunId = 'parent-session-hitl';
        $this->state->sessionId = $parentRunId;
        $this->state->handle = new RunHandle($parentRunId);
        $this->state->activity = RunActivityStateEnum::WaitingHuman;

        $sent = null;
        $client = $this->client;
        $this->client->expects($this->once())->method('send')->willReturnCallback(
            static function (string $runId, UserCommand $cmd) use (&$sent, $parentRunId): void {
                $sent = [$runId, $cmd];
                self::assertSame($parentRunId, $runId);
            },
        );

        $this->questionCoordinator->enqueue(
            new QuestionRequest(
                requestId: 'parent_main_hitl',
                source: QuestionSource::AgentCore,
                kind: QuestionKind::Text,
                prompt: 'Which docs file would you like me to inspect and summarize?',
                schema: ['type' => 'string'],
                runId: $parentRunId,
                questionId: 'q_docs_parent',
            ),
            onAnswer: static function (mixed $answer) use ($client, $parentRunId): void {
                $client->send($parentRunId, new UserCommand(
                    type: 'answer_human',
                    payload: [
                        'question_id' => 'q_docs_parent',
                        'answer' => \is_scalar($answer) ? (string) $answer : 'cancel',
                    ],
                ));
            },
        );

        $this->dispatchSubmit('docs/agents.md');

        $this->assertNotNull($sent);
        $this->assertSame('answer_human', $sent[1]->type);
        $this->assertSame('q_docs_parent', $sent[1]->payload['question_id'] ?? null);
        $this->assertSame('docs/agents.md', $sent[1]->payload['answer'] ?? null);
        $this->assertFalse($this->questionCoordinator->actionRequired());
    }


    #[Test]
    public function normalPromptSubmitAppendsToPromptHistory(): void
    {
        $history = new PromptHistory();
        $this->state->handle = null;
        $this->state->sessionId = 'test-session';
        $this->state->activity = RunActivityStateEnum::Idle;

        $this->client->expects($this->once())
            ->method('start')
            ->willReturn(new RunHandle('run-1'));

        $this->dispatchSubmit('plain user prompt', history: $history);

        $this->assertSame(['plain user prompt'], $history->prompts());
    }

    #[Test]
    public function dispatchRuntimeSlashSubmitAppendsUserTypedTextNotPayloadOnly(): void
    {
        $history = new PromptHistory();
        $this->state->handle = null;
        $this->state->sessionId = 'test-session';
        $this->state->activity = RunActivityStateEnum::Idle;

        $this->client->expects($this->once())
            ->method('start')
            ->willReturn(new RunHandle('run-1'));

        $this->dispatchSubmit('/review foo bar', history: $history);

        $this->assertSame(['/review foo bar'], $history->prompts());
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function localSlashHelpDoesNotAppendToPromptHistory(): void
    {
        $history = new PromptHistory();
        $registry = new SlashCommandRegistry();
        $registry->register(
            new CommandMetadata(
                name: 'localonly',
                description: 'Local only',
                usage: '/localonly',
                acceptsArguments: false,
            ),
            new class implements SlashCommandHandler {
                public function handle(SlashCommand $command): \Ineersa\Tui\Command\TranscriptMessage
                {
                    return new \Ineersa\Tui\Command\TranscriptMessage('local', 'system');
                }
            },
        );
        $router = new SubmissionRouter(new CommandParser(), $registry);

        $this->dispatchSubmit('/localonly', history: $history, router: $router);

        $this->assertSame([], $history->prompts());
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
    private function dispatchSubmit(string $text, ?HatfieldSessionStore $sessionStore = null, ?PromptHistory $history = null, ?SubmissionRouter $router = null): ChatScreen
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
            submissionRouter: $router ?? $this->router,
            blockFactory: new \Ineersa\Tui\Transcript\TranscriptBlockFactory(),
            coordinator: $this->questionCoordinator,
            questionController: $this->questionController,
            subagentLiveInputPolicy: new SubagentLiveInputPolicy(),
            logger: $this->logger,
            history: $history ?? new PromptHistory(),
        );
        $listener->register($context);

        // Extract and invoke the SubmitEvent handler.
        // SubmitEvent expects an AbstractWidget target (the editor widget)
        // and a string value. The SubmitListener closure reads text via
        // $screen->extract() which reads from the PromptEditor, so the
        // value argument is secondary but required.
        $dispatcher = $tui->getEventDispatcher();
        $listeners = $dispatcher->getListeners(SubmitEvent::class);
        $this->assertNotEmpty($listeners, 'SubmitEvent listener was not registered');

        ($listeners[0])(new SubmitEvent($promptEditor->getWidget(), $text));

        return $screen;
    }
}
