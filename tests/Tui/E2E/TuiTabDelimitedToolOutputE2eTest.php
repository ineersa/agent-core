<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use Ineersa\Tui\Transcript\TranscriptLinePreviewService;
use Ineersa\Tui\Transcript\TranscriptToolResultPreviewWidget;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Real-terminal proof for tab-delimited tool output.
 *
 * ScreenBuffer models tab stops but does not model right-margin autowrap.
 * Replaying the same rendered frame in a fixed 224x60 pane detects autowrap
 * that moves status, editor, or footer rows outside their assigned positions.
 */
#[Group('tui-e2e-replay')]
final class TuiTabDelimitedToolOutputE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testDir = TestDirectoryIsolation::createProjectTempDir('tui-tab-output');
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
        if (isset($this->testDir)) {
            TestDirectoryIsolation::removeDirectory($this->testDir);
        }
    }

    #[Test]
    public function tabDelimitedToolOutputKeepsTerminalRowsAlignedWithTheRenderedFrame(): void
    {
        $body = $this->longGhChecksOutput();
        $displayState = new TranscriptDisplayState(previewableBlocksExpanded: true);
        $widget = new TranscriptToolResultPreviewWidget(
            body: $body,
            lineLimit: 4,
            fromEnd: true,
            prependBlankLine: false,
            displayState: $displayState,
            linePreviewService: new TranscriptLinePreviewService(),
            theme: new DefaultTheme(new ThemePalette('tab-output', [])),
            color: ThemeColorEnum::ToolOutput,
        );
        $renderedRows = $widget->render(new RenderContext(220, 60));

        foreach ($renderedRows as $row) {
            $this->assertLessThanOrEqual(220, AnsiUtils::visibleWidth($row));
        }

        $harness = new VirtualTuiHarness(
            columns: 224,
            rows: 60,
            sessionId: 'tab-output',
            displayState: $displayState,
        );
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'assistant-anchor',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'tab-output',
                seq: 1,
                text: 'agent fragment anchor',
            ),
            new TranscriptBlock(
                id: 'tool-result',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: 'tab-output',
                seq: 2,
                text: 'bash',
                meta: ['tool_name' => 'bash', 'result' => $body, 'is_error' => false],
            ),
        ]);
        $harness->screen()->setWorkingVisible(true);
        $harness->screen()->setWorkingMessage('Working...');
        $harness->screen()->promptEditor()->setText('prompt anchor');

        $ansiFrame = $harness->ansiOutput();
        $expectedFrame = trim($harness->plainScreenText());
        $framePath = $this->testDir.'/tab-output.ansi';
        file_put_contents($framePath, $ansiFrame);

        $pane = $this->tmux->startDetached(
            command: \sprintf('cat %s; read -r _', escapeshellarg($framePath)),
            prefix: 'tui-tab-output',
            width: 224,
            height: 60,
            cwd: $this->testDir,
        );

        try {
            $actualFrame = $this->tmux->waitForCaptureContains(
                $pane,
                'session tab-output',
                timeout: 2.0,
                message: 'Rendered footer did not reach its assigned row.',
            );

            // A real terminal must agree with ScreenBuffer's assigned rows.
            $this->assertSame($expectedFrame, $actualFrame);
            foreach ($renderedRows as $row) {
                $this->assertStringNotContainsString("\t", $row);
            }
            $this->assertStringNotContainsString("\t", $ansiFrame);
            $this->assertSame(1, substr_count($actualFrame, 'agent fragment anchor'));
            $this->assertSame(1, substr_count($actualFrame, 'Working...'));
            $this->assertSame(1, substr_count($actualFrame, 'prompt anchor'));
            $this->assertSame(1, substr_count($actualFrame, 'session tab-output'));
        } finally {
            if ($this->tmux->paneExists($pane)) {
                $this->tmux->sendKey($pane, 'Enter');
                $this->tmux->waitUntilPaneExits($pane, 2.0);
            }
        }
    }

    private function longGhChecksOutput(): string
    {
        return implode("\n", [
            "Context (7.4)\tpass\t42s\thttps://github.com/symfony/symfony/actions/runs/19999999999/job/59999999999",
            "PHPUnit tests on Linux with PHP 8.5 and all optional dependencies enabled for the complete framework test matrix and full CI\tpass\t1m32s\thttps://github.com/symfony/symfony/actions/runs/19999999999/job/59999999998",
            "PHPStan analysis for src, tests, bridges, contracts, components, and integration fixtures\tpass\t58s\thttps://github.com/symfony/symfony/actions/runs/19999999999/job/59999999997",
        ]);
    }
}
