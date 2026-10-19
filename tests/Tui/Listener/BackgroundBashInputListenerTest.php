<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\Tui\Listener\BackgroundBashInputListener;
use Ineersa\Tui\Listener\SubagentLiveToggleInputListener;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class BackgroundBashInputListenerTest extends IsolatedKernelTestCase
{
    use TuiRuntimeContextBuilderTrait;

    private BackgroundProcessManager $manager;
    private string $directory;
    private string $command;
    private TuiRuntimeContext $context;

    protected function setUp(): void
    {
        parent::setUp();
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
        $process = $this->manager->start($this->command, 'visible', 'expired-call', hrtime(true) - 1);
        foreach ([JsonlProcessAgentSessionClient::class, InProcessAgentSessionClient::class] as $clientClass) {
            $candidates = self::getContainer()->get($clientClass)->foregroundBash('visible');
            $this->assertCount(1, $candidates);
            $this->assertSame($process->id, $candidates[0]->id);
            $this->assertTrue(posix_kill($process->pid, 0), 'Only the native owner/poller may enforce the deadline.');
            $this->assertNull($this->manager->findByRecordId($process->id)?->finishedAt);
        }
    }

    #[DataProvider('inputSequences')]
    public function testOneCandidateRoutesExactIdentityThroughConfiguredController(string $sequence): void
    {
        $process = $this->manager->start($this->command, 'visible', 'call-visible');
        $foreign = $this->manager->start($this->command, 'foreign', 'call-foreign');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send')->willReturnCallback(static function (string $runId, UserCommand $command) use ($process): void {
            self::assertSame('visible', $runId);
            self::assertSame(['record_id' => $process->id, 'tool_call_id' => 'call-visible'], $command->payload);
            self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new ControllerCommandEvent(
                new RuntimeCommand(id: 'ctrl-b', type: $command->type, runId: $runId, payload: $command->payload),
                static function (RuntimeEvent $event): void {
                    self::fail('Valid background target was refused: '.$event->type);
                },
            ));
        });
        $harness = $this->mount('visible', $client);
        $harness->sendInput($sequence);
        $this->assertTrue($this->manager->findByRecordId($process->id)?->backgroundRequested);
        $this->assertFalse($this->manager->findByRecordId($foreign->id)?->backgroundRequested);
        $harness->stopInputLoop();
    }

    public function testMultipleCandidatesUsePickerAndCtrlBSelectsOnlyHighlightedCall(): void
    {
        $first = $this->manager->start($this->command, 'visible', 'first');
        $second = $this->manager->start($this->command, 'visible', 'second');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send')->with('visible', $this->callback(
            static fn (UserCommand $command): bool => $command->payload === ['record_id' => $first->id, 'tool_call_id' => 'first'],
        ));
        $harness = $this->mount('visible', $client);
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
        $this->manager->start($this->command, 'visible', 'first');
        $this->manager->start($this->command, 'visible', 'second');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $harness = $this->mount('visible', $client);
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
        $harness = $this->mount('visible', $client);
        $harness->sendInput('abc');
        $harness->sendInput("\x02");
        $harness->sendInput('X');
        $this->assertSame('abXc', $harness->screen()->editorText());
        $this->manager->start($this->command, 'visible', 'call');
        $overlay = new TextWidget(text: 'Active completion overlay');
        $harness->screen()->insertOverlayAfterEditor($overlay);
        $harness->sendInput("\x02");
        $this->assertTrue($harness->screen()->hasInputOverlay());
        $harness->screen()->removeOverlay($overlay);
        $harness->stopInputLoop();
    }

    public function testActualTextQuestionOwnsAnswerAfterBackgroundPickerOpens(): void
    {
        $this->manager->start($this->command, 'visible', 'first');
        $this->manager->start($this->command, 'visible', 'second');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $harness = $this->mount('visible', $client);
        self::getContainer()->get(SubmitListener::class)->register($this->context);
        $harness->sendInput("\x02");
        $this->assertInstanceOf(SelectListWidget::class, $harness->tui()->getFocus());
        $request = new QuestionRequest('real-text', QuestionSource::AgentCore, QuestionKind::Text, 'What should happen?', runId: 'visible');
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
        $this->manager->start($this->command, 'visible', 'first');
        $this->manager->start($this->command, 'visible', 'second');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $state = new TuiSessionState('visible');
        $state->subagentLiveCatalog->ingestSnapshot(new SubagentProgressSingleSnapshotDTO('single', 'running', 'scout', 'artifact', 'child', 'Inspect', 'test/model', 'off'));
        $harness = $this->mount('visible', $client, $state);
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
        $parent = $this->manager->start($this->command, 'parent', 'parent-call');
        $this->manager->start($this->command, 'child');
        $state = new TuiSessionState('parent');
        $state->subagentLiveView->active = true;
        $state->subagentLiveView->selected = new SubagentLiveChildDTO('child', 'artifact', 'child', SubagentLiveStatusEnum::Running, 'task', 0, 'test/model', 'off');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $harness = $this->mount('parent', $client, $state);
        $harness->sendInput("\x02");
        $this->assertFalse($this->manager->findByRecordId($parent->id)?->backgroundRequested);
        $harness->stopInputLoop();
    }

    public function testStalePickerSelectionIsRefusedWithoutRetargetingSibling(): void
    {
        $first = $this->manager->start($this->command, 'visible', 'first');
        $second = $this->manager->start($this->command, 'visible', 'second');
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
        $harness = $this->mount('visible', $client);
        $harness->sendInput("\x02");
        $this->manager->stopByRecordId($first->id, 'visible');
        $harness->sendInput("\x1b[B");
        $harness->sendInput("\r");
        $this->assertSame(['protocol.error'], $errors);
        $this->assertFalse($this->manager->findByRecordId($second->id)?->backgroundRequested);
        $harness->stopInputLoop();
    }

    private function mount(string $runId, AgentSessionClient $client, ?TuiSessionState $state = null): VirtualTuiHarness
    {
        $runtimeClient = self::getContainer()->get(JsonlProcessAgentSessionClient::class);
        $client->method('foregroundBash')->willReturnCallback($runtimeClient->foregroundBash(...));
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
