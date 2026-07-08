<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Widget\SelectListWidget;

final class SubagentLivePickerControllerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/picker-export-test-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    #[Test]
    public function testOpenTwiceDoesNotStackOverlay(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-idempotent');
        $state = new TuiSessionState('picker-idempotent');
        $this->seedCatalogChild($state, 'agent_a', 'child-run-1', 'running');

        $picker = $this->picker($harness, $state);
        $picker->open();
        $this->assertTrue($picker->isOpen());
        $picker->open();
        $this->assertTrue($picker->isOpen());
    }

    #[Test]
    public function dismissKeyDoesNotRemoveRunningChild(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-dismiss');
        $state = new TuiSessionState('picker-dismiss');
        $this->seedCatalogChild($state, 'agent_running', 'child-run-running', 'running');

        $picker = $this->picker($harness, $state);
        $this->invokeDismissSelected($picker, $harness->screen(), $state);

        $this->assertCount(1, $state->subagentLiveCatalog->all());
        $this->assertStringContainsString(
            'Cannot remove active subagent scout',
            $this->workingMessage($harness->screen()),
        );
    }

    #[Test]
    public function dismissKeyRemovesCompletedChild(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-dismiss-done');
        $state = new TuiSessionState('picker-dismiss-done');
        $this->seedCatalogChild($state, 'agent_done', 'child-run-done', 'completed');

        $picker = $this->picker($harness, $state);
        $this->invokeDismissSelected($picker, $harness->screen(), $state);

        $this->assertCount(0, $state->subagentLiveCatalog->all());
        $msg = $this->workingMessage($harness->screen());
        // Last child dismissed: no working flash, status cleared
        $this->assertSame('', $msg);
        $entries = $this->statusEntries($harness->screen());
        $this->assertArrayNotHasKey('agents-live', $entries, 'agents-live status should be cleared after last dismiss');
    }

    #[Test]
    public function testEmptyOpenClearsWorkingMessageAndStatus(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-empty-open');
        $state = new TuiSessionState('picker-empty-open');

        $picker = $this->picker($harness, $state);
        $picker->open();

        $this->assertFalse($picker->isOpen(), 'Picker should not open when catalog is empty');
        $msg = $this->workingMessage($harness->screen());
        $this->assertSame('', $msg, 'Working message should be empty');
        $entries = $this->statusEntries($harness->screen());
        $this->assertArrayNotHasKey('agents-live', $entries, 'agents-live status should be absent/cleared');
    }

    #[Test]
    public function exportKeyWritesSelectedChildHtml(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-export');
        $state = new TuiSessionState('parent-session-export');
        $artifactId = 'agent_export';
        $this->seedCatalogChild($state, $artifactId, 'child-run-export', 'completed');
        $this->writeChildEvents('parent-session-export', $artifactId, [
            $this->makeChildEvent(1, 'run_started', ['user_messages' => [['role' => 'user', 'content' => 'fork task']]]),
        ]);

        $picker = $this->picker($harness, $state);
        $this->invokeExportSelected($picker, $harness->screen(), $state);

        $expected = getcwd().'/hatfield-child-'.$artifactId.'.html';
        $this->assertFileExists($expected);
        $this->assertStringContainsString('Session exported to:', $this->workingMessage($harness->screen()));
        @unlink($expected);
    }

    private function picker(VirtualTuiHarness $harness, TuiSessionState $state): SubagentLivePickerController
    {
        $picker = new SubagentLivePickerController(
            new SubagentLiveChildViewPoller(
                new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
                new NullLogger(),
            ),
            $this->sessionStore(),
            new SessionEventsExportService(),
        );
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), $state);

        return $picker;
    }

    private function invokeDismissSelected(
        SubagentLivePickerController $picker,
        ChatScreen $screen,
        TuiSessionState $state,
    ): void {
        $items = SubagentLivePickerController::buildItems($state->subagentLiveCatalog->all(), $screen->theme());
        $listWidget = new SelectListWidget(items: $items, keybindings: new Keybindings());
        $listWidget->setSelectedIndex(0);

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'dismissSelected');
        $children = $state->subagentLiveCatalog->all();
        $method->invokeArgs($picker, [&$listWidget, &$children, $screen->theme(), $screen, $state]);
    }

    private function seedCatalogChild(TuiSessionState $state, string $artifactId, string $runId, string $status): void
    {
        $state->subagentLiveCatalog->ingestRuntimeEvent(new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: 'parent-run',
            seq: 1,
            payload: [
                'tool_call_id' => 'tc_subagent',
                'tool_name' => 'subagent',
                'delta' => '',
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => $status,
                    'agent_name' => 'scout',
                    'artifact_id' => $artifactId,
                    'agent_run_id' => $runId,
                    'task_summary' => 'task',
                ],
            ],
        ));
    }

    private function invokeExportSelected(
        SubagentLivePickerController $picker,
        ChatScreen $screen,
        TuiSessionState $state,
    ): void {
        $items = SubagentLivePickerController::buildItems($state->subagentLiveCatalog->all(), $screen->theme());
        $listWidget = new SelectListWidget(items: $items, keybindings: new Keybindings());
        $listWidget->setSelectedIndex(0);

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'exportSelected');
        $method->invoke($picker, $listWidget, $screen, $state);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function writeChildEvents(string $parentSessionId, string $artifactId, array $events): void
    {
        $dir = $this->projectDir.'/.hatfield/sessions/'.$parentSessionId.'/artifacts/agents/'.$artifactId;
        mkdir($dir, 0777, true);
        $lines = array_map(static fn (array $e): string => json_encode($e, \JSON_THROW_ON_ERROR), $events);
        file_put_contents($dir.'/events.jsonl', implode("\n", $lines)."\n");
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function makeChildEvent(int $seq, string $type, array $payload = []): array
    {
        return [
            'schema_version' => '1.0',
            'run_id' => 'child-run-export',
            'seq' => $seq,
            'turn_no' => 1,
            'type' => $type,
            'payload' => $payload,
            'ts' => '2026-01-01T00:00:00+00:00',
        ];
    }

    private function sessionStore(): HatfieldSessionStore
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
            sessions: new SessionsConfig(path: '.hatfield/sessions'),
        );

        return new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(EntityManagerInterface::class),
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                @chmod($file->getPathname(), 0644);
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }

    private function workingMessage(ChatScreen $screen): string
    {
        $ref = new \ReflectionClass($screen);
        $registry = $ref->getProperty('registry');

        return $registry->getValue($screen)->getWorkingMessage();
    }

    /**
     * @return array<string, string>
     */
    private function statusEntries(ChatScreen $screen): array
    {
        $ref = new \ReflectionClass($screen);
        $registry = $ref->getProperty('registry');

        return $registry->getValue($screen)->getStatusEntries();
    }
}
