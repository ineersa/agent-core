<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Tests\Support\SessionEventsExportServiceFactory;
use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\ChildAgentExportEventsFixture;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Widget\SelectListWidget;

final class SubagentLivePickerControllerTest extends TestCase
{
    private string $projectDir;

    private string $previousCwd;

    private int $seedActivityMs = 1_000_000;

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
    public function testOpenPickerKeepsSnapshotUntilReopened(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-status-snapshot');
        $state = new TuiSessionState('picker-status-snapshot');
        $this->seedCatalogChild($state, 'agent_running', 'child-run-running', 'running');

        $picker = $this->picker($harness, $state);
        $picker->open();
        $this->assertStringContainsString('[running]', $harness->plainScreenText());

        $state->subagentLiveCatalog->applyChildStatus('agent_running', SubagentLiveStatusEnum::Completed);
        $picker->refreshPickerFeedbackIfOpen();
        $this->assertStringContainsString('[running]', $harness->plainScreenText());

        $picker->closePicker();
        $picker->open();
        $screen = $harness->plainScreenText();
        $this->assertStringNotContainsString('[running]', $screen);
        $this->assertStringContainsString('[completed]', $screen);
    }

    #[Test]
    public function testDismissLastSnapshotRowDoesNotImportChildAddedAfterOpen(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-dismiss-snapshot');
        $state = new TuiSessionState('picker-dismiss-snapshot');
        $this->seedCatalogChild($state, 'agent_snapshot', 'child-run-snapshot', 'completed');

        $picker = $this->picker($harness, $state);
        $picker->open();

        $overlayProperty = new \ReflectionProperty(SubagentLivePickerController::class, 'overlay');
        $overlay = $overlayProperty->getValue($picker);
        $this->assertInstanceOf(PickerOverlay::class, $overlay);
        $listWidget = $overlay->listWidget();
        $this->assertInstanceOf(SelectListWidget::class, $listWidget);

        $this->seedCatalogChild($state, 'agent_after_open', 'child-run-after-open', 'completed');

        $harness->startInputLoop();
        try {
            $harness->tui()->setFocus($listWidget);
            $harness->sendInput('d');
        } finally {
            $harness->stopInputLoop();
        }

        $this->assertFalse($picker->isOpen());
        $this->assertNull($state->subagentLiveCatalog->findByArtifactId('agent_snapshot'));
        $this->assertNotNull($state->subagentLiveCatalog->findByArtifactId('agent_after_open'));
    }

