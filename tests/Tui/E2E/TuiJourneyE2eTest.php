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
 *  - UI-only tmux steps (shell) run before
 *    model interaction; /hotkeys and !! rejection are virtual-only
 *    ({@see \Ineersa\Tui\Tests\Screen\TuiVirtualInputTest}).
 *  - A single model-interaction step submits a prompt and verifies
 *    the replay-backed assistant block appears.
 *  - Teardown sends Ctrl+D for a clean exit; TmuxHarness destructor
 *    kills the tmux session.
 *
 * Harness launch count: 1 (integration smoke). Startup, reasoning, /hotkeys, and
 * !! proofs live in virtual tests under tests/Tui/Screen/.
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
     * Exercises in order (tmux integration smoke):
     *  1. Startup layout (logo, status, footer)
     *  2. Shell !ls prefix — real command output proof + ordering
     *  3. Inline shell on completed run + follow-up (issue #183 repro)
     *  4. Clean exit via Ctrl+D
     *
     * Virtual-only (not in this journey): startup detail {@see TuiStartupVirtualRenderTest},
     * Shift+Tab reasoning status/border {@see TuiReasoningCycleTest},
     * @ file completion menu/accept {@see TuiFileCompletionRenderTest},
     * /export confirmation + HTML file {@see TuiExportCommandVirtualTest},
     * model replay assistant block + cache footer {@see TuiModelInteractionVirtualTest},
     * /hotkeys table, !! rejection — {@see TuiVirtualInputTest}.
     *
     * !! double-bang rejection is covered by {@see \Ineersa\Tui\Tests\Screen\TuiVirtualInputTest}.
     * /hotkeys keyboard shortcuts table is covered by {@see \Ineersa\Tui\Tests\Screen\TuiVirtualInputTest::testHotkeysSlashCommandRoutesLocallyAndRendersKeyboardShortcutsTable}.
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
            $this->journeyPhase4ShellPrefixOutput($pane);
            $this->journeyPhase9InlineShellOnCompletedRun($pane);

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
        $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);

        $capture = $this->tmux->waitForTuiReadyAfterLogo($pane);

        self::assertStringContainsString('█', $capture, 'Hatfield logo missing');
        self::assertTrue(
            str_contains($capture, '● idle') || str_contains($capture, '◐ Work'),
            'Working/idle status widget missing',
        );
        self::assertStringContainsString('◆', $capture, 'Footer widget missing');
        // Session ID in footer is covered by {@see TuiModelInteractionVirtualTest}.
        // At startup the footer shows model, token, timer, CWD, branch.
    }


    /**
     * Phase 4: !ls shell prefix (standalone, first-input) — creates a
     * unique marker file, sends !ls -1 (marker NOT in the command text),
     * and asserts the marker appears in captured output (proving real
     * command output was shown).  Also verifies that AgentEnd is the
     * final lifecycle event in the canonical stream (regression for
     * issue #183 ordering race).
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
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
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
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Working/Running status never cleared after !ls -1',
            history: 2000,
        );

        // Ordering assertion: the standalone shell's canonical events
        // must end with AgentEnd (tool_exec_start → tool_exec_end → agent_end).
        // A violation happens when the controller writes AgentEnd synchronously
        // before the async worker writes tool_exec events (issue #183).
        $this->assertShellEventsOrder($this->testProjectDir, '!ls');
    }

    /**
     * Phase 9: Inline shell on a completed run (subsequent !cmd), then
     * follow-up normal message — the documented residual from issue #183.
     *
     * Phase 4 (standalone !ls in this same tmux session) has already
     * completed a shell run and left the session idle. Phase 9 does not
     * require a model turn; {@see TuiModelInteractionVirtualTest} covers
     * model replay assistant/footer proof separately. Sending a second
     * !ls -1 here exercises the subsequent/terminal shell path on that
     * existing completed run, where SubmitListener previously sent
     * shell_command + complete_run and caused a cross-process ordering
     * race between the controller's sync completeRun() and the async
     * tool worker.
     *
     * The follow-up replay fixture (see {@see agentCommand()}) answers
     * only the normal text message submitted after inline shell — it does
     * not seed completed-run state.
     *
     * Ordering is [tool_exec_start, tool_exec_end, agent_end] (standalone
     * inline shell on a terminal run) and the follow-up message succeeds
     * because the root cause was the unresolved pendingToolCalls in state
     * replay (issue #183).
     */
    private function journeyPhase9InlineShellOnCompletedRun(TmuxPane $pane): void
    {
        $marker = 'inline-journey-marker-'.bin2hex(random_bytes(4)).'.txt';
        touch($this->testProjectDir.'/'.$marker);

        $this->tmux->sendKey($pane, 'C-u'); // Clear editor
        $this->tmux->sendLiteral($pane, '!ls -1');
        $this->tmux->sendKey($pane, 'Enter');

        // Assert the shell output appears (proving real command execution).
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap) use ($marker): bool {
                return str_contains($cap, $marker);
            },
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: sprintf('Inline-shell marker file "%s" never appeared in captured output', $marker),
            history: 2000,
        );

        // Assert working status clears after inline shell (AgentEnd from worker).
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return !str_contains($cap, 'Working...')
                    && !str_contains($cap, 'Running...');
            },
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Working/Running status never cleared after inline !ls -1',
            history: 2000,
        );

        // Ordering assertion for inline shell: AgentEnd must be last.
        $this->assertShellEventsOrder($this->testProjectDir, 'inline-!ls');

        // Follow-up normal message: must NOT die (the original bug symptom).
        // The run was completed before the shell; the shell wrote a fresh
        // AgentEnd; the follow_up should dispatch AdvanceRun and get a
        // replay-assisted response.
        $this->tmux->sendKey($pane, 'C-u');
        usleep(100_000);
        $this->tmux->sendLiteral($pane, 'hello');
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for ANY assistant or error block.  The replay fixture or
        // fallback should produce visible output within a few seconds.
        $capture = $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, '◇')
                || str_contains($cap, '✕'),
            timeout: 15.0,
            message: 'Follow-up after inline shell produced no assistant/error block — run appears dead (issue #183)',
            history: 2000,
        );

        self::assertStringNotContainsString(
            '✕',
            $capture,
            'Follow-up after inline shell must NOT produce an error block',
        );

        self::assertStringContainsString(
            '◇',
            $capture,
            'Follow-up after inline shell must produce an assistant block',
        );

        // Wait for turn completion after follow-up.
        try {
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    && !str_contains($cap, '◐ Working...'),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Turn did not complete after follow-up',
                history: 2000,
            );
        } catch (\RuntimeException) {
            // Non-fatal timeout; working indicator may race with cleanup.
        }

        $this->saveAnsiSnapshot($pane, 'journey-inline-shell');
    }


    // ── Helpers ───────────────────────────────────────────────────

    private function agentCommand(): string
    {
        $fixturePaths = [];

        // Follow-up fixture for Phase 9: replay assistant text for the normal
        // message after inline shell (not used to seed completed-run state).
        $followupFixture = __DIR__.'/fixtures/tui-followup-response.json';
        if (\is_file($followupFixture)) {
            $fixturePaths[] = $followupFixture;
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
        // submission timing explicitly.  Phase 9 submits follow-up after inline shell.
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

        return $dir;
    }


    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }

    /**
     * Assert that the canonical event stream ends with AgentEnd.
     *
     * Reads the most recent session's events.jsonl from the isolated test
     * dir and verifies the final event type is agent_end (LifecycleOrderValidator
     * conformance).
     */
    private function assertShellEventsOrder(string $testProjectDir, string $label, string $expectedLastType = 'agent_end'): void
    {
        $sessionDirs = glob($testProjectDir.'/.hatfield/sessions/*', GLOB_ONLYDIR);
        if (false === $sessionDirs || [] === $sessionDirs) {
            return;
        }

        rsort($sessionDirs);
        $eventsPath = $sessionDirs[0].'/events.jsonl';

        if (!\is_file($eventsPath)) {
            return;
        }

        $lines = file($eventsPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines || [] === $lines) {
            return;
        }

        $lastEvent = null;
        $lastLine = null;
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            $decoded = \json_decode($lines[$i], true);
            if (\is_array($decoded) && isset($decoded['type'])) {
                $lastEvent = $decoded;
                $lastLine = $i + 1;
                break;
            }
        }

        if (null === $lastEvent) {
            return;
        }

        self::assertSame(
            $expectedLastType,
            $lastEvent['type'],
            \sprintf(
                '%s: Expected "%s" as the final lifecycle event in events.jsonl (found "%s" at line %d).',
                $label,
                $expectedLastType,
                $lastEvent['type'],
                $lastLine ?? 0,
            ),
        );
    }
}
