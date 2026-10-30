<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RequestBashBackgroundDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\RequestBashBackgroundHandler;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\ChildBackgroundCandidateDTO;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\DTO\SubagentProgressSnapshotDTO;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StartResult;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\Tui\Listener\BackgroundBashInputListener;
use Ineersa\Tui\Listener\SubagentLiveToggleInputListener;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class BackgroundBashInputListenerTest extends IsolatedKernelTestCase
{
    use TuiRuntimeContextBuilderTrait;

    private string $visibleRunId;
    private string $foreignRunId;
    private string $parentRunId;
    private BackgroundProcessManager $manager;
    private string $directory;
    private string $command;
    private TuiRuntimeContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $this->visibleRunId = $sessions->createSession('Visible Bash candidates');
        $this->foreignRunId = $sessions->createSession('Foreign Bash candidates');
        $this->parentRunId = $sessions->createSession('Parent Bash candidates');
        $this->manager = self::getContainer()->get(BackgroundProcessManager::class);
        $this->directory = TestDirectoryIsolation::createOsTempDir('bash-input');
        $fifo = $this->directory.'/release';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $this->command = 'cat '.escapeshellarg($fifo);
    }

    protected function tearDown(): void
    {
        $this->manager->shutdownCleanup();
        TestDirectoryIsolation::removeDirectory($this->directory);
        parent::tearDown();
    }

    public static function inputSequences(): iterable
    {
        yield 'legacy' => ["\x02"];
        yield 'kitty' => ["\x1b[98;5u"];
    }

    public function testCandidateDiscoveryDoesNotEnforceNativeDeadlines(): void
    {
        $process = $this->startPrepared($this->visibleRunId, 'expired-call', hrtime(true) - 1);
        $store = self::getContainer()->get(ProcessStore::class);
        $before = $store->snapshot($process->id);
        $this->assertNotNull($before);
        $this->assertNull($before->pid);
        foreach ([JsonlProcessAgentSessionClient::class, InProcessAgentSessionClient::class] as $clientClass) {
            $candidates = self::getContainer()->get($clientClass)->foregroundBash($this->visibleRunId);
            $this->assertCount(1, $candidates);
            $this->assertSame($process->id, $candidates[0]->id);
            $this->assertSame($process->pid, $candidates[0]->pid);
            $this->assertTrue(posix_kill($process->pid, 0), 'Only the native owner/poller may enforce the deadline.');
            $this->assertEquals($before, $store->snapshot($process->id), 'Candidate discovery must not write lifecycle metadata.');
        }
    }

    public function testPreparedCallWithoutPidEvidenceIsNotACandidate(): void
    {
        $batches = self::getContainer()->get(ToolBatchStoreInterface::class);
        $call = new ExecuteToolCall($this->visibleRunId, 1, 'unlaunched-step', 1, 'unlaunched-key', 'unlaunched-call', 'bash', ['command' => $this->command], 0);
        TestToolBatchRegistration::register(new ToolBatchCollector($batches), $batches, $this->visibleRunId, 1, 'unlaunched-step', [$call]);
        $this->manager->prepareNativeLaunch($this->visibleRunId, 'unlaunched-call', $this->command);
        $this->assertCount(1, self::getContainer()->get(ProcessStore::class)->fetchForegroundTools($this->visibleRunId));
        $this->assertSame([], $this->manager->foregroundTools($this->visibleRunId));
    }

    #[DataProvider('inputSequences')]
    public function testOneCandidateRoutesExactIdentityThroughConfiguredController(string $sequence): void
    {
        $process = $this->startPrepared($this->visibleRunId, 'call-visible');
        $foreign = $this->startPrepared($this->foreignRunId, 'call-foreign');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send')->willReturnCallback(function (string $runId, UserCommand $command) use ($process): void {
            self::assertSame($this->visibleRunId, $runId);
            self::assertSame(['record_id' => $process->id, 'tool_call_id' => 'call-visible'], $command->payload);
            self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new ControllerCommandEvent(
                new RuntimeCommand(id: 'ctrl-b', type: $command->type, runId: $runId, payload: $command->payload),
                static function (RuntimeEvent $event): void {
                    self::fail('Valid background target was refused: '.$event->type);
                },
            ));
        });
        $harness = $this->mount($this->visibleRunId, $client);
        $harness->sendInput($sequence);
        $this->assertFalse($this->manager->findByRecordId($process->id)?->backgroundRequested, 'Controller enqueue does not commit owner intent.');
        $transport = self::getContainer()->get('messenger.transport.run_control');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);
        $queued = iterator_to_array($transport->get());
        $this->assertCount(1, $queued);
        $this->assertInstanceOf(RequestBashBackgroundDTO::class, $queued[0]->getMessage());
        self::getContainer()->get('agent.command.bus')->dispatch($queued[0]->with(new ReceivedStamp('run_control')));
        $transport->ack($queued[0]);
        $this->assertTrue($this->manager->findByRecordId($process->id)?->backgroundRequested);
        $this->assertFalse($this->manager->findByRecordId($foreign->id)?->backgroundRequested);
        $harness->stopInputLoop();
    }

    public function testMultipleCandidatesUsePickerAndCtrlBSelectsOnlyHighlightedCall(): void
    {
        $first = $this->startPrepared($this->visibleRunId, 'first');
        $second = $this->startPrepared($this->visibleRunId, 'second');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send')->with($this->visibleRunId, $this->callback(
            static fn (UserCommand $command): bool => $command->payload === ['record_id' => $first->id, 'tool_call_id' => 'first'],
        ));
        $harness = $this->mount($this->visibleRunId, $client);
        $harness->sendInput("\x02");
        $this->assertTrue($harness->screen()->hasInputOverlay());
        $this->assertFalse($this->manager->findByRecordId($first->id)?->backgroundRequested);
        $this->assertFalse($this->manager->findByRecordId($second->id)?->backgroundRequested);
        // Native process queries are newest-first; Down selects the older call.
        $harness->sendInput("\x1b[B");
        $harness->sendInput("\x02");
        $this->assertFalse($harness->screen()->hasInputOverlay());
        $harness->stopInputLoop();
    }

    public function testNewDialogTakesPrecedenceOverOpenBackgroundPicker(): void
    {
        $this->startPrepared($this->visibleRunId, 'first');
        $this->startPrepared($this->visibleRunId, 'second');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $harness = $this->mount($this->visibleRunId, $client);
        $harness->sendInput("\x02");
        $dialog = new SelectListWidget(items: [['label' => 'Continue?', 'value' => 'yes']]);
        $harness->screen()->insertOverlayAfterEditor($dialog);
        $harness->tui()->setFocus($dialog);
        $harness->sendInput("\x02");
        $this->assertSame($dialog, $harness->tui()->getFocus());
        $harness->screen()->removeOverlay($dialog);
        $harness->stopInputLoop();
    }

    public function testNoCandidateAndExistingOverlayKeepEditorCursorLeft(): void
    {
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $harness = $this->mount($this->visibleRunId, $client);
        $harness->sendInput('abc');
        $harness->sendInput("\x02");
        $harness->sendInput('X');
        $this->assertSame('abXc', $harness->screen()->editorText());
        $this->startPrepared($this->visibleRunId, 'call');
        $overlay = new TextWidget(text: 'Active completion overlay');
        $harness->screen()->insertOverlayAfterEditor($overlay);
        $harness->sendInput("\x02");
        $this->assertTrue($harness->screen()->hasInputOverlay());
        $harness->screen()->removeOverlay($overlay);
        $harness->stopInputLoop();
    }

    public function testActualTextQuestionOwnsAnswerAfterBackgroundPickerOpens(): void
    {
        $this->startPrepared($this->visibleRunId, 'first');
        $this->startPrepared($this->visibleRunId, 'second');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $harness = $this->mount($this->visibleRunId, $client);
        self::getContainer()->get(SubmitListener::class)->register($this->context);
        $harness->sendInput("\x02");
        $this->assertInstanceOf(SelectListWidget::class, $harness->tui()->getFocus());
        $request = new QuestionRequest('real-text', QuestionKind::Text, 'What should happen?', runId: $this->visibleRunId);
        $answer = null;
        $this->context->sessionServices->questionCoordinator->enqueue($request, static function (mixed $value) use (&$answer): void { $answer = $value; });
        $this->context->sessionServices->questionController->open($request);
        $this->assertSame($harness->screen()->editorWidget(), $harness->tui()->getFocus());
        $harness->sendInput('my answer');
        $harness->sendInput("\r");
        $this->assertSame('my answer', $answer);
        $this->assertFalse($this->context->sessionServices->questionCoordinator->actionRequired());
        $this->assertFalse($harness->screen()->hasInputOverlay());
        $harness->stopInputLoop();
    }

    public function testLivePickerToggleClosesBackgroundPickerBeforeEscape(): void
    {
        $this->startPrepared($this->visibleRunId, 'first');
        $this->startPrepared($this->visibleRunId, 'second');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $state = new TuiSessionState($this->visibleRunId);
        $state->subagentLiveCatalog->ingestSnapshot(new SubagentProgressSnapshotDTO('running', 'scout', 'artifact', 'child', 'Inspect', 'test/model', 'off'));
        $harness = $this->mount($this->visibleRunId, $client, $state);
        (new SubagentLiveToggleInputListener())->register($this->context);
        $harness->sendInput("\x02");
        $this->assertTrue($harness->screen()->hasInputOverlay());
        $bashList = $harness->tui()->getFocus();
        $harness->sendInput("\x1c");
        $this->assertTrue($this->context->sessionServices->subagentLivePicker->isOpen());
        $this->assertNotSame($bashList, $harness->tui()->getFocus());
        $harness->sendInput("\x1b");
        $this->assertFalse($this->context->sessionServices->subagentLivePicker->isOpen());
        $this->assertFalse($harness->screen()->hasInputOverlay(), 'No abandoned Bash picker remains after the live picker closes.');
        $harness->sendInput('answer');
        $this->assertSame('answer', $harness->screen()->editorText());
        $harness->stopInputLoop();
    }

    public function testIneligibleChildViewDoesNotRetargetParentWait(): void
    {
        $parent = $this->startPrepared($this->parentRunId, 'parent-call');
        $this->manager->start($this->command, 'child');
        $state = new TuiSessionState($this->parentRunId);
        $state->subagentLiveView->active = true;
        $state->subagentLiveView->selected = new SubagentLiveChildDTO('child', 'artifact', 'child', SubagentLiveStatusEnum::Running, 'task', 0, 'test/model', 'off');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $harness = $this->mount($this->parentRunId, $client, $state);
        $harness->sendInput("\x02");
        $this->assertFalse($this->manager->findByRecordId($parent->id)?->backgroundRequested);
        $harness->stopInputLoop();
    }

    public function testStalePickerSelectionIsRefusedWithoutRetargetingSibling(): void
    {
        $first = $this->startPrepared($this->visibleRunId, 'first');
        $second = $this->startPrepared($this->visibleRunId, 'second');
        $errors = [];
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send')->willReturnCallback(static function (string $runId, UserCommand $command) use (&$errors): void {
            self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new ControllerCommandEvent(
                new RuntimeCommand(id: 'stale', type: $command->type, runId: $runId, payload: $command->payload),
                static function (RuntimeEvent $event) use (&$errors): void {
                    $errors[] = $event->type;
                },
            ));
        });
        $harness = $this->mount($this->visibleRunId, $client);
        $harness->sendInput("\x02");
        $this->manager->stopByRecordId($first->id, $this->visibleRunId);
        $harness->sendInput("\x1b[B");
        $harness->sendInput("\r");
        $this->assertSame([], $errors, 'The owner classifies stale selections after controller enqueue.');
        $container = self::getContainer();
        $transport = $container->get('messenger.transport.run_control');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);
        $queued = iterator_to_array($transport->get());
        $this->assertCount(1, $queued);
        $request = $queued[0]->getMessage();
        $this->assertInstanceOf(RequestBashBackgroundDTO::class, $request);
        $sink = new InMemoryRuntimeEventSink();
        $handler = new RequestBashBackgroundHandler($container->get(ProcessStore::class), $container->get(ProcessLifecycle::class), $container->get(RunLockManager::class), $container->get(ValidatorInterface::class), $sink);
        $handler($request);
        $transport->ack($queued[0]);
        $events = iterator_to_array($sink->drain($this->visibleRunId));
        $this->assertCount(1, $events);
        $this->assertSame('protocol.error', $events[0]->type);
        $this->assertFalse($this->manager->findByRecordId($second->id)?->backgroundRequested);
        $harness->stopInputLoop();
    }

    public function testCodeModePickerRoutesTheCapturedInvocation(): void
    {
        $this->startPrepared($this->visibleRunId, 'bash-call');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send')->with($this->visibleRunId, $this->callback(
            static fn (UserCommand $command): bool => 'background_code_mode' === $command->type
                && ['deferred_id' => 'invocation', 'tool_call_id' => 'host'] === $command->payload,
        ));
        $harness = $this->mount($this->visibleRunId, $client, snapshot: new BackgroundWorkSnapshotDTO([], [], [new ChildBackgroundCandidateDTO('invocation', 'host', 'code_mode host')]));
        $harness->sendInput("\x02");
        $this->assertTrue($harness->screen()->hasInputOverlay());
        $harness->sendInput("\x1b[B");
        $harness->sendInput("\x02");
        $this->assertFalse($harness->screen()->hasInputOverlay());
        $harness->stopInputLoop();
    }

    private function startPrepared(string $runId, string $toolCallId, ?int $deadline = null): StartResult
    {
        $batches = self::getContainer()->get(ToolBatchStoreInterface::class);
        $step = 'step-'.$toolCallId;
        $call = new ExecuteToolCall($runId, 1, $step, 1, 'key-'.$toolCallId, $toolCallId, 'bash', ['command' => $this->command], 0);
        TestToolBatchRegistration::register(new ToolBatchCollector($batches), $batches, $runId, 1, $step, [$call]);
        $prepared = $this->manager->prepareNativeLaunch($runId, $toolCallId, $this->command);

        return $this->manager->start(
            $this->command, $runId, $toolCallId, $deadline,
            preparedRecordId: $prepared->id,
            preparedLogPath: $prepared->logPath,
            preparedStatusPath: $prepared->statusPath,
        );
    }

    private function mount(string $runId, AgentSessionClient $client, ?TuiSessionState $state = null, ?BackgroundWorkSnapshotDTO $snapshot = null): VirtualTuiHarness
    {
        $runtimeClient = self::getContainer()->get(JsonlProcessAgentSessionClient::class);
        $client->method('foregroundBash')->willReturnCallback($runtimeClient->foregroundBash(...));
        $client->method('backgroundWork')->willReturn($snapshot ?? new BackgroundWorkSnapshotDTO([], []));
        $harness = new VirtualTuiHarness(sessionId: $runId);
        $state ??= new TuiSessionState($runId);
        $context = $this->context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withScreen($harness->screen())
            ->withState($state)
            ->withClient($client)
            ->withSessionServices($this->createSessionServices(tui: $harness->tui(), screen: $harness->screen(), state: $state, client: $client))
            ->build();
        (new BackgroundBashInputListener())->register($context);
        $harness->startInputLoop();

        return $harness;
    }
}
