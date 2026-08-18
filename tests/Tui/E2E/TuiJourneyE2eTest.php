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
 *  - UI-only tmux steps (/hotkeys, shell) run before model interaction.
 *  - A single model-interaction step submits a prompt and verifies
 *    the replay-backed assistant block appears.
 *  - Teardown sends Ctrl+D for a clean exit; TmuxHarness destructor
 *    kills the tmux session.
 *
 * Harness launch count: 1 (integration smoke). Reasoning and !! proofs live
 * in virtual tests under tests/Tui/Screen/.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiJourneyE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->tmux->setSnapshotDir($this->testProjectDir);
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
     *  2. /hotkeys — real registrars → SubmitListener → mounted HotkeyTableWidget
     *  3. Slash completion — /he opens Completions with /help, Tab accepts, overlay closes
     *  4. Shell !ls prefix — real command output proof + ordering
     *  5. Inline shell on completed run + follow-up (issue #183 repro)
     *  6. Clean exit via Ctrl+D
     *
     * Virtual-only (not in this journey): startup detail {@see TuiStartupVirtualRenderTest},
     * Shift+Tab reasoning status/border {@see TuiReasoningCycleTest},
     *
     * @ file completion menu/accept {@see TuiFileCompletionRenderTest},
     * /export local confirmation {@see TuiExportCommandVirtualTest}; available-tools HTML {@see TuiExportCommandE2eTest},
     * model replay assistant block + cache footer {@see TuiModelInteractionVirtualTest},
     * !! rejection — {@see TuiVirtualInputTest}.
     *
     * !! double-bang rejection is covered by {@see \Ineersa\Tui\Tests\Screen\TuiVirtualInputTest}.
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
            $this->journeyPhaseHotkeysCatalog($pane);
            $this->journeyPhaseSlashCompletion($pane);
            $this->journeyPhase4ShellPrefixOutput($pane);
            $this->journeyPhase9InlineShellOnCompletedRun($pane);
            $this->journeyPhase10ChromeStructureAfterAssistantOutput($pane);

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'journey-FAILURE');
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

        $this->assertStringContainsString('█', $capture, 'Hatfield logo missing');
        $this->assertTrue(
            str_contains($capture, '● idle') || str_contains($capture, '◐ Work'),
            'Working/idle status widget missing',
        );
        $this->assertStringContainsString('◆', $capture, 'Footer widget missing');
        // Session ID in footer is covered by {@see TuiModelInteractionVirtualTest}.
        // At startup the footer shows model, token, timer, CWD, branch.
    }

    /**
     * Phase hotkeys: real /hotkeys slash path through InteractiveMode registrars,
     * SubmitListener structured meta, TranscriptBlockWidgetFactory, and mounted
     * HotkeyTableWidget. Leaves the editor clean for later shell phases.
     */
    private function journeyPhaseHotkeysCatalog(TmuxPane $pane): void
    {
        $this->tmux->sendKey($pane, 'C-u');
        $this->tmux->sendLiteral($pane, '/hotkeys');
        $this->tmux->sendKey($pane, 'Enter');

        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return str_contains($cap, 'Keyboard shortcuts')
                    && str_contains($cap, 'Ctrl+C')
                    && (str_contains($cap, '┌') || str_contains($cap, '│'));
            },
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: '/hotkeys must render Keyboard shortcuts heading, Ctrl+C, and table borders',
            history: 2000,
        );

        $capture = $this->tmux->capturePlainWithHistory($pane, 2000);
        $this->assertStringContainsString('Keyboard shortcuts', $capture);
        $this->assertStringContainsString('Ctrl+C', $capture);
        $this->assertTrue(
            str_contains($capture, 'Global') || str_contains($capture, 'Editor'),
            '/hotkeys table must show a real registrar context group',
        );
        $this->assertTrue(
            str_contains($capture, '┌') || str_contains($capture, '│') || str_contains($capture, '└'),
            '/hotkeys table must show border chrome',
        );

        $this->tmux->saveAnsiSnapshot($pane, 'journey-hotkeys');

        // Clear any residual editor text so later shell phases start clean.
        $this->tmux->sendKey($pane, 'C-u');
    }

    /**
     * Phase completion: slash-command completion through the real
     * CompletionListener → CompletionMenu path (replay-backed, no model
     * interaction needed).
     *
     * Types "/he" — live completion opens the Completions overlay showing
     * the /help suggestion. First Tab accepts it (editor becomes "/help",
     * overlay disappears). Second Tab with the overlay gone must not reopen
     * it. Additional literal text must append after the accepted completion
     * (cursor-at-end contract). Ends with C-u so later shell phases start
     * clean.
     */
    private function journeyPhaseSlashCompletion(TmuxPane $pane): void
    {
        $this->tmux->sendKey($pane, 'C-u'); // Clear editor
        $this->tmux->sendLiteral($pane, '/he');

        // Live completion overlay must appear with the /help suggestion.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return str_contains($cap, 'Completions')
                    && str_contains($cap, '/help');
            },
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Typing "/he" must open the Completions overlay showing /help',
            history: 2000,
        );

        $this->tmux->saveAnsiSnapshot($pane, 'journey-slash-completion-open');

        // First Tab accepts the selected /help suggestion and closes the overlay.
        $this->tmux->sendKey($pane, 'Tab');
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return !str_contains($cap, 'Completions')
                    && str_contains($cap, '/help');
            },
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Tab must accept /help and remove the Completions overlay',
            history: 2000,
        );

        // Second Tab: overlay already gone — must not reopen or submit.
        $this->tmux->sendKey($pane, 'Tab');
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return !str_contains($cap, 'Completions');
            },
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Second Tab must not reopen the Completions overlay',
            history: 2000,
        );

        // Cursor-at-end proof: after Tab accepted "/help ", additional literal
        // text must appear AFTER the accepted completion in the visible editor.
        // This exercises the real terminal → InputEvent → CompletionListener →
        // EditorWidget path; a cursor left at the start would render "xyz/help ".
        $this->tmux->sendLiteral($pane, 'xyz');
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return str_contains($cap, '/help xyz');
            },
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Typing after the accepted completion must append after /help (cursor at end)',
            history: 2000,
        );

        $this->tmux->saveAnsiSnapshot($pane, 'journey-slash-completion-cursor-at-end');

        // Clear editor so later shell phases start clean.
        $this->tmux->sendKey($pane, 'C-u');
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
            message: \sprintf('Marker file "%s" never appeared in captured output for !ls -1', $marker),
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

        // Direct !shell must render the complete bash exchange card (not orphan ToolResult).
        $plain = $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return str_contains($cap, 'bash')
                    && str_contains($cap, 'command:')
                    && str_contains($cap, 'ls -1');
            },
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Direct !ls -1 never rendered bash command: card text',
            history: 2000,
        );
        $this->assertStringContainsString('bash', $plain);
        $this->assertStringContainsString('command:', $plain);
        $this->assertStringContainsString('ls -1', $plain);
        $this->assertStringContainsString($marker, $plain);

        // Colored/styled exchange proof: argument key is themed separately from the colon/value
        // (ToolArgumentColoredFormatter), so plain "command:" may not appear contiguously in ANSI.
        $ansi = $this->tmux->captureAnsi($pane);
        $this->assertMatchesRegularExpression(
            '/\x1b\[[0-9;]*m/',
            $ansi,
            'Direct-shell bash card ANSI capture must include SGR color escapes',
        );
        $this->assertMatchesRegularExpression(
            '/command\x1b\[[0-9;]*m:\s*\x1b\[[0-9;]*m?\s*\'?ls -1\'?|command\x1b\[[0-9;]*m: \'?ls -1\'?/',
            $ansi,
            'ANSI capture must colorize the command argument key separately from its value',
        );
        $this->assertStringContainsString('bash', $ansi);
        $this->assertStringContainsString('ls -1', $ansi);
        $this->tmux->saveAnsiSnapshot($pane, 'journey-direct-shell-card');

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
            message: \sprintf('Inline-shell marker file "%s" never appeared in captured output', $marker),
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

        $this->assertStringNotContainsString(
            '✕',
            $capture,
            'Follow-up after inline shell must NOT produce an error block',
        );

        $this->assertStringContainsString(
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

        $this->tmux->saveAnsiSnapshot($pane, 'journey-inline-shell');
    }

    /**
     * Phase 10: migrated chrome structure/order after replay-backed assistant
     * output (tui-05).
     *
     * After the phase-9 follow-up assistant block the terminal must show the
     * directly mounted native chrome in display order: header logo →
     * transcript/assistant → working/status row → compact header (the journey
     * project ships one skill, so CompactHeaderRegistrar's first-tick snapshot
     * renders a real skills row) → footer. Separator rows (bounded
     * LiveTextWidget KEEP) must still span the full width.
     */
    private function journeyPhase10ChromeStructureAfterAssistantOutput(TmuxPane $pane): void
    {
        $capture = $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, '● idle')
                && str_contains($cap, '◇'),
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Chrome proof requires an idle terminal with the assistant block visible',
            history: 2000,
        );

        $this->assertStringContainsString('█', $capture, 'Native header logo missing after assistant output');
        $this->assertStringContainsString('◇', $capture, 'Assistant block missing');
        $this->assertStringContainsString('● idle', $capture, 'Native working/status row missing');
        $this->assertStringContainsString('◆', $capture, 'Native footer missing');

        // Compact header: the directly mounted CompactHeaderWidget must show
        // the journey skill. strrpos targets the compact-header occurrence
        // (the loaded-resources startup block may list the same skill above
        // the transcript).
        $this->assertStringContainsString('skills', $capture, 'Compact header skills row missing');
        $this->assertStringContainsString('journey-skill', $capture, 'Compact header skill entry missing');

        $logoPos = strpos($capture, '█');
        $assistantPos = strpos($capture, '◇');
        $statusPos = strpos($capture, '● idle');
        $skillPos = strrpos($capture, 'journey-skill');
        $footerPos = strpos($capture, '◆');

        $this->assertNotFalse($logoPos, 'Header logo position missing');
        $this->assertNotFalse($assistantPos, 'Assistant block position missing');
        $this->assertNotFalse($statusPos, 'Status row position missing');
        $this->assertNotFalse($skillPos, 'Compact header skill position missing');
        $this->assertNotFalse($footerPos, 'Footer position missing');
        $this->assertLessThan($assistantPos, $logoPos, 'Header logo must render above the assistant block');
        $this->assertLessThan($statusPos, $assistantPos, 'Assistant block must render above the status row');
        $this->assertLessThan($skillPos, $statusPos, 'Status row must render above the compact header');
        $this->assertLessThan($footerPos, $skillPos, 'Compact header must render above the footer');

        // Separator rows (bounded LiveTextWidget KEEP) still span the width.
        // (The `u` modifier is required: PCRE treats {10,} after a multibyte
        // literal byte-wise without it.)
        $this->assertMatchesRegularExpression(
            '/─{10,}/u',
            $capture,
            'Full-width separator rows must remain in the chrome',
        );

        $this->tmux->saveAnsiSnapshot($pane, 'journey-chrome-order');
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function agentCommand(): string
    {
        $fixturePaths = [];

        // Follow-up fixture for Phase 9: replay assistant text for the normal
        // message after inline shell (not used to seed completed-run state).
        $followupFixture = __DIR__.'/fixtures/tui-followup-response.json';
        if (is_file($followupFixture)) {
            $fixturePaths[] = $followupFixture;
        }

        // Use source bin/console (not PHAR) so APP_ENV=test autoload-dev
        // classes (ControllerReplayHttpClientFactory in tests/) are available.
        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $fixtureEnv = '' !== $fixturePaths
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg(implode(';', $fixturePaths)).' '
            : '';

        // Use an isolated test DB so StartupDatabaseMigrator can auto-migrate
        // on startup without colliding with the shared app_test.sqlite that
        // already has migrations applied.
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-journey');
        $dbPath = $paths['app'];
        $transportDbPath = $paths['transport'];

        // Do NOT use --prompt (auto-submit) — the journey controls
        // submission timing explicitly.  Phase 9 submits follow-up after inline shell.
        // When HATFIELD_LLM_REPLAY_FIXTURE_PATH is set and a prompt is
        // later submitted, ControllerReplayHttpClientFactory serves the
        // fixture response.
        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s %s agent '
                .'--model=llama_cpp_test/test 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e');
        @mkdir($dir.'/.hatfield', 0o777, true);

        // Ship one skill so the real compact header (CompactHeaderRegistrar →
        // directly mounted CompactHeaderWidget) renders a skills row during
        // the journey (phase 10 chrome structure/order proof).
        @mkdir($dir.'/.agents/skills/journey-skill', 0o777, true);
        file_put_contents(
            $dir.'/.agents/skills/journey-skill/SKILL.md',
            "---\nname: journey-skill\ndescription: journey chrome proof skill\n---\n",
        );

        $settings = TuiE2eDatabaseEnv::replayBaseSettings();

        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }

    /**
     * Assert that the canonical event stream ends with AgentEnd.
     *
     * Reads the most recent session's events.jsonl from the isolated test
     * dir and verifies the final event type is agent_end (lifecycle event order
     * conformance).
     */
    private function assertShellEventsOrder(string $testProjectDir, string $label, string $expectedLastType = 'agent_end'): void
    {
        $sessionDirs = glob($testProjectDir.'/.hatfield/sessions/*', \GLOB_ONLYDIR);
        if (false === $sessionDirs || [] === $sessionDirs) {
            return;
        }

        rsort($sessionDirs);
        $eventsPath = $sessionDirs[0].'/events.jsonl';

        if (!is_file($eventsPath)) {
            return;
        }

        $lines = file($eventsPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines || [] === $lines) {
            return;
        }

        $lastEvent = null;
        $lastLine = null;
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            $decoded = json_decode($lines[$i], true);
            if (\is_array($decoded) && isset($decoded['type'])) {
                $lastEvent = $decoded;
                $lastLine = $i + 1;
                break;
            }
        }

        if (null === $lastEvent) {
            return;
        }

        $this->assertSame(
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
