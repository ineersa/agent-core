<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture;
use Ineersa\Tui\Tests\Support\SubagentChildBashBackgroundPromptFixture;
use Ineersa\Tui\Tests\Support\SubagentProgressEventsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

/** @group tui-e2e-replay */
#[Group('tui-e2e-replay')]
final class TuiSubagentLiveViewE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }
        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
        if (isset($this->testProjectDir)) {
            TestDirectoryIsolation::removeDirectory($this->testProjectDir);
        }
    }

    public function testAgentsLivePickerOpenReadonlyAndAgentsMainReturnsToParent(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-live-view',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $sessionId = $this->createSessionAndWaitForAssistant($pane);
            SubagentProgressEventsFixture::write($this->testProjectDir, $sessionId);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);
            // Resume proof: fixture artifact must be visible before slash commands.
            $this->tmux->waitForCaptureContains($pane, 'agent_e2e_progress_fixture', 12.0, 'Resumed transcript must show fixture artifact');
            // Main footer keeps reasoning as color only (no text suffix). Capture border before live view.
            $mainBorderSgr = $this->editorBottomBorderSgr($this->tmux->captureAnsi($pane));
            $this->assertNotNull($mainBorderSgr, 'Main editor border SGR must be readable before live view');

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/agents-live');
            $this->tmux->sendKey($pane, 'Enter');
            // Transcript already contains the artifact id; wait for picker chrome.
            $this->tmux->waitForCaptureContains($pane, 'Agents live', 10.0, 'Agents live picker must open');
            $pickerCap = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringContainsString(ChildContextStatisticsFixture::CONTEXT_DETAIL, $pickerCap, 'Picker row must show child context usage');
            $this->assertStringContainsString(ChildContextStatisticsFixture::MODEL_SHORT, $pickerCap, 'Picker row must show child model');

            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Child agent', 10.0, 'Live view working line must appear');
            $this->tmux->waitForCaptureContains($pane, '[completed]', 10.0, 'Fixture child must show completed status in live view');
            $this->tmux->waitForCaptureContains(
                $pane,
                ChildContextStatisticsFixture::MODEL_SHORT.' (reasoning: high)',
                10.0,
                'Child live footer must show child model with child reasoning',
            );
            $liveCap = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringContainsString(ChildContextStatisticsFixture::CONTEXT_DETAIL, $liveCap, 'Child live footer must show context usage');
            $this->assertStringContainsString(
                ChildContextStatisticsFixture::MODEL_SHORT.' (reasoning: high)',
                $liveCap,
                'Child live footer must show child model + reasoning',
            );
            $childBorderSgr = $this->editorBottomBorderSgr($this->tmux->captureAnsi($pane));
            $this->assertNotNull($childBorderSgr, 'Child live editor border SGR must be readable');
            $this->assertNotSame(
                $mainBorderSgr,
                $childBorderSgr,
                'Editor border SGR must change from main medium reasoning to child high reasoning',
            );

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, 'continue after completion');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'has finished', 10.0, 'Terminal child input must show finished-subagent warning');
            $capAfterTerminal = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringContainsString('has finished', strtolower($capAfterTerminal), 'Terminal child warning must mention finished subagent');

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/new');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Leave subagent live view', 10.0, 'Blocked slash must show leave-live-view warning');
            $capAfterBlock = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringContainsString('agent_e2e_progress_fixture', $capAfterBlock, 'Must remain in live view after blocked /new');
            $this->assertStringNotContainsString('subagent scout running', $capAfterBlock, 'Must not switch back to parent transcript after blocked /new');

            $capLive = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringNotContainsString('skills', strtolower($capLive), 'Compact header must be hidden in live view');

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/agents-main');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'scout [completed]', 10.0, 'Parent transcript must restore after /agents-main');
            $parentCap = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringContainsString(ChildContextStatisticsFixture::TRANSCRIPT_CTX_LINE, $parentCap, 'Parent child card must show context usage line after resume');
            $this->assertStringNotContainsString('Subagent live:', $this->tmux->capturePlainWithHistory($pane, 2500));
            $restoredBorderSgr = $this->editorBottomBorderSgr($this->tmux->captureAnsi($pane));
            $this->assertNotNull($restoredBorderSgr, 'Restored main editor border SGR must be readable');
            $this->assertSame(
                $mainBorderSgr,
                $restoredBorderSgr,
                'Editor border SGR must restore to main reasoning after /agents-main',
            );
            $this->assertNotSame(
                $childBorderSgr,
                $restoredBorderSgr,
                'Restored main border must differ from child live border',
            );

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * Replay-backed proof: agent_child live view leave/re-enter never shows the
     * parent-only bash "Move it to the background?" overlay while child bash is
     * represented as an in-flight tool execution.
     */
    public function testAgentsLiveChildBashPathDoesNotShowBackgroundOverlayOnReenter(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-child-bash-bg',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $sessionId = $this->createSessionAndWaitForAssistant($pane);
            SubagentChildBashBackgroundPromptFixture::write($this->testProjectDir, $sessionId);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);
            $this->tmux->waitForCaptureContains($pane, 'agent_e2e_progress_fixture', 12.0, 'Resumed transcript must show fixture artifact');

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/agents-live');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Agents live', 10.0, 'Agents live picker must open');

            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Child agent', 10.0, 'Live view working line must appear');
            $this->tmux->waitForCaptureContains($pane, 'bash', 10.0, 'Child live view must represent in-flight bash tool');

            $liveCap = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringNotContainsString(
                'Move it to the background',
                $liveCap,
                'Initial agent_child live view must not show bash background overlay',
            );

            $this->tmux->sendKey($pane, 'C-\\');
            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    return !str_contains($cap, 'agents-live scout')
                        && str_contains($cap, 'agent_e2e_progress_fixture');
                },
                timeout: 12.0,
                message: 'Ctrl+\\ leave must restore parent transcript chrome',
                history: 2000,
            );

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/agents-live');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Agents live', 10.0, 'Agents live picker must reopen');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Child agent', 10.0, 'Live view must reopen after leave');

            $reenterCap = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringNotContainsString(
                'Move it to the background',
                $reenterCap,
                'Re-entering agent_child live view must not resurrect bash background overlay',
            );
            $this->assertStringNotContainsString(
                'Confirmation required',
                $reenterCap,
                'Re-entering agent_child live view must not show confirm overlay chrome for bash backgrounding',
            );
            $this->assertStringContainsString('bash', $reenterCap, 'Child live reconstruction must still show bash tool path');

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * Replay-backed proof: multiline picker rows stay one physical line and transcript
     * remains ordered while a paced assistant stream continues with /agents-live open.
     *
     * Boots with agent --resume on a preseeded completed session (TuiRepair pattern)
     * so the paced follow-up is the only LLM call and has a clean sequence/mailbox.
     */
    public function testAgentsLivePickerStaysSingleRowWhileStreamContinues(): void
    {
        $sessionId = '90042';
        $projectRoot = ProjectDir::get();
        $paths = TuiE2eDatabaseEnv::allocateIsolatedPaths($projectRoot, $this->testProjectDir, 'tui-picker-stream-');
        $this->migrateIsolatedTestDatabase($paths['appEnv'], $paths['transportEnv'], $paths['transportAbsolute']);
        SubagentProgressEventsFixture::writeMultilinePickerChildren($this->testProjectDir, $sessionId);
        $this->registerHatfieldSessionRow($paths['appAbsolute'], $sessionId, 'picker stream e2e');

        $pane = $this->tmux->startDetached(
            command: $this->agentCommandResumeWithPacedStream($sessionId, $paths['appEnv'], $paths['transportEnv']),
            prefix: 'tui-subagent-live-picker-stream',
            width: 140,
            height: 50,
            cwd: $this->testProjectDir,
        );

        $this->tmux->setSnapshotDir($this->testProjectDir);

        $ids = ['agent_e2e_fork_nl', 'agent_e2e_scout_nl'];

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);
            $this->tmux->waitForCaptureContains($pane, 'agent_e2e_fork_nl', 12.0, 'Resumed transcript must show fork artifact');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => !str_contains($cap, 'Working') && str_contains($cap, 'agent_e2e_scout_nl'),
                timeout: 10.0,
                message: 'Resumed session must be idle with both multiline children visible',
                history: 2500,
            );

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, 'PICKER_STREAM_PROMPT_UNIQUE stream while agents picker open');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Working', 10.0, 'Stream run must enter Working before picker open');

            $this->tmux->sendKey($pane, 'C-\\');
            $this->tmux->waitForCaptureContains($pane, 'Agents live', 10.0, 'Agents live picker must open during delayed stream');
            $this->tmux->waitForCaptureContains($pane, 'agent_e2e_scout_nl', 10.0, 'Picker must list scout child');

            $this->tmux->saveAnsiSnapshot($pane, 'agents-live-stream-open');
            $this->assertPickerRowCount($pane, $ids, 2);
            $this->assertPickerRowsAreSinglePhysicalLine($pane, $ids);

            $initial = $this->waitForPickerRowStyles($pane, $ids, static function (array $rows): bool {
                return $rows['agent_e2e_fork_nl']['native']
                    && !$rows['agent_e2e_scout_nl']['native']
                    && 1 === self::countNativePickerRows($rows);
            }, 'Initial picker must show exactly one native highlight on fork row');
            $this->assertFalse($initial['agent_e2e_fork_nl']['accent']);
            $this->assertFalse($initial['agent_e2e_scout_nl']['accent']);

            // Early marker while picker open; mid/late markers must still be absent so
            // we prove true multi-tick delivery (not one prebuffered dump after response_delay).
            $this->tmux->waitForHistoryContains($pane, 'STREAM_MARK_A', 20.0, 2500);
            $earlyCap = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringContainsString(
                'Agents live',
                $this->tmux->capturePlain($pane),
                'First stream marker must arrive while agents-live picker remains open',
            );
            $this->assertStringContainsString('STREAM_MARK_A', $earlyCap);
            $this->assertStringNotContainsString(
                'STREAM_MARK_FINAL',
                $earlyCap,
                'Final marker must not already be present when first marker arrives (proves incremental stream)',
            );
            $this->tmux->saveAnsiSnapshot($pane, 'agents-live-stream-early-marker');

            $this->assertPickerRowCount($pane, $ids, 2);
            $this->assertPickerRowsAreSinglePhysicalLine($pane, $ids);

            // Navigation interaction between early and late markers.
            $this->tmux->sendKey($pane, 'Down');
            $afterDown = $this->waitForPickerRowStyles($pane, $ids, static function (array $rows): bool {
                return !$rows['agent_e2e_fork_nl']['native']
                    && $rows['agent_e2e_scout_nl']['native']
                    && 1 === self::countNativePickerRows($rows);
            }, 'After Down during stream, native highlight must move one logical entry');
            $this->assertTrue(
                str_contains($afterDown['agent_e2e_scout_nl']['line'], '→')
                && str_contains($afterDown['agent_e2e_scout_nl']['line'], "\x1b[1m"),
                'Row 2 must use shared native selected-row marker/style while streaming',
            );
            $this->assertPickerRowCount($pane, $ids, 2);
            $this->assertPickerRowsAreSinglePhysicalLine($pane, $ids);
            $this->tmux->saveAnsiSnapshot($pane, 'agents-live-stream-after-down');

            $this->tmux->waitForHistoryContains($pane, 'STREAM_MARK_FINAL', 12.0, 2500);
            $this->assertStringContainsString(
                'Agents live',
                $this->tmux->capturePlain($pane),
                'Final stream marker must arrive while agents-live picker remains open',
            );
            $this->tmux->saveAnsiSnapshot($pane, 'agents-live-stream-final-marker');

            $this->tmux->sendKey($pane, 'Escape');
            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    return !str_contains($cap, 'Agents live')
                        && str_contains($cap, 'STREAM_MARK_A')
                        && str_contains($cap, 'STREAM_MARK_FINAL');
                },
                timeout: 10.0,
                message: 'Closing picker must reveal complete ordered stream markers',
                history: 2500,
            );

            $finalCap = $this->tmux->capturePlainWithHistory($pane, 2500);
            $posA = strpos($finalCap, 'STREAM_MARK_A');
            $posB = strpos($finalCap, 'STREAM_MARK_B');
            $posC = strpos($finalCap, 'STREAM_MARK_C');
            $posD = strpos($finalCap, 'STREAM_MARK_D');
            $posFinal = strpos($finalCap, 'STREAM_MARK_FINAL');
            $this->assertNotFalse($posA);
            $this->assertNotFalse($posB);
            $this->assertNotFalse($posC);
            $this->assertNotFalse($posD);
            $this->assertNotFalse($posFinal);
            $this->assertTrue(
                $posA < $posB && $posB < $posC && $posC < $posD && $posD < $posFinal,
                'Stream markers must remain ordered after picker close',
            );
            $this->assertSame(1, substr_count($finalCap, 'STREAM_MARK_A'));
            $this->assertSame(1, substr_count($finalCap, 'STREAM_MARK_FINAL'));
            $this->assertStringNotContainsString('Agents live', $finalCap);

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            try {
                $this->tmux->saveAnsiSnapshot($pane, 'agents-live-stream-failure');
            } catch (\Throwable) {
            }
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * Replay-backed proof: /agents-live with three parallel children keeps a single
     * native SelectListWidget highlight that moves on Down (no stale Accent on row 1).
     */
    public function testAgentsLivePickerArrowMovesSingleNativeHighlight(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-live-picker-highlight',
            width: 140,
            height: 50,
            cwd: $this->testProjectDir,
        );

        $this->tmux->setSnapshotDir($this->testProjectDir);

        try {
            $sessionId = $this->createSessionAndWaitForAssistant($pane);
            SubagentProgressEventsFixture::writeThreeCompletedChildren($this->testProjectDir, $sessionId);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);
            $this->tmux->waitForCaptureContains($pane, 'agent_e2e_alpha_pick', 12.0, 'Resumed transcript must show alpha artifact');

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/agents-live');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Agents live', 10.0, 'Agents live picker must open');
            $this->tmux->waitForCaptureContains($pane, 'agent_e2e_bravo_pick', 10.0, 'Picker must list bravo child');
            $this->tmux->waitForCaptureContains($pane, 'agent_e2e_charlie_pick', 10.0, 'Picker must list charlie child');

            $ids = ['agent_e2e_alpha_pick', 'agent_e2e_bravo_pick', 'agent_e2e_charlie_pick'];
            $this->tmux->saveAnsiSnapshot($pane, 'agents-live-picker-before-down');

            $initial = $this->waitForPickerRowStyles($pane, $ids, static function (array $rows): bool {
                return $rows['agent_e2e_alpha_pick']['native']
                    && !$rows['agent_e2e_bravo_pick']['native']
                    && !$rows['agent_e2e_charlie_pick']['native']
                    && 1 === self::countNativePickerRows($rows);
            }, 'Initial picker must show exactly one native highlight on row 1');

            $this->assertFalse($initial['agent_e2e_alpha_pick']['accent'], 'Row 1 must not carry manual Accent');
            $this->assertFalse($initial['agent_e2e_bravo_pick']['accent']);
            $this->assertFalse($initial['agent_e2e_charlie_pick']['accent']);
            $this->assertPickerRowCount($pane, $ids, 3);

            $this->tmux->sendKey($pane, 'Down');
            $afterDown = $this->waitForPickerRowStyles($pane, $ids, static function (array $rows): bool {
                return !$rows['agent_e2e_alpha_pick']['native']
                    && $rows['agent_e2e_bravo_pick']['native']
                    && !$rows['agent_e2e_charlie_pick']['native']
                    && 1 === self::countNativePickerRows($rows);
            }, 'After Down, exactly one native highlight must move to row 2');
            $this->tmux->saveAnsiSnapshot($pane, 'agents-live-picker-after-down');

            $this->assertFalse($afterDown['agent_e2e_alpha_pick']['accent'], 'Row 1 must lose any Accent/style after Down');
            $this->assertFalse($afterDown['agent_e2e_bravo_pick']['accent']);
            $this->assertFalse($afterDown['agent_e2e_charlie_pick']['accent']);
            $this->assertTrue(
                str_contains($afterDown['agent_e2e_bravo_pick']['line'], '→')
                && str_contains($afterDown['agent_e2e_bravo_pick']['line'], "\x1b[1m"),
                'Row 2 must use shared native selected-row marker/style',
            );
            $this->assertPickerRowCount($pane, $ids, 3);

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            try {
                $this->tmux->saveAnsiSnapshot($pane, 'agents-live-picker-failure');
            } catch (\Throwable) {
            }
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * @param list<string>                                                                   $artifactIds
     * @param callable(array<string, array{line: string, native: bool, accent: bool}>): bool $predicate
     *
     * @return array<string, array{line: string, native: bool, accent: bool}>
     */
    private function waitForPickerRowStyles(TmuxPane $pane, array $artifactIds, callable $predicate, string $message): array
    {
        $last = [];
        $this->tmux->waitForCallback(
            $pane,
            function (string $_cap) use ($pane, $artifactIds, $predicate, &$last): bool {
                $last = $this->pickerRowStylesFromAnsi($this->tmux->captureAnsi($pane), $artifactIds);
                if (\count($last) !== \count($artifactIds)) {
                    return false;
                }

                return $predicate($last);
            },
            timeout: 10.0,
            message: $message,
            history: 0,
        );

        return $last;
    }

    /**
     * @param list<string> $artifactIds
     *
     * @return array<string, array{line: string, native: bool, accent: bool}>
     */
    private function pickerRowStylesFromAnsi(string $ansi, array $artifactIds): array
    {
        $out = [];
        $lines = explode("\n", $ansi);

        // Prefer the Agents-live list region over earlier transcript artifact cards.
        $start = 0;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'Agents live')) {
                $start = $i;
                break;
            }
        }

        for ($i = $start, $n = \count($lines); $i < $n; ++$i) {
            $line = $lines[$i];
            foreach ($artifactIds as $artifactId) {
                if (!str_contains($line, $artifactId) || isset($out[$artifactId])) {
                    continue;
                }
                // Picker rows are SelectList lines (→ selected / two-space unselected), not transcript cards.
                if (!preg_match('/(?:→| {2})\s*\S+\s*\[/', $line)) {
                    continue;
                }
                $hasBold = (bool) preg_match('/\x1b\[(?:\d+;)*1m/', $line);
                $hasArrow = str_contains($line, '→');
                // Manual Accent is a foreground color on the label without the native selected bold+arrow pair.
                $hasFgColor = (bool) preg_match('/\x1b\[(?:38;5;\d+|3[0-7]|9[0-7]|38;2;\d+;\d+;\d+)m/', $line);
                $out[$artifactId] = [
                    'line' => $line,
                    'native' => $hasArrow && $hasBold,
                    'accent' => $hasFgColor && !($hasArrow && $hasBold),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, array{line: string, native: bool, accent: bool}> $rows
     */
    private static function countNativePickerRows(array $rows): int
    {
        return (int) array_sum(array_column($rows, 'native'));
    }

    /**
     * @param list<string> $artifactIds
     */
    private function assertPickerRowsAreSinglePhysicalLine(TmuxPane $pane, array $artifactIds): void
    {
        $plain = $this->tmux->capturePlain($pane);
        $pickerRegion = $plain;
        $marker = strrpos($plain, 'Agents live');
        if (false !== $marker) {
            $pickerRegion = substr($plain, $marker);
        }

        $lines = explode("\n", $pickerRegion);
        foreach ($artifactIds as $artifactId) {
            $matching = [];
            foreach ($lines as $line) {
                if (str_contains($line, $artifactId) && preg_match('/(?:→| {2})\s*\S+\s*\[/', $line)) {
                    $matching[] = $line;
                }
            }
            $this->assertCount(
                1,
                $matching,
                "Artifact {$artifactId} must occupy exactly one physical picker row",
            );
            $row = $matching[0];
            $this->assertStringNotContainsString("\r", $row);
            // Raw task continuation text from multiline summaries must not appear on adjacent rows.
            $this->assertStringNotContainsString('Your task, in order', $pickerRegion);
            $this->assertDoesNotMatchRegularExpression('/  +Your task/', $pickerRegion);
        }
    }

    /**
     * @param list<string> $artifactIds
     */
    private function assertPickerRowCount(TmuxPane $pane, array $artifactIds, int $expected): void
    {
        $plain = $this->tmux->capturePlain($pane);
        $pickerRegion = $plain;
        $marker = strrpos($plain, 'Agents live');
        if (false !== $marker) {
            $pickerRegion = substr($plain, $marker);
        }

        $pickerRowCount = 0;
        foreach (explode("\n", $pickerRegion) as $line) {
            if (!preg_match('/(?:→| {2})\s*\S+\s*\[/', $line)) {
                continue;
            }
            foreach ($artifactIds as $artifactId) {
                if (str_contains($line, $artifactId)) {
                    ++$pickerRowCount;
                    break;
                }
            }
        }

        $this->assertSame(
            $expected,
            $pickerRowCount,
            'Agents-live picker region must show exactly '.$expected.' unique child rows (no duplicates)',
        );
    }

    /**
     * Extract editor-frame bottom border SGR (truecolor).
     *
     * Real ChatScreen places ThemeColorEnum::Separator rules above/below the
     * EditorWidget frame. Scanning only the last ─ line hits the footer
     * separator (steel), not EditorWidget::frame. Prefer the last truecolor
     * full-rule line that is not the fixed steel separator (#4a5568).
     */
    private function editorBottomBorderSgr(string $ansi): ?string
    {
        $steel = '38;2;74;85;104'; // cyberpunk vars.steel used for Separator
        $lines = explode("\n", $ansi);
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            $line = $lines[$i];
            if (!str_contains($line, '─')) {
                continue;
            }
            // Editor frame is a full-width rule of ─ only (optional scroll marker text).
            $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line;
            $plain = trim($plain);
            if ('' === $plain || !preg_match('/^─+$/u', $plain)) {
                continue;
            }
            if (!preg_match('/\x1b\[(38;2;\d+;\d+;\d+)m/', $line, $m)) {
                continue;
            }
            if ($steel === $m[1]) {
                continue;
            }

            return $m[1];
        }

        return null;
    }

    private function createSessionAndWaitForAssistant(TmuxPane $pane, string $bootPrompt = 'hi'): string
    {
        $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
        $this->tmux->waitForTuiReadyAfterLogo($pane);
        $this->tmux->sendLiteral($pane, $bootPrompt);
        $this->tmux->sendKey($pane, 'Enter');
        $sessionId = null;
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap) use (&$sessionId): bool {
                if (!str_contains($cap, '◇') && !str_contains($cap, '✕')) {
                    return false;
                }
                if (!preg_match('/session\s+(\d+)/', $cap, $matches)) {
                    return false;
                }
                $sessionId = $matches[1];

                return true;
            },
            timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
            message: 'Assistant block and session id must both appear in capture',
            history: 2000,
        );
        $this->assertNotEmpty($sessionId, 'Session id must appear in the same capture as assistant/error glyph');

        return $sessionId;
    }

    private function agentCommand(): string
    {
        return $this->agentCommandWithFixtures([__DIR__.'/fixtures/tui-resume-minimal.json']);
    }

    private function agentCommandResumeWithPacedStream(
        string $sessionId,
        string $appDbEnvPath,
        string $transportDbEnvPath,
    ): string {
        $projectDir = ProjectDir::get();

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent --resume=%s --cwd=%s --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefixForIsolatedEnv($appDbEnvPath, $transportDbEnvPath),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg(__DIR__.'/fixtures/tui-agents-picker-stream-paced.json'),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($projectDir.'/bin/console'),
            escapeshellarg($sessionId),
            escapeshellarg($this->testProjectDir),
        );
    }

    private function migrateIsolatedTestDatabase(
        string $appDbEnvPath,
        string $transportDbEnvPath,
        string $transportDbAbsolutePath,
    ): void {
        $cmd = \sprintf(
            'cd %s && APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH=%s %s %s doctrine:migrations:migrate --no-interaction 2>&1',
            escapeshellarg($this->testProjectDir),
            escapeshellarg($appDbEnvPath),
            escapeshellarg($transportDbEnvPath),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(ProjectDir::get().'/bin/console'),
        );

        exec($cmd, $output, $exitCode);
        if (0 !== $exitCode) {
            $this->fail('Failed to migrate test database for picker stream E2E: '.implode("\n", $output));
        }

        TuiE2eDatabaseEnv::ensureIsolatedMessengerTransportSchema($transportDbAbsolutePath);
    }

    private function registerHatfieldSessionRow(string $appDbAbsolutePath, string $sessionId, string $prompt): void
    {
        $pdo = new \PDO('sqlite:'.$appDbAbsolutePath);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO hatfield_session (id, cwd, prompt, name, provider_cache_key, created_at, updated_at) VALUES (:id, :cwd, :prompt, :name, :provider_cache_key, :created_at, :updated_at)');
        $stmt->execute([
            'id' => (int) $sessionId,
            'cwd' => $this->testProjectDir,
            'prompt' => $prompt,
            'name' => 'picker-stream-e2e',
            'provider_cache_key' => UuidV7::v7()->toRfc4122(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param list<string> $fixturePaths
     */
    private function agentCommandWithFixtures(array $fixturePaths): string
    {
        $projectDir = ProjectDir::get();
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-subagent-live-');

        $dbPath = $paths['app'];

        $transportDbPath = $paths['transport'];

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg(implode(';', $fixturePaths)),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($projectDir.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-subagent-live');
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);
        $settings = ['ai' => [
            'default_reasoning' => 'medium',
            'providers' => [
                'deepseek' => ChildContextStatisticsFixture::deepseekProviderSettings(),
                'llama_cpp_test' => [
                    'api' => 'openai-completions',
                    'api_key' => 'dummy',
                    'completions_path' => '/chat/completions',
                    'supports_completions' => true,
                    'supports_embeddings' => false,
                    'supports_thinking_levels' => true,
                    'models' => [
                        'test' => [
                            'name' => 'test',
                            'context_window' => 32768,
                            'max_tokens' => 32768,
                            'input' => ['text'],
                            'tool_calling' => true,
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'off' => '0',
                                'minimal' => '0',
                                'low' => '0',
                                'medium' => '0',
                                'high' => '0',
                                'xhigh' => '0',
                            ],
                            'cost' => ['input' => 0, 'output' => 0],
                        ],
                    ],
                ],
            ],
        ]];
        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }
}
