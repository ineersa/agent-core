<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture;
use Ineersa\Tui\Tests\Support\SubagentProgressEventsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

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
            $liveCap = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringContainsString(ChildContextStatisticsFixture::CONTEXT_DETAIL, $liveCap, 'Child live footer must show context usage');
            $this->assertStringContainsString(ChildContextStatisticsFixture::MODEL_SHORT, $liveCap, 'Child live footer must show child model');

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

        $snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        if (!is_dir($snapshotDir) && !mkdir($snapshotDir, 0o777, true) && !is_dir($snapshotDir)) {
            throw new \RuntimeException('Failed to create snapshot dir: '.$snapshotDir);
        }

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
            $this->saveAnsiSnapshot($pane, $snapshotDir, 'agents-live-picker-before-down');

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
            $this->saveAnsiSnapshot($pane, $snapshotDir, 'agents-live-picker-after-down');

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
                $this->saveAnsiSnapshot($pane, $snapshotDir, 'agents-live-picker-failure');
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

    private function saveAnsiSnapshot(TmuxPane $pane, string $snapshotDir, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $path = \sprintf('%s/%s-%s.ansi', $snapshotDir, $tag, (new \DateTimeImmutable())->format('Ymd-His-u'));
        file_put_contents($path, $ansi);
    }

    private function createSessionAndWaitForAssistant(TmuxPane $pane): string
    {
        $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
        $this->tmux->waitForTuiReadyAfterLogo($pane);
        $this->tmux->sendLiteral($pane, 'hi');
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
        $fixturePath = __DIR__.'/fixtures/tui-resume-minimal.json';
        $projectDir = ProjectDir::get();
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-subagent-live-');

        $dbPath = $paths['app'];

        $transportDbPath = $paths['transport'];

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixturePath),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($projectDir.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-subagent-live');
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);
        $settings = ['ai' => ['providers' => ['deepseek' => ChildContextStatisticsFixture::deepseekProviderSettings(), 'llama_cpp_test' => ['api' => 'openai-completions', 'api_key' => 'dummy', 'completions_path' => '/chat/completions', 'supports_completions' => true, 'supports_embeddings' => false, 'supports_thinking_levels' => true, 'models' => ['test' => ['name' => 'test', 'context_window' => 32768, 'max_tokens' => 32768, 'input' => ['text'], 'tool_calling' => true, 'reasoning' => true, 'thinking_level_map' => ['off' => '0'], 'cost' => ['input' => 0, 'output' => 0]]]]]]];
        $yaml = \Symfony\Component\Yaml\Yaml::dump(TuiE2eDatabaseEnv::withSingleLlmWorkerForReplay($settings), 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }
}
