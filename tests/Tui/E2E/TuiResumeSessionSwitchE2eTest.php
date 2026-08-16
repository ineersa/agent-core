<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\ResumeCanonicalEventsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal tmux lifecycle proofs for /resume direct repaint and picker selection.
 *
 * Canonical replay block reconstruction and picker render/escape cleanliness
 * are covered by virtual/initializer tests.
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
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @mkdir($this->snapshotDir, 0o777, true);
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

    public function testResumeRepaintsSelectedSessionInVisiblePane(): void
    {
        $pane = $this->startPane('tui-resume-repaint');

        try {
            $sessionId = $this->createSessionAndWaitForAssistant($pane);
            ResumeCanonicalEventsFixture::write($this->testProjectDir, $sessionId);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $visiblePane = $this->tmux->waitForTuiReadyAfterLogo($pane);
            $this->assertStringContainsString($sessionId, $visiblePane);
            $this->assertStringContainsString('█', $visiblePane);
            $this->assertStringContainsString('◆', $visiblePane);
            $this->assertTrue(
                str_contains($visiblePane, '● idle') || str_contains($visiblePane, '◐ Work'),
                'Resumed session must show active TUI status',
            );

            $this->saveAnsiSnapshot($pane, 'resume-repaint');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'resume-repaint-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function testSelectSessionFromPickerTransitionsCleanly(): void
    {
        $pane = $this->startPane('tui-picker-select');

        try {
            $sessionId = $this->createSessionAndWaitForAssistant($pane);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/resume');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCaptureContains($pane, 'Resume session', 3.0);
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $resumedPane = $this->tmux->waitForTuiReadyAfterLogo($pane);
            $this->assertStringContainsString($sessionId, $resumedPane);
            $this->assertStringNotContainsString('Resume session', $resumedPane);
            $this->assertStringContainsString('● idle', $resumedPane);

            $this->saveAnsiSnapshot($pane, 'resume-picker-select');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'resume-picker-select-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * Real-TTY isolation proof for per-session composition: submit in session
     * N, /new, then assert the fresh session's VISIBLE pane contains neither
     * the old assistant text nor the old session id (positive marker: the
     * fresh-session welcome block).
     */
    public function testNewSessionDoesNotShowPreviousSessionTranscript(): void
    {
        $pane = $this->startPane('tui-new-isolation');

        try {
            $sessionId = $this->createSessionAndWaitForAssistant($pane);

            // The replay-fixture assistant reply must be visible before the switch.
            $this->tmux->waitForCaptureContains($pane, 'Hello from the test harness.', 5.0);

            // /new → controlled same-process rebuild into a fresh lazy draft.
            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/new');
            $this->tmux->sendKey($pane, 'Enter');

            // Positive fresh-session marker: the rebuild is complete once the
            // welcome block is visible AND the fresh frame is fully painted
            // (idle/work status + footer diamond are the last rows the
            // renderer writes).  The welcome block alone can appear while the
            // pane below is still being repainted, so wait for the full frame
            // before asserting the negative proof.
            $this->tmux->waitForCaptureContains($pane, 'Welcome to Hatfield', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $freshPane = $this->tmux->waitForCallback(
                $pane,
                static fn (string $plain): bool => (str_contains($plain, '● idle') || str_contains($plain, '◐ Work'))
                    && str_contains($plain, '◆'),
                timeout: 5.0,
                message: 'Fresh session must reach idle-ready state after the welcome block',
                history: 0,
            );

            // Positive fresh-session marker: the draft welcome block.
            $this->assertStringContainsString('Welcome to Hatfield', $freshPane);
            // Negative proof: session N's assistant text, submitted text, and
            // session label are not visible in the fresh session pane.
            $this->assertStringNotContainsString('Hello from the test harness.', $freshPane);
            $this->assertStringNotContainsString('❯ hi', $freshPane);
            $this->assertStringNotContainsString('session '.$sessionId, $freshPane);

            $this->saveAnsiSnapshot($pane, 'new-session-isolation');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'new-session-isolation-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function startPane(string $prefix): TmuxPane
    {
        return $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: $prefix,
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );
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
        if (!is_file($fixturePath)) {
            $this->fail("Fixture not found: {$fixturePath}");
        }

        $projectDir = ProjectDir::get();
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-resume-');

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
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e');
        @mkdir($dir.'/.hatfield', 0o777, true);

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
                                    'off' => '0', 'minimal' => '0', 'low' => '0', 'medium' => '0', 'high' => '0', 'xhigh' => '0',
                                ],
                                'cost' => ['input' => 0, 'output' => 0],
                            ],
                        ],
                    ],
                ],
            ],
            'extensions' => [
                'enabled' => ['Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension'],
                'settings' => [
                    'safe_guard' => [
                        'tool_names' => ['bash' => 'bash', 'write' => 'write', 'edit' => 'edit', 'read' => 'read'],
                        'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b'],
                        'allow_write_outside_cwd' => [],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                ],
            ],
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump(TuiE2eDatabaseEnv::withSingleLlmWorkerForReplay($settings), 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        @mkdir($dir.'/home/.hatfield', 0o777, true);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        file_put_contents(\sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts), $ansi);
    }
}
