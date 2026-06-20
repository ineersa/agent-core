<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E test proving tool call result OUTPUT is visible in the TUI transcript,
 * not just a terse "{tool_name} completed" fallback.
 *
 * Uses a replay fixture that serves a read tool call.  The read tool
 * executes for real in the isolated project directory, reading ./test.txt
 * which contains a unique sentinel string.  After our fix for #131, the
 * actual file content must appear in the transcript instead of "read
 * completed".
 *
 * Design:
 *  - Single tmux session with a replay fixture that returns a read tool_call.
 *  - Isolated project dir has ./test.txt with sentinel content.
 *  - Submits a prompt; the fixture triggers a real read tool execution.
 *  - After tool execution, LLM fixture exhaustion fallback returns "done".
 *  - Asserts sentinel file content is visible in the TUI transcript.
 *  - Asserts "read completed" fallback is absent (output flows now).
 *  - Captures ANSI snapshot on success/failure.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiToolOutputE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $snapshotDir;

    /** Sentinel that the read tool should capture from ./test.txt. */
    private const OUTPUT_SENTINEL = 'TOOL_OUTPUT_SENTINEL_131_READ';

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
     * Submit a prompt that triggers a read tool call via replay fixture.
     *
     * Asserts in order:
     *  1. An assistant block (◇) appears post-tool-execution.
     *  2. The sentinel file content from the real read is visible in the
     *     transcript (proving tool result output is shown, not just
     *     "read completed").
     *  3. The "read completed" fallback label is absent.
     *  4. A session ID appears in the footer.
     */
    public function testToolResultShowsActualOutput(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-tool-output',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);

            // Let the TUI finish startup rendering.
            usleep(500_000);

            // Submit a prompt.  The replay fixture serves a read tool_call;
            // the read tool executes for real; then the LLM fixture exhaustion
            // fallback returns "done".
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);

            $prompt = 'Read ./test.txt';
            $this->tmux->sendLiteral($pane, $prompt);
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the assistant response block (◇) — signals
            // the tool executed and the LLM (fixture fallback) responded.
            $capture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    || str_contains($cap, '✕'),
                timeout: 15.0,
                message: 'Neither ◇ assistant block nor ✕ error block appeared after tool execution',
                history: 2000,
            );

            // The turn must complete with an assistant block (not error).
            self::assertTrue(
                str_contains($capture, '◇'),
                'Transcript must display an assistant block (◇) after tool execution + done response',
            );

            // Capture full transcript history for assertions.
            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 2000);

            // 1. The real tool output (file content) must appear in the transcript.
            self::assertStringContainsString(
                self::OUTPUT_SENTINEL,
                $fullCapture,
                'Tool result must show actual file content, not just "read completed" fallback. '
                .'If this fails, the fix in ToolCallResultHandler did not propagate result text.',
            );

            // 2. The "read completed" fallback must NOT appear (real output flows now).
            // Tool name "read" may appear in the tool CALL block, but "read completed"
            // is the specific fallback label we want to prove absent.
            self::assertStringNotContainsString(
                'read completed',
                $fullCapture,
                'Tool result fallback "read completed" must NOT appear when real output flows. '
                .'The result text replaces the fallback entirely.',
            );

            // 3. Verify session ID in footer.
            self::assertStringContainsString(
                'session ',
                $fullCapture,
                'Session ID should appear in footer after prompt submission',
            );

            // 4. Save ANSI snapshot for inspection.
            $this->saveAnsiSnapshot($pane, 'tool-output');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'tool-output-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-tool-call-read.json';
        $fixtureEnv = \is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.\escapeshellarg($fixturePath).' '
            : '';

        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $dbPath = 'app_test-tui-tool-output-'.bin2hex(random_bytes(4)).'.sqlite';

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
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-tool-output');
        @\mkdir($dir.'/.hatfield', 0o777, true);

        // Create a test file the read tool will read.  The sentinel is
        // what we assert appears in the TUI transcript.
        \file_put_contents($dir.'/test.txt', self::OUTPUT_SENTINEL."\n");

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
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }
}
