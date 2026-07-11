<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\ChildAgentExportEventsFixture;
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

    private string $previousCwd;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('picker-export-virtual');
        $this->previousCwd = getcwd() ?: '';
        chdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        if ('' !== $this->previousCwd) {
            chdir($this->previousCwd);
        }
        TestDirectoryIsolation::removeDirectory($this->projectDir);
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
    public function exportKeyWritesSelectedChildHtmlWithChildOnlyContent(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-export');
        $state = new TuiSessionState('parent-session-export');
        $artifactId = 'agent_export';
        $childRunId = 'child-run-export';
        $this->seedCatalogChild($state, $artifactId, $childRunId, 'completed');
        ChildAgentExportEventsFixture::write(
            $this->projectDir,
            'parent-session-export',
            $artifactId,
            [
                ChildAgentExportEventsFixture::childEvent(
                    $childRunId,
                    1,
                    'run_started',
                    ['user_messages' => [['role' => 'user', 'content' => 'child export unique marker']]],
                ),
            ],
        );

        $picker = $this->exportPicker($harness, $state);
        $this->invokeExportSelected($picker, $harness->screen(), $state);

        $expected = $this->projectDir.'/hatfield-child-'.$artifactId.'.html';
        $this->assertFileExists($expected);
        $html = file_get_contents($expected);
        $this->assertIsString($html);
        $this->assertStringContainsString('child export unique marker', $html);
        $this->assertStringContainsString('Child agent exported to:', $this->workingMessage($harness->screen()));
        $this->assertStringContainsString($expected, $this->workingMessage($harness->screen()));
    }

    #[Test]
    public function exportKeyReportsMissingEventsFileWithoutParentFallback(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-no-events');
        $state = new TuiSessionState('parent-session-no-events');
        $artifactId = 'agent_no_file';
        $this->seedCatalogChild($state, $artifactId, 'child-run-no-file', 'completed');
        $this->writeParentOnlyEvents('parent-session-no-events', 'parent-only marker must not appear in export');

        $picker = $this->exportPicker($harness, $state);
        $this->invokeExportSelected($picker, $harness->screen(), $state);

        $this->assertStringContainsString('has no events to export', $this->workingMessage($harness->screen()));
        $this->assertFileDoesNotExist($this->projectDir.'/hatfield-child-'.$artifactId.'.html');
    }

    #[Test]
    public function exportKeyReportsMalformedChildEventsWithoutParentFallback(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-bad-json');
        $state = new TuiSessionState('parent-session-bad-json');
        $artifactId = 'agent_bad_json';
        $this->seedCatalogChild($state, $artifactId, 'child-run-bad', 'completed');
        $dir = $this->projectDir.'/.hatfield/sessions/parent-session-bad-json/artifacts/agents/'.$artifactId;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/events.jsonl', '{not valid jsonl
');
        $this->writeParentOnlyEvents('parent-session-bad-json', 'parent fallback must not export');

        $picker = $this->exportPicker($harness, $state);
        $this->invokeExportSelected($picker, $harness->screen(), $state);

        $msg = $this->workingMessage($harness->screen());
        $this->assertNotSame('', $msg);
        $this->assertStringNotContainsString('parent fallback must not export', $msg);
        $this->assertFileDoesNotExist($this->projectDir.'/hatfield-child-'.$artifactId.'.html');
    }

    #[Test]
    public function exportKeyReportsChildAbsentFromCatalog(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-missing-child');
        $state = new TuiSessionState('parent-session-missing-child');
        $this->seedCatalogChild($state, 'agent_stale', 'child-run-stale', 'completed');

        $picker = $this->exportPicker($harness, $state);
        $items = SubagentLivePickerController::buildItems($state->subagentLiveCatalog->all(), $harness->screen()->theme());
        $listWidget = new SelectListWidget(items: $items, keybindings: new Keybindings());
        $listWidget->setSelectedIndex(0);
        $state->subagentLiveCatalog->dismissArtifactId('agent_stale');

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'exportSelected');
        $method->invoke($picker, $listWidget, $harness->screen(), $state);

        $this->assertStringContainsString(
            'no longer in the catalog',
            $this->workingMessage($harness->screen()),
        );
    }

    #[Test]
    public function exportKeyReportsNoSelectedChild(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-no-select');
        $state = new TuiSessionState('parent-session-no-select');
        $this->seedCatalogChild($state, 'agent_x', 'child-run-x', 'completed');

        $picker = $this->exportPicker($harness, $state);
        $listWidget = new SelectListWidget(items: [], keybindings: new Keybindings());

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'exportSelected');
        $method->invoke($picker, $listWidget, $harness->screen(), $state);

        $this->assertSame(
            'No child agent selected to export.',
            $this->workingMessage($harness->screen()),
        );
    }

    #[Test]
    public function enterLiveViewCallsSnapshotProviderOnceAndCachesTranscript(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-enter-snapshot');
        $state = new TuiSessionState('picker-enter-snapshot');
        $this->seedCatalogChild($state, 'agent_snap', 'child-run-snap', 'running');

        $child = $state->subagentLiveCatalog->findByArtifactId('agent_snap');
        $this->assertNotNull($child);

        $block = new \Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock(
            'snap-b',
            \Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum::Progress,
            'child-run-snap',
            4,
            'snapshot line',
        );

        $snapshotProvider = $this->createMock(ChildRunTranscriptSnapshotProviderInterface::class);
        $snapshotProvider->expects($this->once())
            ->method('snapshot')
            ->with('child-run-snap')
            ->willReturn(new ChildRunTranscriptSnapshotDTO([$block], [], 4));

        $picker = new SubagentLivePickerController(
            new SubagentLiveChildViewPoller(
                new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
                new NullLogger(),
            ),
            $snapshotProvider,
            $this->createStub(ChildAgentEventsPathResolverInterface::class),
            new SessionEventsExportService(),
        );
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), $state);

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'enterLiveView');
        $method->invoke($picker, $child, $state, $harness->screen());

        $this->assertSame(4, $state->subagentLiveView->childLastSeq);
        $this->assertSame('snapshot line', $state->subagentLiveView->childTranscript[0]->text);
        $this->assertArrayHasKey('child-run-snap', $state->subagentLiveView->childCaches);

        $snapshotProvider->expects($this->never())->method('snapshot');
        $method->invoke($picker, $child, $state, $harness->screen());
        $this->assertSame(4, $state->subagentLiveView->childLastSeq);
    }

    private function picker(VirtualTuiHarness $harness, TuiSessionState $state): SubagentLivePickerController
    {
        $picker = new SubagentLivePickerController(
            new SubagentLiveChildViewPoller(
                new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
                new NullLogger(),
            ),
            $this->createStub(ChildRunTranscriptSnapshotProviderInterface::class),
            $this->createStub(ChildAgentEventsPathResolverInterface::class),
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

    private function exportPicker(VirtualTuiHarness $harness, TuiSessionState $state): SubagentLivePickerController
    {
        $picker = new SubagentLivePickerController(
            new SubagentLiveChildViewPoller(
                new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
                new NullLogger(),
            ),
            $this->createStub(ChildRunTranscriptSnapshotProviderInterface::class),
            $this->childEventsPathResolver(),
            new SessionEventsExportService(),
        );
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), $state);

        return $picker;
    }

    private function childEventsPathResolver(): ChildAgentEventsPathResolverInterface
    {
        return new class($this->sessionStore()) implements ChildAgentEventsPathResolverInterface {
            public function __construct(private readonly HatfieldSessionStore $sessionStore)
            {
            }

            public function eventsPath(string $parentSessionId, string $artifactId): string
            {
                return $this->sessionStore->resolveSessionsBasePath().'/'.$parentSessionId.'/artifacts/agents/'.$artifactId.'/events.jsonl';
            }
        };
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

    private function writeParentOnlyEvents(string $parentSessionId, string $userContent): void
    {
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$parentSessionId;
        if (!is_dir($sessionDir) && !mkdir($sessionDir, 0777, true) && !is_dir($sessionDir)) {
            throw new \RuntimeException('Failed to create parent session dir');
        }
        $event = [
            'schema_version' => '1.0',
            'run_id' => $parentSessionId,
            'seq' => 1,
            'turn_no' => 1,
            'type' => 'run_started',
            'payload' => ['user_messages' => [['role' => 'user', 'content' => $userContent]]],
            'ts' => '2026-01-01T00:00:00+00:00',
        ];
        file_put_contents($sessionDir.'/events.jsonl', json_encode($event, \JSON_THROW_ON_ERROR)."\n");
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
