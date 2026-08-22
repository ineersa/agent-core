<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal tmux proof for /reload: a full-process settings reload that keeps
 * the same PHP process (same pane PID), synchronously tears down the old
 * controller/consumer tree, rebuilds the kernel/container from fresh
 * settings, and resumes the SAME session with its transcript re-rendered.
 *
 * Test thesis: with output cap 500 a read is capped (notice visible, raw
 * sentinel hidden); after rewriting settings.yaml on disk to cap 20000 and
 * running /reload, the same pane/session continues under a fresh controller
 * (session-owner flock re-acquired — old one shut down synchronously, no
 * overlap), and a second read is NOT capped (sentinel visible, no new
 * notice). A clean C-d exit shuts the controller down gracefully.
 *
 * The two-fixture queue (read tool call, then text response) lets each
 * exchange complete to idle so the reload guard passes.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiReloadSettingsE2eTest extends TestCase
{
    /** Visible cap-notice marker (model_notification System block). */
    private const string CAP_NOTICE_MARKER = 'Output capped';

    /** Sentinel that MUST stay hidden while capped and become visible after reload raises the cap. */
    private const string RAW_OUTPUT_SENTINEL = 'OUTPUT_CAP_RAW_SHOULD_BE_HIDDEN_';

    private const string REPLAY_PROMPT = 'Read ./large_file.txt';

    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $settingsPath;
    private string $homeSettingsPath;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->tmux->setSnapshotDir($this->testProjectDir);
        $this->settingsPath = $this->testProjectDir.'/.hatfield/settings.yaml';
        $this->homeSettingsPath = $this->testProjectDir.'/home/.hatfield/settings.yaml';
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

    public function testReloadRebuildsContainerAndResumesSameSessionUnderNewSettings(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-reload',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            // ── Exchange 1: read is capped (settings cap = 500) ──
            $sessionId = $this->exchangeReadPrompt($pane);
            $this->assertTrue($this->waitForIdle($pane), 'Session must be idle after exchange 1');

            $baselineCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            $this->assertStringContainsString(self::CAP_NOTICE_MARKER, $baselineCapture, 'Baseline: cap notice must be visible at cap 500');
            $baselineNoticeCount = mb_substr_count($baselineCapture, self::CAP_NOTICE_MARKER);
            $this->assertStringNotContainsString(self::RAW_OUTPUT_SENTINEL, $baselineCapture, 'Baseline: raw output must be hidden at cap 500');

            $pidBefore = $this->tmux->panePid($pane);
            // First controller must have acquired the session-owner flock.
            $this->assertStringContainsString(
                'controller.session_owner_lock_acquired',
                $this->readAgentLog(),
                'Baseline: first controller must have acquired the session-owner lock',
            );

            // ── Rewrite settings on disk: raise output cap to 20000 ──
            $this->writeSettings(outputCap: 20_000);

            // ── /reload: in-process full bootstrap, same session ──
            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/reload');
            $this->tmux->sendKey($pane, 'Enter');

            // The re-rendered transcript is longer than the visible pane, so
            // the fresh-boot logo scrolls above the fold — poll the scrollback
            // for the resume proof instead of the visible logo.
            $reloadedPane = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Resumed run')
                    && str_contains($cap, '● idle'),
                timeout: TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL,
                message: 'Reload must resume the same session and reach idle',
                history: 2000,
            );

            $this->assertSame($pidBefore, $this->tmux->panePid($pane), 'Reload must keep the same process (in-process bootstrap, no respawn)');
            $this->assertStringContainsString('session '.$sessionId, $reloadedPane, 'Reload must resume the SAME session');
            $this->assertTrue(
                str_contains($reloadedPane, '● idle') || str_contains($reloadedPane, '◐ Work'),
                'Reloaded session must show active TUI status',
            );

            // Transcript blocks re-rendered from canonical events.
            $reloadedCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            $this->assertStringContainsString(self::REPLAY_PROMPT, $reloadedCapture, 'Reloaded transcript must re-render the submitted prompt');
            $this->assertStringContainsString('large_file.txt', $reloadedCapture, 'Reloaded transcript must re-render the tool call');
            $this->assertStringContainsString(self::CAP_NOTICE_MARKER, $reloadedCapture, 'Reloaded transcript must re-render the cap notice from events');

            // Old controller stopped synchronously BEFORE the fresh boot
            // (graceful-shutdown log line) and the new one acquired the
            // session-owner flock — the lock is per controller lifetime, so
            // acquisition proves the old one is gone (no overlap). A conflict
            // line would mean two controllers raced for one session.
            $this->assertTrue(
                $this->waitForLogContains('Controller shutting down gracefully'),
                'After reload the old controller must have shut down gracefully (synchronous teardown)',
            );
            $this->assertTrue(
                $this->waitForLogCount('controller.session_owner_lock_acquired', 2),
                'After reload a fresh controller must have acquired the session-owner lock',
            );
            $this->assertStringNotContainsString(
                'controller.session_owner_lock_conflict',
                $this->readAgentLog(),
                'No controller overlap may occur during reload (session-owner lock conflict)',
            );

            $this->tmux->saveAnsiSnapshot($pane, 'reload-settings');

            // ── Exchange 2: same read, now NOT capped (fresh container, cap 20000) ──
            // The re-rendered transcript already contains one ◇ / session id /
            // idle from exchange 1, so the waits must target NEW content: the
            // second prompt echo, then the sentinel itself.
            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, self::REPLAY_PROMPT);
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => mb_substr_count($cap, '❯ '.self::REPLAY_PROMPT) >= 2,
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Second prompt echo must appear in the scrollback (new exchange after reload)',
                history: 2000,
            );
            $this->assertTrue($this->waitForIdle($pane), 'Session must be idle after exchange 2');
            $this->assertTrue(
                $this->waitForSentinel($pane),
                'New setting proof: raw output must become visible after reload raised the cap',
            );

            $afterCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            $afterNoticeCount = mb_substr_count($afterCapture, self::CAP_NOTICE_MARKER);
            $this->assertSame($baselineNoticeCount, $afterNoticeCount, 'New setting proof: no NEW cap notice may appear after reload raised the cap');
            $this->tmux->saveAnsiSnapshot($pane, 'reload-settings-after-second-read');

            // ── Clean exit: controller must shut down gracefully ──
            $this->tmux->sendKey($pane, 'C-d');
            $this->tmux->waitUntilPaneExits($pane, 15.0);
            $this->assertTrue(
                $this->waitForLogCount('Controller shutting down gracefully', 2, 10.0),
                'Clean C-d exit must shut the second controller down gracefully (no leaked subprocess)',
            );
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'reload-settings-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * Submit the replay prompt and wait for the assistant/error glyph.
     *
     * @return string session id parsed from the capture
     */
    private function exchangeReadPrompt(TmuxPane $pane): string
    {
        $this->tmux->sendKey($pane, 'C-u');
        $this->tmux->sendLiteral($pane, self::REPLAY_PROMPT);
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

    private function waitForIdle(TmuxPane $pane, float $timeout = 10.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $capture = $this->tmux->capturePlainWithHistory($pane, 2000);
            if (preg_match('/●\s*idle/', $capture)) {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    /**
     * Poll the scrollback until the uncapped read output (sentinel) appears.
     */
    private function waitForSentinel(TmuxPane $pane, float $timeout = 15.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $capture = $this->tmux->capturePlainWithHistory($pane, 2000);
            if (str_contains($capture, self::RAW_OUTPUT_SENTINEL)) {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    /**
     * Controller lifecycle log: <CWD>/.hatfield/logs/agent.log, shared by the
     * TUI process and its controller subprocesses (same runtime CWD).
     */
    private function readAgentLog(): string
    {
        // RotatingFileHandler writes agent-<date>.log (and may keep agent.log
        // around); glob both.
        $content = '';
        foreach (glob($this->testProjectDir.'/.hatfield/logs/agent*.log') ?: [] as $path) {
            $chunk = @file_get_contents($path);
            if (false !== $chunk) {
                $content .= $chunk;
            }
        }

        return $content;
    }

    private function waitForLogContains(string $needle, float $timeout = 10.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (str_contains($this->readAgentLog(), $needle)) {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    private function waitForLogCount(string $needle, int $expected, float $timeout = 10.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($expected <= mb_substr_count($this->readAgentLog(), $needle)) {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    private function agentCommand(): string
    {
        $projectDir = ProjectDir::get();
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-reload-');

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($this->fixtureEnvValue()),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($projectDir.'/bin/console'),
        );
    }

    /**
     * Fixture queue: invocation 1 replays the read tool call, invocation 2
     * the text response — each exchange completes to idle so /reload's
     * guards pass. The value doubles as this test's /proc marker.
     */
    private function fixtureEnvValue(): string
    {
        return __DIR__.'/fixtures/tui-output-cap-read.json;'.__DIR__.'/fixtures/tui-simple-text-response.json';
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-reload');
        @mkdir($dir.'/.hatfield', 0o777, true);

        // Oversized file (>500 chars) whose first line carries the sentinel.
        $fileContent = self::RAW_OUTPUT_SENTINEL.'_'.bin2hex(random_bytes(8))."\n"
            .str_repeat('X', 500)."\n"
            .str_repeat('Y', 200)."\n";
        file_put_contents($dir.'/large_file.txt', $fileContent);

        $this->writeSettingsInto($dir, outputCap: 500);

        return $dir;
    }

    private function writeSettings(int $outputCap): void
    {
        $this->writeSettingsInto($this->testProjectDir, $outputCap);
    }

    private function writeSettingsInto(string $root, int $outputCap): void
    {
        $settings = TuiE2eDatabaseEnv::replayBaseSettings();
        $settings = [
            'ai' => $settings['ai'],
            'tools' => [
                'output_cap' => [
                    'path' => '.hatfield/tmp/output-cap',
                    'default_cap' => $outputCap,
                    'doc_cap' => $outputCap,
                ],
            ],
            'extensions' => $settings['extensions'],
        ];

        TuiE2eDatabaseEnv::writeReplaySettings($root, $settings);
    }
}
