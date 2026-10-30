<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Tests\Support\BackgroundProcessFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StartResult;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Listener\BackgroundProcessCommandRegistrar;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Tui\Event\TickEvent;
use Symfony\Component\Tui\Widget\SelectListWidget;

final class BackgroundProcessCommandHandlerTest extends IsolatedKernelTestCase
{
    use TuiRuntimeContextBuilderTrait;

    private BackgroundProcessManager $manager;
    private VirtualTuiHarness $harness;
    private TuiRuntimeContext $context;
    private AgentSessionClient $native;
    private string $directory;
    /** @var resource */
    private $release;
    private int $reads = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = self::getContainer()->get(BackgroundProcessManager::class);
        $this->directory = TestDirectoryIsolation::createOsTempDir('bg-picker');
        $this->assertTrue(posix_mkfifo($this->directory.'/release', 0600));
        $this->release = fopen($this->directory.'/release', 'r+b');
    }

    protected function tearDown(): void
    {
        if (isset($this->harness)) {
            $this->harness->stopInputLoop();
        }
        $this->manager->shutdownCleanup();
        fclose($this->release);
        TestDirectoryIsolation::removeDirectory($this->directory);
        parent::tearDown();
    }

    public static function clients(): iterable
    {
        yield 'process' => [JsonlProcessAgentSessionClient::class];
        yield 'in-process' => [InProcessAgentSessionClient::class];
    }

    #[DataProvider('clients')]
    public function testOpeningSnapshotSurvivesConfirmationTicksAndChangesUntilReopened(string $clientClass): void
    {
        $first = $this->start();
        $private = $this->start(accepted: false);
        $foreign = $this->start('foreign');
        $this->start();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->update('background_process', ['status' => BackgroundProcessStatusEnum::Stopped->value], ['id' => $first->id]);
        $this->mount($clientClass);
        $this->open();
        $this->assertSame(1, $this->reads);
        $screen = $this->harness->plainScreenText();
        $this->assertStringContainsString('bash [stopping]', $screen);
        $this->assertStringContainsString('PID '.$first->pid.' |', $screen);
        $this->assertStringContainsString('read -r release', $screen);
        $this->assertStringContainsString('s |', $screen);
        $this->assertStringNotContainsString('PID '.$private->pid.' |', $screen);
        $this->assertStringNotContainsString('PID '.$foreign->pid.' |', $screen);
        $later = $this->start();
        $this->harness->sendInput("\x1b[B");
        $this->harness->sendInput("\r");
        $this->assertSame('no', $this->list()->getSelectedItem()['value']);
        $this->assertStringContainsString('Cancel this background work?', $this->harness->plainScreenText());
        $this->context->ticks->dispatch(new TickEvent());
        $this->harness->sendInput("\r"); // Default No restores the opening snapshot.
        $this->assertStringNotContainsString('PID '.$later->pid.' |', $this->harness->plainScreenText());
        $this->harness->sendInput("\r");
        $this->harness->sendInput("\x1b"); // Confirmation Escape also restores it.
        $this->assertSame('1', $this->list()->getSelectedItem()['value']);
        $this->assertSame(1, $this->reads);
        $this->harness->sendInput("\x1b");
        $this->assertFalse($this->harness->screen()->hasInputOverlay());
        $this->assertSame($this->harness->screen()->editorWidget(), $this->harness->tui()->getFocus());
        $this->open();
        $this->assertSame(2, $this->reads);
        $this->assertStringContainsString('PID '.$later->pid.' |', $this->harness->plainScreenText());
    }

    #[DataProvider('clients')]
    public function testConfirmedStopRendersResultAndAcceptsInput(string $clientClass): void
    {
        // Intent/UI only. Native TERM→KILL escalation is proved by
        // BashRegistrationClientTest::testControllerAdoptsCommittedStopWhileFirstReplyIsLost
        // and BashWorkerLifecycleSubscriberTest scenarios; do not wait on OS exit here.
        $process = $this->start();
        $this->mount($clientClass);
        $this->open();
        $this->assertTrue(posix_kill($process->pid, 0), 'Opening the picker must never enforce deadlines.');
        $this->assertNull($this->manager->findByRecordId($process->id)->finishedAt);
        $before = \strlen($this->harness->terminal()->getOutput());
        $this->confirm();
        $rendered = substr($this->harness->terminal()->getOutput(), $before);
        if (JsonlProcessAgentSessionClient::class === $clientClass) {
            // This virtual harness has no controller. Process-backed stops now
            // refuse rather than mutating locally or starting a controller.
            $this->assertStringContainsString('Controller unavailable', $rendered);
            $this->assertFalse($this->manager->findByRecordId($process->id)->stoppedByUser);
            $this->harness->sendInput('responsive');
            $this->assertStringContainsString('responsive', substr($this->harness->terminal()->getOutput(), $before));

            return;
        }
        $this->assertStringContainsString('Cancellation requested', $rendered);
        $this->assertMatchesRegularExpression('/\e\[/', $rendered);
        $this->harness->sendInput('responsive');
        $this->assertStringContainsString('responsive', substr($this->harness->terminal()->getOutput(), $before));
        $record = $this->manager->findByRecordId($process->id);
        $this->assertTrue($record->stoppedByUser);
        $this->assertSame(1, $this->reads);
    }

    #[DataProvider('clients')]
    public function testFinishedSnapshotSelectionKeepsOrdinaryNotificationPending(string $clientClass): void
    {
        $process = $this->start();
        $this->mount($clientClass);
        $this->open();
        // Seed finished metadata directly. This proves picker UX for a finished
        // snapshot without waiting on OS exit; it does not prove native settlement
        // or model notification delivery.
        $entity = self::getContainer()->get(EntityManagerInterface::class)->find(BackgroundProcess::class, $process->id);
        $this->assertNotNull($entity);
        BackgroundProcessFixture::finish($entity, 0, new \DateTimeImmutable());
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->confirm();
        $this->assertNull($this->manager->findByRecordId($process->id)->completionNotifiedAt);
        $this->assertStringContainsString('finished.', $this->harness->plainScreenText());
        $this->assertSame(1, $this->reads);
    }

    public function testDeletedSnapshotRecordCannotRetargetReusedPid(): void
    {
        $process = $this->start();
        $this->mount(JsonlProcessAgentSessionClient::class);
        $this->open();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $old = $em->find(BackgroundProcess::class, $process->id);
        $replacement = clone $old;
        $replacement->id = 0;
        $em->remove($old);
        $em->flush();
        $em->persist($replacement);
        $em->flush();
        try {
            $this->assertNotSame($process->id, $replacement->id);
            $this->confirm();
            $this->assertTrue(posix_kill($process->pid, 0), 'Stale ID must not stop another row with the same PID.');
            $this->assertNull($replacement->finishedAt);
            $this->assertStringContainsString('termination is not confirmed', $this->harness->plainScreenText());
        } finally {
            $this->manager->stopByRecordId($replacement->id, 'visible');
        }
    }

    public function testLiveChildScopeCannotListOrStopParentAndModalKeepsFocus(): void
    {
        $parent = $this->start();
        $this->mount(JsonlProcessAgentSessionClient::class);
        $this->context->state->subagentLiveView->active = true;
        $this->context->state->subagentLiveView->selected = new SubagentLiveChildDTO('child', 'artifact', 'child', SubagentLiveStatusEnum::Running, 'task', 0, 'test/model', 'off');
        $this->open();
        $this->assertFalse($this->harness->screen()->hasInputOverlay());
        $this->assertNull($this->manager->findByRecordId($parent->id)->finishedAt);
        $this->context->state->subagentLiveView->active = false;
        $this->open();
        $list = $this->list();
        $dialog = new SelectListWidget(items: [['value' => 'ok', 'label' => 'Another dialog']]);
        $this->harness->screen()->insertOverlayAfterEditor($dialog);
        $this->harness->tui()->setFocus($dialog);
        $this->harness->sendInput("\r");
        $this->assertSame($dialog, $this->harness->tui()->getFocus());
        $this->assertSame('0', $list->getSelectedItem()['value']);
        $this->assertNull($this->manager->findByRecordId($parent->id)->finishedAt);
        $this->harness->screen()->removeOverlay($dialog);
        $this->assertFalse($this->harness->screen()->hasInputOverlay(), 'No abandoned background picker remains after focus changes.');
        $this->harness->tui()->setFocus($this->harness->screen()->editorWidget());
        $this->open();
        $this->context->state->sessionId = 'changed';
        $this->confirm();
        $this->assertNull($this->manager->findByRecordId($parent->id)->finishedAt);
        $this->assertStringContainsString('visible session changed', $this->harness->plainScreenText());
    }

    #[DataProvider('clients')]
    public function testClientRefusesPrivateOrForeignRecords(string $clientClass): void
    {
        $private = $this->start(accepted: false);
        $foreign = $this->start('foreign');
        $client = self::getContainer()->get($clientClass);
        foreach ([$private, $foreign] as $process) {
            try {
                $client->stopBackgroundProcess('visible', $process->id);
                $this->fail('The record must not be stopped from this visible session.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString(JsonlProcessAgentSessionClient::class === $clientClass ? 'Controller unavailable' : 'no longer available', $e->getMessage());
            }
            $this->assertTrue(posix_kill($process->pid, 0));
            $this->assertNull($this->manager->findByRecordId($process->id)->finishedAt);
        }
    }

    public function testConfirmedCodeModeStopTargetsOnlyCapturedInvocation(): void
    {
        $snapshot = new BackgroundWorkSnapshotDTO([], [new BackgroundWorkDTO('invocation', 'code_mode', 'retained host', 'running', false, ['owner'], toolCallId: 'host')]);
        $sent = [];
        $this->mount(JsonlProcessAgentSessionClient::class, $snapshot, static function (string $run, UserCommand $command) use (&$sent): void { $sent[] = [$run, $command]; });
        $this->open();
        $this->assertSame([], $sent);
        $this->confirm();
        $this->assertCount(1, $sent);
        $this->assertSame('owner', $sent[0][0]);
        $this->assertSame('stop_code_mode', $sent[0][1]->type);
        $this->assertSame(['deferred_id' => 'invocation', 'tool_call_id' => 'host'], $sent[0][1]->payload);
        $this->assertFalse($this->harness->screen()->hasInputOverlay());
    }

    private function start(string $runId = 'visible', bool $accepted = true): StartResult
    {
        $command = 'read -r release < '.escapeshellarg($this->directory.'/release');
        $toolCallId = 'call-'.bin2hex(random_bytes(4));
        $prepared = $this->manager->prepareNativeLaunch($runId, $toolCallId, $command, true);
        $process = $this->manager->start(
            $command,
            $runId,
            $toolCallId,
            preparedRecordId: $prepared->id,
            preparedLogPath: $prepared->logPath,
            preparedStatusPath: $prepared->statusPath,
        );
        // Local UI fixture: persist launch facts without waiting for owner receipt.
        self::getContainer()->get(\Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore::class)->applyLaunch(
            $prepared->id,
            $runId,
            $process->pid,
            posix_getpgid($process->pid) ?: null,
            new \DateTimeImmutable(),
        );
        if ($accepted) {
            $candidate = $this->manager->resolveBackgroundAcceptance($process->statusPath, $runId);
            $this->assertNotNull($candidate);
            $this->manager->acceptResolvedBackground($candidate);
        }

        return $process;
    }

    private function mount(string $clientClass, ?BackgroundWorkSnapshotDTO $snapshot = null, ?\Closure $send = null): void
    {
        $this->native = self::getContainer()->get($clientClass);
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('start');
        $client->expects(null === $send ? $this->never() : $this->once())->method('send')->willReturnCallback($send ?? static fn () => null);
        $client->expects($this->never())->method('cancel');
        $client->method('backgroundWork')->willReturnCallback(function (string $runId, ?int $observedProcessId = null) use ($snapshot): BackgroundWorkSnapshotDTO {
            // Opening snapshots are counted separately from exact-record stop
            // revalidation and UI-only settlement; those must not refresh an open picker.
            if (null === $observedProcessId) {
                ++$this->reads;
            }

            return $snapshot ?? $this->native->backgroundWork($runId, $observedProcessId);
        });
        $client->method('stopBackgroundProcess')->willReturnCallback(function (string $runId, int $id): bool {
            $this->assertFalse($this->harness->screen()->hasInputOverlay(), 'Close before the native stop action.');
            $this->assertSame($this->harness->screen()->editorWidget(), $this->harness->tui()->getFocus());

            return $this->native->stopBackgroundProcess($runId, $id);
        });
        $this->harness = new VirtualTuiHarness(sessionId: 'visible');
        $state = new TuiSessionState('visible');
        $catalog = new SlashCommandCatalog();
        $registrar = self::getContainer()->get(BackgroundProcessCommandRegistrar::class);
        $registrar->registerCatalog($catalog);
        $services = $this->createSessionServices(tui: $this->harness->tui(), screen: $this->harness->screen(), state: $state, client: $client, catalog: $catalog);
        $this->context = $this->buildTuiContext()->withTui($this->harness->tui())->withScreen($this->harness->screen())->withState($state)->withClient($client)->withSessionServices($services)->build();
        $registrar->register($this->context);
        self::getContainer()->get(SubmitListener::class)->register($this->context);
        $this->harness->startInputLoop();
    }

    private function open(): void
    {
        $this->harness->sendInput('/bg');
        $this->harness->sendInput("\r");
    }

    private function confirm(): void
    {
        $this->harness->sendInput("\r");
        $this->harness->sendInput("\x1b[B");
        $this->harness->sendInput("\r");
    }

    private function list(): SelectListWidget
    {
        $list = $this->harness->tui()->getFocus();
        $this->assertInstanceOf(SelectListWidget::class, $list);

        return $list;
    }
}
