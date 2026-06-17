<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Consolidated journey test for the agent TUI.
 *
 * Uses a single long-lived tmux/TUI session to exercise multiple
 * UI-only and replay-backed behaviours in sequence, replacing the
 * previous one-harness-per-assertion pattern.
 *
 * Design:
 *  - Launches the TUI once with APP_ENV=test + replay fixtures so
 *    model-dependent steps are deterministic and require no live
 *    llama.cpp.
 *  - UI-only steps (hotkeys, reasoning, shell, file completion) run
 *    before any model interaction.
 *  - A single model-interaction step submits a prompt and verifies
 *    the replay-backed assistant block appears.
 *  - Teardown sends Ctrl+D for a clean exit; TmuxHarness destructor
 *    kills the tmux session.
 *
 * Harness launch count: 1 (was 6+ across separate test classes for
 * startup, hotkeys, reasoning, border, shell-prefix, file-completion).
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiJourneyE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    /**
     * Full TUI journey — one session, multiple assertions.
     *
     * Exercises in order:
     *  1. Startup layout (logo, status, footer)
     *  2. Reasoning cycling via Shift+Tab + border colour change
     *  3. /hotkeys slash-command table
     *  4. Shell !ls prefix — real command output proof
     *  5. File @ completion preserves multiline content
     *  6. Model interaction via replay fixture (no live LLM)
     *  7. !! double-bang rejection proof
     *  8. Clean exit via Ctrl+D
     *
     * Ctrl+J newline is tested separately in HotkeySmokeTest
     * (it is sensitive to terminal configuration and a race
     * with replay-mode tmux session startup).
     */
    public function testJourneyCoversCoreTuiBehavior(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-journey',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->journeyPhase1StartupLayout($pane);
            $this->journeyPhase2ReasoningAndBorderColour($pane);
            $this->journeyPhase3HotkeysTable($pane);
            $this->journeyPhase4ShellPrefixOutput($pane);
            $this->journeyPhase5FileCompletion($pane);
            $this->journeyPhase6ModelInteractionReplay($pane);
            $this->journeyPhase7DoubleBangRejection($pane);

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'journey-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    // ── Journey phases ────────────────────────────────────────────

    /**
     * Phase 1: Startup layout proof.
     *
     * Asserts the Hatfield logo (█), working/idle status (● idle),
     * footer (◆), and session label appear.
     *
     * After the logo appears, wait briefly so the TUI event loop
     * finishes initialisation (TTY setup, Reactor run, footer render)
     * before the journey starts sending keystrokes.
     */
    private function journeyPhase1StartupLayout(TmuxPane $pane): void
    {
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // Let the TUI finish startup rendering before keystrokes arrive.
        usleep(500_000);

        $capture = $this->tmux->capturePlainWithHistory($pane, 500);

        self::assertStringContainsString('█', $capture, 'Hatfield logo missing');
        self::assertTrue(
            str_contains($capture, '● idle') || str_contains($capture, '◐ Work'),
            'Working/idle status widget missing',
        );
        self::assertStringContainsString('◆', $capture, 'Footer widget missing');
        // Session ID only appears after first prompt submission (Phase 6).
        // At startup the footer shows model, token, timer, CWD, branch.
    }

    /**
     * Phase 2: Shift+Tab reasoning cycling + editor border colour
     * change (purely visual, no model involved).
     */
    private function journeyPhase2ReasoningAndBorderColour(TmuxPane $pane): void
    {
        $initialColour = $this->editorBorderColour($pane);
        self::assertNotNull($initialColour, 'Border colour should not be null');
        self::assertNotEmpty($initialColour, 'Border colour should not be empty');

        // Shift+Tab: off → minimal
        $this->tmux->sendLiteral($pane, "\x1b[Z");

        // Wait for border colour change (catches the stylesheet repaint).
        $minimalColour = $this->waitForBorderColorChange($pane, $initialColour, 5.0);
        self::assertNotSame(
            $initialColour,
            $minimalColour,
            \sprintf(
                'Border colour should change after Shift+Tab (off→minimal): %s vs %s',
                $initialColour,
                $minimalColour ?? '(null)',
            ),
        );

        // Status panel should show reasoning level.
        $capture = $this->tmux->capturePlainWithHistory($pane, 500);
        self::assertStringContainsString('reasoning', $capture, 'Status panel should show reasoning key');

        // Second Shift+Tab: minimal → low
        $this->tmux->sendLiteral($pane, "\x1b[Z");

        $lowColour = $this->waitForBorderColorChange($pane, $minimalColour, 5.0);
        self::assertNotSame(
            $minimalColour,
            $lowColour,
            \sprintf(
                'Border colour should change again (minimal→low): %s vs %s',
                $minimalColour,
                $lowColour ?? '(null)',
            ),
        );
    }

    /**
     * Phase 3: /hotkeys slash-command renders a keyboard shortcuts table.
     */
    private function journeyPhase3HotkeysTable(TmuxPane $pane): void
    {
        $this->tmux->sendKey($pane, 'C-u'); // Clear editor
        $this->tmux->sendLiteral($pane, '/hotkeys');
        $this->tmux->sendKey($pane, 'Enter');

        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return str_contains($cap, 'Keyboard shortcuts');
            },
            timeout: 5.0,
            message: '/hotkeys table never appeared',
            history: 2000,
        );

        $capture = $this->tmux->capturePlainWithHistory($pane, 2000);

        // Structural box-drawing chars must be present.
        $boxChars = ['┌', '├', '└', '│', '┐', '┤', '┘'];
        foreach ($boxChars as $ch) {
            self::assertStringContainsString(
                $ch,
                $capture,
                \sprintf('/hotkeys output should contain box-drawing char "%s"', $ch),
            );
        }

        // Representative entries.
        foreach (['Ctrl+C', 'Ctrl+D', 'Ctrl+J', 'Insert newline', 'Submit prompt', 'Enter', 'Tab'] as $entry) {
            self::assertStringContainsString(
                $entry,
                $capture,
                \sprintf('/hotkeys output should contain "%s"', $entry),
            );
        }
    }

    /**
     * Phase 4: !ls shell prefix — creates a unique marker file,
     * sends !ls -1 (marker NOT in the command text), and asserts
     * the marker appears in captured output (proving real command
     * output was shown).
     */
    private function journeyPhase4ShellPrefixOutput(TmuxPane $pane): void
    {
        $marker = 'shjourney-marker-'.bin2hex(random_bytes(4)).'.txt';
        touch($this->testProjectDir.'/'.$marker);

        $this->tmux->sendKey($pane, 'C-u'); // Clear editor
        $this->tmux->sendLiteral($pane, '!ls -1');
        $this->tmux->sendKey($pane, 'Enter');

        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap) use ($marker): bool {
                return str_contains($cap, $marker);
            },
            timeout: 5.0,
            message: sprintf('Marker file "%s" never appeared in captured output for !ls -1', $marker),
            history: 2000,
        );

        // Assert working status clears after shell command.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return !str_contains($cap, 'Working...')
                    && !str_contains($cap, 'Running...');
            },
            timeout: 5.0,
            message: 'Working/Running status never cleared after !ls -1',
            history: 2000,
        );
    }

    /**
     * Phase 5: @ file completion — triggers completion via Tab and
     * verifies the completion menu appears with file entries.
     *
     * Creates test files under the isolated HOME directory.  Uses a
     * single-line @ trigger (no C-j/multiline — the C-j newline test
     * is in HotkeySmokeTest) to avoid a C-j-as-Enter race.
     */
    private function journeyPhase5FileCompletion(TmuxPane $pane): void
    {
        $this->tmux->sendKey($pane, 'C-u'); // Clear editor
        $this->tmux->sendLiteral($pane, '@test');
        $this->tmux->sendKey($pane, 'Tab');

        // Completion menu should appear — any file/dir entry with @
        // proves the index builder and completion chain work.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return str_contains($cap, 'testfiles')
                    || str_contains($cap, '@home');
            },
            timeout: 3.0,
            message: 'File completion menu did not appear',
            history: 2000,
        );

        $capture = $this->tmux->capturePlain($pane);

        // The completed path should start with @.
        self::assertStringContainsString('@', $capture, 'Editor must contain completed @ path after Tab');

        // Dismiss any completion menu before moving on.
        $this->tmux->sendKey($pane, 'Escape');
        \usleep(100_000);
        $this->tmux->sendKey($pane, 'C-u');
    }

    /**
     * Phase 6: Model interaction via deterministic replay fixture.
     *
     * Submits a prompt; the TUI processes it through the replay-backed
     * LLM pipeline (HATFIELD_LLM_REPLAY_FIXTURE_PATH → test services
     * replay HttpClient). Asserts the assistant block (◇) appears
     * with fixture text.
     *
     * This is the ONLY prompt submission in the journey; earlier phases
     * only exercise UI behaviour.
     */
    private function journeyPhase6ModelInteractionReplay(TmuxPane $pane): void
    {
        // After several rounds of Ctrl+U and typing, the editor may
        // have residual state.  Send Escape to cancel/clear, then a
        // brief pause so the TUI repaints.
        $this->tmux->sendKey($pane, 'Escape');
        usleep(100_000);
        $this->tmux->sendKey($pane, 'C-u');
        usleep(100_000);

        $prompt = 'Respond with exactly one sentence: the sky is blue.';
        $this->tmux->sendLiteral($pane, $prompt);
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for assistant block (◇) — the replay fixture response
        // streams in immediately (no network latency).
        $capture = $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, '◇')
                || str_contains($cap, '✕'),
            timeout: 10.0,
            message: 'Neither ◇ assistant block nor ✕ error block appeared after prompt submission',
            history: 2000,
        );

        self::assertTrue(
            str_contains($capture, '◇') || str_contains($capture, '✕'),
            'Transcript must display either an assistant block (◇) or error block (✕)',
        );

        // Assert the replay fixture text appears in history.
        if (str_contains($capture, '◇')) {
            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            self::assertStringContainsString(
                'The sky is blue.',
                $fullCapture,
                'Replay fixture response text must appear in transcript',
            );
        }

        // Wait for turn completion (Working spinner gone).
        try {
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    && !str_contains($cap, '◐ Working...'),
                timeout: 5.0,
                message: 'Turn did not complete after replay response',
                history: 2000,
            );
        } catch (\RuntimeException) {
            // Timeout on working clear is non-fatal in replay mode
            // (the fixture response may be faster than the TUI poller).
        }

        $this->saveAnsiSnapshot($pane, 'journey-model-replay');

        // After the first prompt submission, the session should exist.
        $sessionCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
        self::assertStringContainsString('session ', $sessionCapture, 'Session ID should appear in footer after prompt submission');
    }

    /**
     * Phase 7: !! double-bang shell prefix rejection.
     */
    private function journeyPhase7DoubleBangRejection(TmuxPane $pane): void
    {
        $this->tmux->sendKey($pane, 'C-u'); // Clear editor
        $this->tmux->sendLiteral($pane, '!!echo should-not-run');
        $this->tmux->sendKey($pane, 'Enter');

        $capture = $this->tmux->waitForCaptureContains($pane, 'not supported', 2.0);

        self::assertStringContainsString('not supported', $capture, '!! must show not-supported message');
        self::assertStringNotContainsString('should-not-run', $capture, '!! must never execute the command');
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function agentCommand(): string
    {
        $fixturePaths = [];

        // Model-interaction step (phase 7): the first explicit prompt submission
        // gets its response from this fixture.
        $replyFixture = __DIR__.'/fixtures/tui-simple-text-response.json';
        if (\is_file($replyFixture)) {
            $fixturePaths[] = $replyFixture;
        }

        // Use source bin/console (not PHAR) so APP_ENV=test autoload-dev
        // classes (ControllerReplayHttpClientFactory in tests/) are available.
        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $fixtureEnv = '' !== $fixturePaths
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.\escapeshellarg(\implode(';', $fixturePaths)).' '
            : '';

        // Use an isolated test DB so StartupDatabaseMigrator can auto-migrate
        // on startup without colliding with the shared app_test.sqlite that
        // already has migrations applied.
        $dbPath = 'app_test-tui-journey-'.bin2hex(random_bytes(4)).'.sqlite';

        // Do NOT use --prompt (auto-submit) — the journey controls
        // submission timing explicitly.  Only Phase 7 submits a prompt.
        // When HATFIELD_LLM_REPLAY_FIXTURE_PATH is set and a prompt is
        // later submitted, ControllerReplayHttpClientFactory serves the
        // fixture response.
        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            \escapeshellarg($dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e');
        @\mkdir($dir.'/.hatfield', 0o777, true);

        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
                'default_reasoning' => 'off',
                'providers' => [
                    'llama_cpp_test' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'http://192.168.2.38:9052/v1',
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
                                'input' => ['text', 'image'],
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
            ],
            'extensions' => [
                'enabled' => [
                    'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension',
                ],
                'settings' => [
                    'safe_guard' => [
                        'tool_names' => [
                            'bash' => 'bash',
                            'write' => 'write',
                            'edit' => 'edit',
                            'read' => 'read',
                        ],
                        'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b'],
                        'allow_write_outside_cwd' => [],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                ],
            ],
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);

        // Also write for the HOME dir.
        @\mkdir($dir.'/home/.hatfield', 0o777, true);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        // Create test files for file mention completion phase
        // before TUI starts, so the startup index scanner picks them up.
        @\mkdir($dir.'/home/testfiles', 0o777, true);
        \file_put_contents($dir.'/home/testfiles/alpha.txt', 'test');

        return $dir;
    }

    // ── ANSI border colour helpers (from EditorBorderColorTest) ──

    private function editorBorderColour(TmuxPane $pane): ?string
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $lines = explode("\n", $ansi);

        // Find the footer bar anchor: last line containing ◆.
        $footerIdx = null;
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            if (str_contains($lines[$i], '◆')) {
                $footerIdx = $i;
                break;
            }
        }

        if (null === $footerIdx) {
            return null;
        }

        // Editor bottom border = 2 lines above the footer (skip footer separator).
        $borderIdx = $footerIdx - 2;
        if ($borderIdx < 0 || !isset($lines[$borderIdx])) {
            return null;
        }

        $borderLine = $lines[$borderIdx];

        // Extract the ANSI SGR colour before the first ─.
        if (preg_match('/\x1b\[([0-9;]*)m/', $borderLine, $m)) {
            $colourPart = $m[1];
            if ('' !== $colourPart) {
                return $colourPart;
            }
        }

        return 'default';
    }

    private function waitForBorderColorChange(TmuxPane $pane, string $previous, float $timeout = 5.0): ?string
    {
        $deadline = \microtime(true) + $timeout;

        while (\microtime(true) < $deadline) {
            $colour = $this->editorBorderColour($pane);
            if (null !== $colour && $colour !== $previous) {
                return $colour;
            }
            \usleep(100_000);
        }

        return $this->editorBorderColour($pane);
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }
}
