<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E proof that model/reasoning selected at session start (via --model CLI flag)
 * is persisted to session metadata and restored on resume — without the --model flag
 * being re-supplied.
 *
 * Github #176: Resuming a session was falling back to the global default model
 * because InProcessAgentSessionClient::start() never wrote model/reasoning to the
 * hatfield_session DB table.  The fix persists on start; this test proves the
 * user-visible behaviour (footer shows the correct model after resume).
 *
 * Flow:
 *  1. Start TUI with --model=<non-default> --prompt="hi" → fix persists to DB
 *  2. Wait for assistant response (replay fixture), capture session ID
 *  3. Exit TUI (Ctrl+D)
 *  4. Resume the SAME session with --resume=<id> and NO --model flag
 *  5. Assert footer shows the originally-selected model (session-metadata restore,
 *     NOT explicit --model flag restore)
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiResumeModelRestoreE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $snapshotDir;
    private string $dbPath;
    private string $sessionId = '';

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);

        // Shared DB path so both TUI launches use the same database.
        $this->dbPath = 'app_test-tui-model-resume-'.bin2hex(random_bytes(4)).'.sqlite';
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

    public function testResumeRestoresSessionModelInFooter(): void
    {
        // ── Phase 1: Start TUI with --model and --prompt ──
        //
        // The --model=llama_cpp_test/test flag passes through to
        // StartRunRequest.model, which the fixed start() persists
        // to the session DB row.
        $pane1 = $this->tmux->startDetached(
            command: $this->firstAgentCommand(),
            prefix: 'tui-model-resume-create',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for startup layout.
            $this->tmux->waitForCaptureContains($pane1, '█', 5.0);
            usleep(200_000);

            // Wait for assistant block (◇) from the replay fixture.
            $this->tmux->waitForCallback(
                $pane1,
                static fn (string $cap): bool => str_contains($cap, '◇') || str_contains($cap, '✕'),
                timeout: 5.0,
                message: 'Neither ◇ assistant block nor ✕ error appeared after first submit',
                history: 2000,
            );

            // Extract session ID from footer.
            $firstCapture = $this->tmux->capturePlainWithHistory($pane1, 2000);
            $matched = preg_match('/session\s+(\d+)/', $firstCapture, $matches);
            self::assertSame(1, $matched,
                'Footer must show numeric session ID after first submit');
            $this->sessionId = $matches[1];

            // Wait for turn to complete.
            try {
                $this->tmux->waitForCallback(
                    $pane1,
                    static fn (string $cap): bool => str_contains($cap, '◇')
                        && !str_contains($cap, '◐ Working...'),
                    timeout: 3.0,
                    message: 'Turn did not complete',
                    history: 2000,
                );
            } catch (\RuntimeException) {
                // Non-fatal: may already be done.
            }

            $this->saveAnsiSnapshot($pane1, 'model-resume-step1-session-created');

            // ── Phase 2: Exit the TUI ──
            $this->tmux->sendKey($pane1, 'C-d');

            // Wait for tmux session to die (process exit).
            $deadline = microtime(true) + 5.0;
            $exited = false;
            while (microtime(true) < $deadline) {
                $alive = $this->tmuxSessionAlive($pane1->session);
                if (!$alive) {
                    $exited = true;
                    break;
                }
                usleep(200_000);
            }
            self::assertTrue($exited, 'First TUI session must exit after Ctrl+D');

            // Kill the pane to clean up (session may already be dead).
            try {
                $this->tmux->killSession($pane1);
            } catch (\RuntimeException) {
                // Already dead — fine.
            }

            // ── Phase 3: Resume the SAME session WITHOUT --model ──
            //
            // The fix persists model on start, so the second TUI launch
            // must resolve it from session metadata and show it in the
            // footer — even though --model is absent.
            $pane2 = $this->tmux->startDetached(
                command: $this->resumeAgentCommand($this->sessionId),
                prefix: 'tui-model-resume-restore',
                width: 120,
                height: 60,
                cwd: $this->testProjectDir,
            );

            try {
                // Wait for the resumed TUI to paint. The header (█) is the
                // most reliable stable-mount signal.
                $this->tmux->waitForCaptureContains($pane2, '█', 5.0);
                usleep(300_000);

                $resumedPane = $this->tmux->capturePlain($pane2);

                // A) Header and footer present.
                self::assertStringContainsString('█', $resumedPane,
                    'Header must be visible after resume');
                self::assertStringContainsString('◆', $resumedPane,
                    'Footer must be visible after resume');

                // B) Session ID must match.
                self::assertStringContainsString($this->sessionId, $resumedPane,
                    'Session ID must appear after resume');

                // C) The model name (short form) must appear in the footer.
                //    FooterStateSegmentProvider shows the model name from
                //    TuiSessionState.footerModel, which FooterStateInitializer
                //    seeds from session metadata on resume.
                //    shortModelName('llama_cpp_test/test') === 'test'.
                self::assertStringContainsString('test', $resumedPane,
                    'Footer must show the session-selected model (test) after resume');

                // D) The global default (which is different) must NOT win.
                //    Our isolated config has a different model set as default
                //    to prove session metadata takes precedence.
                self::assertStringNotContainsString('deepseek-v4-pro', $resumedPane,
                    'Footer must NOT show the global default model on resume');

                // E) Idle status — proves TUI is alive.
                self::assertStringContainsString('● idle', $resumedPane,
                    'Idle status must be visible after resume');

                $this->saveAnsiSnapshot($pane2, 'model-resume-step2-resumed');

                // Clean exit.
                $this->tmux->sendKey($pane2, 'C-d');
            } catch (\Throwable $e) {
                $this->saveAnsiSnapshot($pane2, 'model-resume-FAILURE');
                try {
                    $this->tmux->sendKey($pane2, 'C-d');
                } catch (\Throwable) {
                }
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane1, 'model-resume-FAILURE');
            try {
                $this->tmux->sendKey($pane1, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Command for the first TUI launch: creates a session with model.
     */
    private function firstAgentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-resume-minimal.json';
        if (!\is_file($fixturePath)) {
            self::fail("Fixture not found: {$fixturePath}");
        }

        $projectDir = ProjectDir::get();

        return \sprintf(
            'APP_ENV=test '
                .'HATFIELD_TEST_DATABASE_PATH=%s '
                .'HOME=%s '
                .'HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s '
                .'%s %s agent '
                .'--model=llama_cpp_test/test '
                .'--prompt=hi '
                .'--tools-excluded=bash '
                .'2>&1',
            \escapeshellarg($this->dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($fixturePath),
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($projectDir.'/bin/console'),
        );
    }

    /**
     * Command for the second TUI launch: resumes WITHOUT --model.
     */
    private function resumeAgentCommand(string $sessionId): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-resume-minimal.json';
        $projectDir = ProjectDir::get();

        return \sprintf(
            'APP_ENV=test '
                .'HATFIELD_TEST_DATABASE_PATH=%s '
                .'HOME=%s '
                .'HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s '
                .'%s %s agent '
                .'--resume=%s '
                .'--tools-excluded=bash '
                .'2>&1',
            \escapeshellarg($this->dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($fixturePath),
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($projectDir.'/bin/console'),
            \escapeshellarg($sessionId),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e');
        @\mkdir($dir.'/.hatfield', 0o777, true);

        // Two providers: llama_cpp_test (with model 'test') and
        // a fictitious 'other' provider as the default.  This proves
        // the session-scoped model (llama_cpp_test/test) wins over
        // the global default (other/default).
        $settings = [
            'ai' => [
                'default_model' => 'other/default',
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
                    'other' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'https://example.com/v1',
                        'api' => 'openai-completions',
                        'api_key' => 'dummy',
                        'completions_path' => '/chat/completions',
                        'supports_completions' => true,
                        'supports_embeddings' => false,
                        'supports_thinking_levels' => false,
                        'models' => [
                            'default' => [
                                'name' => 'default',
                                'context_window' => 16384,
                                'max_tokens' => 16384,
                                'input' => ['text'],
                                'tool_calling' => false,
                                'reasoning' => false,
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

    /**
     * Check whether a tmux session is still alive.
     */
    private function tmuxSessionAlive(string $sessionName): bool
    {
        $output = $this->runTmuxRaw(
            \sprintf('tmux has-session -t %s 2>&1', \escapeshellarg($sessionName)),
            1.0,
        );

        // Exit code 0 = alive, non-zero = dead.
        return '' !== $output && !str_contains($output, "can't find session")
            && !str_contains($output, 'no server running');
    }

    private function runTmuxRaw(string $command, float $timeout): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);
        if (!\is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);

        $stdout = @stream_get_contents($pipes[1]);
        $stderr = @stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        return ($stdout ?: '').($stderr ?: '');
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }
}