    #[Test]
    public function dismissKeyDoesNotRemoveRunningChild(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-dismiss');
        $state = new TuiSessionState('picker-dismiss');
        $this->seedCatalogChild($state, 'agent_running', 'child-run-running', 'running');

        $picker = $this->picker($harness, $state);
        $picker->open();
        $this->invokeDismissSelected($picker, $harness->screen(), $state);

        $this->assertCount(1, $state->subagentLiveCatalog->all());
        $this->assertStringContainsString(
            'Cannot remove active subagent scout',
            (string) $state->subagentLiveView->pickerFeedbackMessage,
        );
        $this->assertStringContainsString(
            'Cannot remove active subagent scout',
            $this->workingMessage($harness->screen()),
        );
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
                    ['payload' => ['messages' => [['role' => 'user', 'content' => 'child export unique marker']]]],
                ),
            ],
        );

        $picker = $this->exportPicker($harness, $state);
        $picker->open();
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
        $picker->open();
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
        $picker->open();
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
        $picker->open();
        $items = SubagentLivePickerController::buildItems($state->subagentLiveCatalog->all());
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
        $picker->open();
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
            $harness->tui(),
            $harness->screen(),
            $state,
            $this->createStub(AgentSessionClient::class),
            new SubagentLiveChildViewPoller(new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()), new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer()),
            $snapshotProvider,
            $this->createStub(ChildAgentEventsPathResolverInterface::class),
            SessionEventsExportServiceFactory::create(),
        );

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'enterLiveView');
        $method->invoke($picker, $child, $state, $harness->screen());

        $this->assertSame(4, $state->subagentLiveView->childLastSeq);
        $this->assertSame('snapshot line', $state->subagentLiveView->childTranscript[0]->text);
        $this->assertArrayHasKey('child-run-snap', $state->subagentLiveView->childCaches);

        $snapshotProvider->expects($this->never())->method('snapshot');
        $method->invoke($picker, $child, $state, $harness->screen());
        $this->assertSame(4, $state->subagentLiveView->childLastSeq);
    }

    /**
     * Test thesis: before the fix, Down moved the native →/bold selection to row 2 while
     * row 1 kept a baked ThemeColorEnum::Accent label, so two rows looked selected. After
     * the fix, exactly one native SelectListWidget highlight exists and moves with Down;
     * dismiss rebuilds plain items with a single native selection at index 0.
     */
    #[Test]
    public function buildItemsSanitizesMultilineTaskSummaryBeforeTruncation(): void
    {
        $state = new TuiSessionState('picker-sanitize-row');
        // Shared catalog path: both fork and subagent children reach buildItems() the same way.
        $this->seedCatalogChild(
            $state,
            'agent_fork_nl',
            'fork-run-nl',
            'completed',
            agentName: 'fork',
            task: "Fork tool interactive test.\r\n\n\tYour task, in order, is to validate multiline rows",
        );
        $this->seedCatalogChild(
            $state,
            'agent_scout_nl',
            'scout-run-nl',
            'running',
            agentName: 'scout',
            task: "Inspect docs\n\twith   tabs and   spaces",
        );

        $items = SubagentLivePickerController::buildItems($state->subagentLiveCatalog->all());
        $this->assertCount(2, $items);

        foreach ($items as $item) {
            $label = $item['label'];
            $this->assertStringNotContainsString("\n", $label, 'Picker labels must be single-line (no LF)');
            $this->assertStringNotContainsString("\r", $label, 'Picker labels must be single-line (no CR)');
            $this->assertStringNotContainsString("\t", $label, 'Picker labels must collapse tabs');
            $this->assertDoesNotMatchRegularExpression('/  +/', $label, 'Picker labels must collapse repeated whitespace');
        }

        $this->assertStringContainsString('Fork tool interactive test. Your task, in ord...', $items[0]['label']);
        $this->assertStringContainsString('Inspect docs with tabs and spaces', $items[1]['label']);
        $this->assertStringNotContainsString('Your task, in order', $items[0]['label'], 'Sanitized summary must truncate after whitespace collapse');
    }

    #[Test]
    public function testArrowNavigationMovesSingleNativeHighlight(): void
    {
        $base = VirtualTuiHarness::defaultVirtualPalette();
        $palette = new ThemePalette(
            $base->name,
            array_merge($base->colors, [ThemeColorEnum::Accent->value => 'magenta']),
        );
        $harness = new VirtualTuiHarness(
            sessionId: 'picker-native-highlight',
            palette: $palette,
            columns: 140,
            rows: 40,
        );
        $state = new TuiSessionState('picker-native-highlight');
        $this->seedCatalogChild($state, 'agent_alpha', 'child-run-alpha', 'completed', agentName: 'alpha', task: 'Alpha unique task');
        $this->seedCatalogChild($state, 'agent_bravo', 'child-run-bravo', 'completed', agentName: 'bravo', task: 'Bravo unique task');
        $this->seedCatalogChild($state, 'agent_charlie', 'child-run-charlie', 'completed', agentName: 'charlie', task: 'Charlie unique task');

        $picker = $this->picker($harness, $state);
        $picker->open();
        $this->assertTrue($picker->isOpen());

        $overlayRef = new \ReflectionProperty(SubagentLivePickerController::class, 'overlay');
        $overlay = $overlayRef->getValue($picker);
        $this->assertInstanceOf(PickerOverlay::class, $overlay);
        $list = $overlay->listWidget();
        $this->assertInstanceOf(SelectListWidget::class, $list);
        $this->assertSame(0, $this->selectedIndex($list));

        $itemsProp = new \ReflectionProperty(SelectListWidget::class, 'items');
        $items = $itemsProp->getValue($list);
        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertStringNotContainsString("\x1b[", $item['label'], 'Labels must stay plain; native widget owns highlight');
        }

        $accentProbe = $harness->screen()->theme()->color(ThemeColorEnum::Accent, 'PROBE');
        $this->assertStringContainsString("\x1b[35m", $accentProbe, 'Visible Accent palette required so pre-fix dual-highlight would fail');

        $harness->startInputLoop();
        try {
            $harness->tui()->setFocus($list);
            $harness->render();

            $initial = $this->pickerRowStyles($harness, ['agent_alpha', 'agent_bravo', 'agent_charlie']);
            $this->assertTrue($initial['agent_alpha']['native'], 'Row 1 must start as the single native selection');
            $this->assertFalse($initial['agent_bravo']['native']);
            $this->assertFalse($initial['agent_charlie']['native']);
            $this->assertFalse($initial['agent_alpha']['accent'], 'Row 1 must not also carry manual Accent');
            $this->assertFalse($initial['agent_bravo']['accent']);
            $this->assertFalse($initial['agent_charlie']['accent']);
            $this->assertSame(1, $this->countNativeRows($initial));

            $harness->sendInput("\x1b[B"); // Down
            $this->assertSame(1, $this->selectedIndex($list));

            $afterDown = $this->pickerRowStyles($harness, ['agent_alpha', 'agent_bravo', 'agent_charlie']);
            $this->assertFalse($afterDown['agent_alpha']['native'], 'Row 1 must lose native selection after Down');
            $this->assertTrue($afterDown['agent_bravo']['native'], 'Row 2 must become the single native selection');
            $this->assertFalse($afterDown['agent_charlie']['native']);
            $this->assertFalse($afterDown['agent_alpha']['accent'], 'Row 1 must not retain manual Accent after Down');
            $this->assertFalse($afterDown['agent_bravo']['accent']);
            $this->assertFalse($afterDown['agent_charlie']['accent']);
            $this->assertSame(1, $this->countNativeRows($afterDown));
            $this->assertCount(3, $itemsProp->getValue($list));

            $harness->sendInput('d');
            $this->assertTrue($picker->isOpen(), 'Dismiss of one completed child must keep picker open');
            $this->assertNull($state->subagentLiveCatalog->findByArtifactId('agent_bravo'));
            $this->assertCount(2, $state->subagentLiveCatalog->all());
            $this->assertSame(0, $this->selectedIndex($list));
            $this->assertCount(2, $itemsProp->getValue($list));
            foreach ($itemsProp->getValue($list) as $item) {
                $this->assertStringNotContainsString("\x1b[", $item['label']);
            }

            $afterDismiss = $this->pickerRowStyles($harness, ['agent_alpha', 'agent_charlie']);
            $this->assertTrue($afterDismiss['agent_alpha']['native']);
            $this->assertFalse($afterDismiss['agent_charlie']['native']);
            $this->assertFalse($afterDismiss['agent_alpha']['accent']);
            $this->assertFalse($afterDismiss['agent_charlie']['accent']);
            $this->assertSame(1, $this->countNativeRows($afterDismiss));
        } finally {
            $harness->stopInputLoop();
        }
    }

    #[Test]
    public function testCatalogRepeatedNestedProgressDoesNotDuplicatePickerRows(): void
    {
        $state = new TuiSessionState('parent-picker-dedupe');
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: 'fork-run',
            seq: 2,
            payload: [
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'running',
                    'agent_name' => 'scout',
                    'artifact_id' => 'agent_scout',
                    'agent_run_id' => 'scout-run',
                    'task_summary' => 'list docs',
                    'model' => 'deepseek/deepseek-v4-flash',
                    'reasoning' => 'medium',
                ],
            ],
        );

        for ($i = 0; $i < 5; ++$i) {
            SubagentProgressSerializerTestSupport::ingestCatalogEvent($state->subagentLiveCatalog, $event);
        }

        $this->assertCount(1, $state->subagentLiveCatalog->all());

        $harness = new VirtualTuiHarness(sessionId: 'parent-picker-dedupe');
        $this->seedCatalogChild($state, 'agent_fork', 'fork-run', 'running');
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($state->subagentLiveCatalog, $event);

        $picker = $this->picker($harness, $state);
        $picker->open();
        $this->assertTrue($picker->isOpen());

        $overlayRef = new \ReflectionProperty(SubagentLivePickerController::class, 'overlay');
        $overlay = $overlayRef->getValue($picker);
        $this->assertNotNull($overlay);
        $list = $overlay->listWidget();
        $this->assertNotNull($list);
        $this->assertCount(2, $state->subagentLiveCatalog->all());

        $itemsProp = new \ReflectionProperty(SelectListWidget::class, 'items');
        $this->assertCount(2, $itemsProp->getValue($list));
    }

    #[Test]
    public function pickerRowShowsChildContextUsageSuffix(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-child-ctx');
        $state = new TuiSessionState('picker-child-ctx');
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($state->subagentLiveCatalog, new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: 'parent-run',
            seq: 1,
            payload: [
                'tool_call_id' => 'tc_subagent',
                'tool_name' => 'subagent',
                'delta' => '',
                'subagent_progress' => array_merge([
                    'mode' => 'single',
                    'status' => 'completed',
                    'agent_name' => 'scout',
                    'artifact_id' => 'agent_ctx',
                    'agent_run_id' => 'child-run-ctx',
                    'task_summary' => 'Context stats', 'reasoning' => 'medium',
                ], \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::progressPayloadOverrides()),
            ],
        ));

        $items = SubagentLivePickerController::buildItems($state->subagentLiveCatalog->all());
        $this->assertNotEmpty($items);
        $label = preg_replace('/\x1b\[[0-9;]*m/', '', $items[0]['label']) ?? $items[0]['label'];
        $this->assertStringContainsString(\Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::CONTEXT_DETAIL, $label);
        $this->assertStringContainsString(\Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::MODEL_SHORT, $label);
    }

    #[Test]
    public function exportFeedbackStoredInPickerHeaderAndState(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-export-persist');
        $state = new TuiSessionState('parent-session-export-persist');
        $artifactId = 'agent_export_persist';
        $this->seedCatalogChild($state, $artifactId, 'child-run-export-persist', 'completed');
        ChildAgentExportEventsFixture::write(
            $this->projectDir,
            'parent-session-export-persist',
            $artifactId,
            [
                ChildAgentExportEventsFixture::childEvent(
                    'child-run-export-persist',
                    1,
                    'run_started',
                    ['payload' => ['messages' => [['role' => 'user', 'content' => 'persist marker']]]],
                ),
            ],
        );

        $picker = $this->exportPicker($harness, $state);
        $picker->open();
        $this->invokeExportSelected($picker, $harness->screen(), $state);

        $expected = $this->projectDir.'/hatfield-child-'.$artifactId.'.html';
        $feedback = (string) $state->subagentLiveView->pickerFeedbackMessage;
        $this->assertStringContainsString('Child agent exported to:', $feedback);
        $this->assertStringContainsString($expected, $feedback);
        $header = $this->pickerHeaderPlain($picker);
        $this->assertStringContainsString('Child agent exported to:', $header);
        $this->assertStringContainsString($expected, $header);
    }

    #[Test]
    public function exportFailureFeedbackStoredInPickerHeader(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-export-fail-header');
        $state = new TuiSessionState('parent-session-export-fail-header');
        $artifactId = 'agent_no_file_hdr';
        $this->seedCatalogChild($state, $artifactId, 'child-run-no-file', 'completed');
        $picker = $this->exportPicker($harness, $state);
        $picker->open();
        $this->invokeExportSelected($picker, $harness->screen(), $state);

        $feedback = (string) $state->subagentLiveView->pickerFeedbackMessage;
        $this->assertStringContainsString('has no events to export', $feedback);
        $this->assertStringContainsString('has no events to export', $this->pickerHeaderPlain($picker));
    }

    #[Test]
    public function closePickerClearsExportFeedbackState(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-close-feedback');
        $state = new TuiSessionState('picker-close-feedback');
        $this->seedCatalogChild($state, 'agent_close', 'child-close', 'completed');
        $picker = $this->picker($harness, $state);
        $picker->open();
        $state->subagentLiveView->pickerFeedbackMessage = 'Child agent exported to: /tmp/x.html';
        $picker->closePicker();
        $this->assertNull($state->subagentLiveView->pickerFeedbackMessage);
        $this->assertNull($state->subagentLiveView->lastPickerFeedbackWorkingMessage);
    }

    #[Test]
    public function dismissFeedbackReplacesStaleExportFeedbackInPickerHeader(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-dismiss-feedback');
        $state = new TuiSessionState('picker-dismiss-feedback');
        $artifactId = 'agent_dismiss_done';
        $this->seedCatalogChild($state, $artifactId, 'child-dismiss-done', 'completed');
        $this->seedCatalogChild($state, 'agent_keep', 'child-keep', 'completed');
        ChildAgentExportEventsFixture::write(
            $this->projectDir,
            'picker-dismiss-feedback',
            $artifactId,
            [
                ChildAgentExportEventsFixture::childEvent(
                    'child-dismiss-done',
                    1,
                    'run_started',
                    ['payload' => ['messages' => [['role' => 'user', 'content' => 'dismiss feedback marker']]]],
                ),
            ],
        );
        $picker = $this->exportPicker($harness, $state);
        $picker->open();
        $this->invokeExportSelected($picker, $harness->screen(), $state);
        $this->assertStringContainsString('Child agent exported to:', (string) $state->subagentLiveView->pickerFeedbackMessage);

        $this->invokeDismissSelected($picker, $harness->screen(), $state);

        $feedback = (string) $state->subagentLiveView->pickerFeedbackMessage;
        $this->assertStringContainsString('Removed scout from /agents-live.', $feedback);
        $this->assertStringNotContainsString('Child agent exported to:', $feedback);
        $this->assertStringContainsString('Removed scout from /agents-live.', $this->pickerHeaderPlain($picker));
    }

    private function picker(VirtualTuiHarness $harness, TuiSessionState $state): SubagentLivePickerController
    {
        return new SubagentLivePickerController(
            $harness->tui(),
            $harness->screen(),
            $state,
            $this->createStub(AgentSessionClient::class),
            new SubagentLiveChildViewPoller(new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()), new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer()),
            $this->createStub(ChildRunTranscriptSnapshotProviderInterface::class),
            $this->createStub(ChildAgentEventsPathResolverInterface::class),
            SessionEventsExportServiceFactory::create(),
        );
    }

    private function invokeDismissSelected(
        SubagentLivePickerController $picker,
        ChatScreen $screen,
        TuiSessionState $state,
    ): void {
        if (!$picker->isOpen()) {
            $picker->open();
        }
        $items = SubagentLivePickerController::buildItems($state->subagentLiveCatalog->all());
        $listWidget = new SelectListWidget(items: $items, keybindings: new Keybindings());
        $listWidget->setSelectedIndex(0);

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'dismissSelected');
        $method->invoke($picker, $listWidget, $screen, $state);
    }

    private function seedCatalogChild(
        TuiSessionState $state,
        string $artifactId,
        string $runId,
        string $status,
        string $agentName = 'scout',
        string $task = 'task',
    ): void {
        // Deterministic lastActivityAtMs at the test seam: restore attention+activity ordering
        // without relying on wall-clock uniqueness between sequential seeds.
        $catalog = $state->subagentLiveCatalog;
        $byId = new \ReflectionProperty($catalog, 'byArtifactId');
        /** @var array<string, SubagentLiveChildDTO> $rows */
        $rows = $byId->getValue($catalog);
        $rows[$artifactId] = new SubagentLiveChildDTO(
            agentRunId: $runId,
            artifactId: $artifactId,
            agentName: $agentName,
            status: SubagentLiveStatusEnum::fromProgressString($status),
            taskSummary: $task,
            // Descending counter so seed order matches all() lastActivityAtMs DESC.
            lastActivityAtMs: $this->seedActivityMs--,
            model: 'deepseek/deepseek-v4-flash',
            reasoning: 'medium',
        );
        $byId->setValue($catalog, $rows);
    }

    private function selectedIndex(SelectListWidget $list): int
    {
        $prop = new \ReflectionProperty(SelectListWidget::class, 'selectedIndex');

        return (int) $prop->getValue($list);
    }

    /**
     * @param list<string> $artifactIds
     *
     * @return array<string, array{line: string, native: bool, accent: bool}>
     */
    private function pickerRowStyles(VirtualTuiHarness $harness, array $artifactIds): array
    {
        $buffer = new ScreenBuffer(
            width: $harness->terminal()->getColumns(),
            height: $harness->terminal()->getRows(),
        );
        $buffer->write($harness->ansiOutput());
        $styledLines = explode("\n", $buffer->getStyledScreen());

        $out = [];
        foreach ($artifactIds as $artifactId) {
            $line = null;
            foreach ($styledLines as $candidate) {
                if (str_contains($candidate, $artifactId)) {
                    $line = $candidate;
                    break;
                }
            }
            $this->assertNotNull($line, "Picker row for {$artifactId} must be visible");
            $out[$artifactId] = [
                'line' => $line,
                'native' => str_contains($line, '→') && str_contains($line, "\x1b[1m"),
                'accent' => str_contains($line, "\x1b[35m"),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array{line: string, native: bool, accent: bool}> $rows
     */
    private function countNativeRows(array $rows): int
    {
        return (int) array_sum(array_column($rows, 'native'));
    }

    private function exportPicker(VirtualTuiHarness $harness, TuiSessionState $state): SubagentLivePickerController
    {
        return new SubagentLivePickerController(
            $harness->tui(),
            $harness->screen(),
            $state,
            $this->createStub(AgentSessionClient::class),
            new SubagentLiveChildViewPoller(new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()), new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer()),
            $this->createStub(ChildRunTranscriptSnapshotProviderInterface::class),
            $this->childEventsPathResolver(),
            SessionEventsExportServiceFactory::create(),
        );
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
            dispatcher: new EventDispatcher(),
        );
    }

    private function invokeExportSelected(
        SubagentLivePickerController $picker,
        ChatScreen $screen,
        TuiSessionState $state,
    ): void {
        if (!$picker->isOpen()) {
            $picker->open();
        }
        $items = SubagentLivePickerController::buildItems($state->subagentLiveCatalog->all());
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
            'payload' => ['payload' => ['messages' => [['role' => 'user', 'content' => $userContent]]]],
            'ts' => '2026-01-01T00:00:00+00:00',
        ];
        file_put_contents($sessionDir.'/events.jsonl', json_encode($event, \JSON_THROW_ON_ERROR)."\n");
    }

    private function pickerHeaderPlain(SubagentLivePickerController $picker): string
    {
        $headerRef = new \ReflectionProperty(SubagentLivePickerController::class, 'headerWidget');
        $header = $headerRef->getValue($picker);
        $this->assertInstanceOf(\Symfony\Component\Tui\Widget\TextWidget::class, $header);

        return strip_tags($header->getText());
    }

    private function workingMessage(ChatScreen $screen): string
    {
        return $screen->workingMessage();
    }

    /*
     * @return array<string, string>
     */
}
