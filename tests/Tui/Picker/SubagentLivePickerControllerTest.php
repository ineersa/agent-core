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
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\ContextUsageTestAppConfig;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\TuiTheme;
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
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('picker-export-test');
    }

    protected function tearDown(): void
    {
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
    public function exportKeyWritesSelectedChildHtml(): void
    {
        $previousCwd = getcwd();
        chdir($this->projectDir);
        try {
            $harness = new VirtualTuiHarness(sessionId: 'parent-session-export');
            $state = new TuiSessionState('parent-session-export');
            $artifactId = 'agent_export';
            $this->seedCatalogChild($state, $artifactId, 'child-run-export', 'completed');
            $this->writeChildEvents('parent-session-export', $artifactId, [
                $this->makeChildEvent(1, 'run_started', ['user_messages' => [['role' => 'user', 'content' => 'fork task']]]),
            ]);

            $picker = $this->picker($harness, $state);
            $this->invokeExportSelected($picker, $harness->screen(), $state);

            $expected = $this->projectDir.'/hatfield-child-'.$artifactId.'.html';
            $this->assertFileExists($expected);
            $this->assertStringContainsString('Child agent exported to:', $this->workingMessage($harness->screen()));
        } finally {
            if (false !== $previousCwd) {
                chdir($previousCwd);
            }
        }
    }

    #[Test]
    public function exportKeyReportsMissingEventsFile(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-no-events');
        $state = new TuiSessionState('parent-session-no-events');
        $artifactId = 'agent_no_file';
        $this->seedCatalogChild($state, $artifactId, 'child-run-no-file', 'completed');

        $picker = $this->picker($harness, $state);
        $this->invokeExportSelected($picker, $harness->screen(), $state);

        $this->assertStringContainsString('has no events to export', $this->workingMessage($harness->screen()));
    }

    #[Test]
    public function exportKeyReportsChildAbsentFromCatalog(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-missing-child');
        $state = new TuiSessionState('parent-session-missing-child');
        $this->seedCatalogChild($state, 'agent_stale', 'child-run-stale', 'completed');

        $picker = $this->picker($harness, $state);
        $items = $this->buildPickerItems($picker, $state->subagentLiveCatalog->all(), $harness->screen()->theme());
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
    public function pickerLabelIncludesContextSuffixFromCatalog(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-ctx');
        $state = new TuiSessionState('parent-session-ctx');
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
                    'status' => 'running',
                    'agent_name' => 'scout',
                    'artifact_id' => 'agent_ctx',
                    'agent_run_id' => 'child-run-ctx',
                    'task_summary' => 'task',
                    'model' => 'deepseek/deepseek-v4-flash',
                    'latest_input_tokens' => 97_900,
                ],
            ],
        ));

        $picker = $this->picker($harness, $state);
        $items = $this->buildPickerItems($picker, $state->subagentLiveCatalog->all(), $harness->screen()->theme());

        $this->assertNotEmpty($items);
        $this->assertStringContainsString('36%', $items[0]['label']);
        $this->assertStringContainsString('97.9k/272.0k', $items[0]['label']);
    }

    #[Test]
    public function exportKeyReportsNoSelectedChild(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'parent-session-no-select');
        $state = new TuiSessionState('parent-session-no-select');
        $this->seedCatalogChild($state, 'agent_x', 'child-run-x', 'completed');

        $picker = $this->picker($harness, $state);
        $listWidget = new SelectListWidget(items: [], keybindings: new Keybindings());

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'exportSelected');
        $method->invoke($picker, $listWidget, $harness->screen(), $state);

        $this->assertSame(
            'No child agent selected to export.',
            $this->workingMessage($harness->screen()),
        );
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
            ContextUsageTestAppConfig::withContextWindow(),
        );
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), $state);

        return $picker;
    }

    private function invokeDismissSelected(
        SubagentLivePickerController $picker,
        ChatScreen $screen,
        TuiSessionState $state,
    ): void {
        $items = $this->buildPickerItems($picker, $state->subagentLiveCatalog->all(), $screen->theme());
        $listWidget = new SelectListWidget(items: $items, keybindings: new Keybindings());
        $listWidget->setSelectedIndex(0);

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'dismissSelected');
        $children = $state->subagentLiveCatalog->all();
        $method->invokeArgs($picker, [&$listWidget, &$children, $screen->theme(), $screen, $state]);
    }

    /**
     * @param list<SubagentLiveChildDTO> $children
     *
     * @return list<array{value: string, label: string}>
     */
    private function buildPickerItems(SubagentLivePickerController $picker, array $children, TuiTheme $theme, int $selectedIndex = -1): array
    {
        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'buildItems');

        return $method->invoke($picker, $children, $theme, $selectedIndex);
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
        $items = $this->buildPickerItems($picker, $state->subagentLiveCatalog->all(), $screen->theme());
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
