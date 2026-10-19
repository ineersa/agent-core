<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
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
        $this->manager->findByRecordId($first->id)->status = BackgroundProcessStatusEnum::Stopped;
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->mount($clientClass);
        $this->open();
        $this->assertSame(1, $this->reads);
        $screen = $this->harness->plainScreenText();
        $this->assertStringContainsString('PID '.$first->pid.' | stopping |', $screen);
        $this->assertStringContainsString('read -r release', $screen);
        $this->assertStringContainsString('s |', $screen);
        $this->assertStringNotContainsString('PID '.$private->pid.' |', $screen);
        $this->assertStringNotContainsString('PID '.$foreign->pid.' |', $screen);
        $later = $this->start();
        $this->harness->sendInput("\x1b[B");
        $this->harness->sendInput("\r");
        $this->assertSame('no', $this->list()->getSelectedItem()['value']);
        $this->assertStringContainsString('Stop this process?', $this->harness->plainScreenText());
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
    public function testConfirmedStopUsesNativeClientAfterClosingAndNeverReloads(string $clientClass): void
    {
        $process = $this->start();
        $this->manager->findByRecordId($process->id)->deadlineNanoseconds = hrtime(true) - 1;
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->mount($clientClass);
        $this->open();
        $this->assertTrue(posix_kill($process->pid, 0), 'Opening the picker must never enforce deadlines.');
        $this->assertNull($this->manager->findByRecordId($process->id)->finishedAt);
        $this->confirm();
        $record = $this->manager->findByRecordId($process->id);
        $this->assertTrue($record->stoppedByUser);
        $this->assertNotNull($record->finishedAt);
        $this->assertNotNull($record->completionNotifiedAt);
        $this->assertSame(1, $this->reads);
        $this->assertStringContainsString('Process PID '.$process->pid.' stopped.', $this->harness->plainScreenText());
        $this->open();
        $this->assertSame(2, $this->reads);
        $this->assertFalse($this->harness->screen()->hasInputOverlay());
        $this->assertStringContainsString('No background processes running.', $this->harness->plainScreenText());
    }

    #[DataProvider('clients')]
    public function testFinishedSnapshotSelectionKeepsOrdinaryNotificationPending(string $clientClass): void
    {
        $process = $this->start();
        $this->mount($clientClass);
        $this->open();
        fwrite($this->release, "release\n");
        $deadline = hrtime(true) + 2_000_000_000;
        do {
            $record = $this->manager->findByRecordId($process->id);
            if (null !== $record->finishedAt) {
                break;
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);
        $this->assertNotNull($record->finishedAt);
        $this->confirm();
        $this->assertNull($this->manager->findByRecordId($process->id)->completionNotifiedAt);
        $this->assertStringContainsString('had already finished', $this->harness->plainScreenText());
        $this->assertSame(1, $this->reads);
    }

    public function testDeletedSnapshotRecordCannotRetargetReusedPid(): void
    {
        $process = $this->start();
        $this->mount(JsonlProcessAgentSessionClient::class);
        $this->open();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $old = $this->manager->findByRecordId($process->id);
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
            $this->assertStringContainsString('no longer available', $this->harness->plainScreenText());
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
                $this->assertStringContainsString('no longer available', $e->getMessage());
            }
            $this->assertTrue(posix_kill($process->pid, 0));
            $this->assertNull($this->manager->findByRecordId($process->id)->finishedAt);
        }
    }

    private function start(string $runId = 'visible', bool $accepted = true): StartResult
    {
        $process = $this->manager->start('read -r release < '.escapeshellarg($this->directory.'/release'), $runId, 'call');
        if ($accepted) {
            $this->manager->markBackgroundedForRecord($process->id, $runId);
        }

        return $process;
    }

    private function mount(string $clientClass): void
    {
        $this->native = self::getContainer()->get($clientClass);
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('start');
        $client->expects($this->never())->method('send');
        $client->method('backgroundProcesses')->willReturnCallback(function (string $runId): array {
            ++$this->reads;

            return $this->native->backgroundProcesses($runId);
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
