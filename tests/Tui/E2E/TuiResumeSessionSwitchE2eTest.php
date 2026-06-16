<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E proof that /resume <session-id> cleanly re-renders the full TUI
 * layout on the CURRENT VISIBLE PANE — no stale scrollback leakage.
 *
 * The prior version of this test used waitForHistoryContains() and
 * capturePlainWithHistory() for the proof, which allowed stale
 * terminal scrollback from before the session switch to satisfy the
 * assertions.  This version uses waitForCaptureContains() and
 * capturePlain() (visible pane only) for all post-/resume assertions
 * so the proof is against what the user actually sees.
 *
 * Flow:
 *  1. Start TUI in draft mode, wait for startup layout.
 *  2. Type "hi" + Enter → session is created, replay fixture responds.
 *  3. Extract the numeric session ID from footer history.
 *  4. /resume <id> → session switch back to the created session.
 *  5. Wait for clean re-render via visible-pane header detection.
 *  6. Assert visible pane contains a valid TUI layout with no
 *     duplicate Working status rows, no orphaned stream fragments,
 *     and no raw escape-sequence leakage.
 *
 * Uses a minimal replay fixture (3 deltas: thinking, text, finish)
 * for fast deterministic playback.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiResumeSessionSwitchE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
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

    public function testResumeAfterNewRendersCleanVisiblePane(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-resume',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // ── Phase 1: Startup layout ──
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);
            usleep(200_000);

            // ── Phase 2: Type "hi" + Enter → creates session, gets replay response ──
            $this->tmux->sendLiteral($pane, 'hi');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for assistant block (◇) in the visible pane.
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇') || str_contains($cap, '✕'),
                timeout: 5.0,
                message: 'Neither ◇ assistant block nor ✕ error block appeared after first submit',
                history: 2000,
            );

            // Prove the replay response text appears in the transcript
            // (history is fine for content extraction — this is not a
            //  layout assertion, just a content check).
            $firstCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            self::assertStringContainsString('Hello', $firstCapture, 'Replay response must be visible after first submit');

            // Extract session ID from footer history.
            $matched = preg_match('/session\s+(\d+)/', $firstCapture, $matches);
            self::assertSame(1, $matched, 'Footer must show numeric session ID after first submit');
            $sessionId = $matches[1];

            // Wait for turn to complete (Working spinner gone) before switching.
            try {
                $this->tmux->waitForCallback(
                    $pane,
                    static fn (string $cap): bool => str_contains($cap, '◇')
                        && !str_contains($cap, '◐ Working...'),
                    timeout: 3.0,
                    message: 'Turn did not complete before session switch',
                    history: 2000,
                );
            } catch (\RuntimeException) {
                // Non-fatal: may already be done.
            }

            $this->saveAnsiSnapshot($pane, 'resume-step1-first-session');

            // ── Phase 3: /resume <sessionId> — resume the same session ──
            // Clear the editor and issue the resume command.
            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the header (█) to appear in the VISIBLE PANE.
            // Using waitForCaptureContains (not waitForHistoryContains)
            // so we prove the re-render painted the header on the
            // current screen, not that it's buried in scrollback.
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);
            usleep(300_000);

            // ── Phase 4: Assert clean visible-pane layout ──
            // Capture ONLY the visible pane — no scrollback history.
            $visiblePane = $this->tmux->capturePlain($pane);

            // A) Structural layout elements must be present.
            self::assertStringContainsString('█', $visiblePane,
                'Hatfield logo (header) must be present in the visible pane after /resume');
            self::assertStringContainsString('◆', $visiblePane,
                'Footer must be present in the visible pane after /resume');
            self::assertTrue(
                str_contains($visiblePane, '● idle') || str_contains($visiblePane, '◐ Work'),
                'Working/idle status must be present in the visible pane after /resume',
            );

            // B) Session/transcript content must be rendered.
            self::assertStringContainsString($sessionId, $visiblePane,
                'Session ID must appear in the visible pane (footer or resume block)');
            self::assertStringContainsString('Hello', $visiblePane,
                'Original replay response must be visible in the transcript after /resume');

            // C) No orphaned streaming fragments or duplicate status rows.
            //    After /resume the TUI should show exactly one Working
            //    status line and at most one copy of any marker.
            self::assertStringNotContainsString('◇ </think>', $visiblePane,
                'Streaming thinking fragments must not appear in the visible pane after /resume');

            $runningCount = \substr_count($visiblePane, '● Running…');
            self::assertLessThanOrEqual(1, $runningCount,
                \sprintf(
                    'At most one "● Running…" may appear in the visible pane after /resume (found %d). '
                    .'Multiple copies mean stale terminal output leaked.',
                    $runningCount,
                ));

            // D) No raw escape-sequence leakage.
            self::assertStringNotContainsString('[2J', $visiblePane,
                'Escape [2J must not leak into visible pane');
            self::assertStringNotContainsString('[3J', $visiblePane,
                'Escape [3J must not leak into visible pane');

            $this->saveAnsiSnapshot($pane, 'resume-step2-resumed');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'resume-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    // ── Setup ─────────────────────────────────────────────────────

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-resume-minimal.json';
        if (!\is_file($fixturePath)) {
            self::fail("Fixture not found: {$fixturePath}");
        }

        $projectDir = ProjectDir::get();
        $dbPath = 'app_test-tui-resume-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test '
                .'HATFIELD_TEST_DATABASE_PATH=%s '
                .'HOME=%s '
                .'HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s '
                .'%s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash '
                .'2>&1',
            \escapeshellarg($dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($fixturePath),
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($projectDir.'/bin/console'),
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

        @\mkdir($dir.'/home/.hatfield', 0o777, true);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        \file_put_contents(
            \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts),
            $ansi,
        );
    }
}
