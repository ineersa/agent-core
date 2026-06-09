<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test for the ! shell prefix feature.
 *
 * Starts the agent TUI with bash available (no --tools-excluded=bash),
 * sends !-prefixed shell commands, and verifies output appears in the
 * transcript with working status properly cleared.
 *
 * Also verifies shell commands are recallable via Up/Down prompt history.
 *
 * Uses an isolated project directory with SafeGuard configured to allow
 * deterministic harmless commands (printf, ls, echo).
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
final class ShellPrefixSmokeTest extends TestCase
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
        $this->projectRoot = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDirForShell();
        $this->snapshotDir = $this->testProjectDir . '/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        // TmuxHarness destructor kills all sessions.
    }

    /**
     * @test
     *
     * First-input ! shell command (no prior LLM run):
     *  - launches agent
     *  - sends `!printf shell-prefix-e2e-ok`
     *  - asserts shell output visible
     *  - asserts working status NOT stuck
     *  - sends a normal prompt and asserts the LLM responds
     */
    public function testShellPrefixAsFirstInputCompletesAndAllowsNextPrompt(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommandWithBash(),
            prefix: 'hatfield-sh-first',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for the agent to boot (logo visible).
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // Send shell command as first input. Use a deterministic marker
        // that is distinct from any user-block text so we can assert the
        // actual tool-execution output, not just the command echo.
        $shellMarker = 'e2e-shell-ok-'.bin2hex(random_bytes(4));
        $this->tmux->sendLiteral($pane, '!printf '.$shellMarker);
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for the tool-result block (● prefix) containing our marker.
        // The ● prefix is the TranscriptBlockKind::ToolResult indicator.
        // We capture from history so content that scrolled off is included.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture) use ($shellMarker): bool {
                // Must see the output AND the ● tool-result prefix
                // near it in the transcript (not just the user-block echo).
                return str_contains($capture, $shellMarker)
                    && str_contains($capture, '●');
            },
            timeout: 10.0,
            message: sprintf('Shell output marker "%s" never appeared with tool-result prefix', $shellMarker),
            history: 2000,
        );

        // ── Assert working status cleared ──
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture): bool {
                return !str_contains($capture, 'Working...')
                    && !str_contains($capture, 'Running...');
            },
            timeout: 5.0,
            message: 'Working status never cleared',
            history: 2000,
        );

        // ── Send a normal prompt to verify the session still works ──
        $this->tmux->sendLiteral($pane, 'Say exactly: hello');
        $this->tmux->sendKey($pane, 'Enter');

        $this->waitForLlmResponse($pane, 'hello');
    }

    /**
     * @test
     *
     * Second-turn ! shell command (after an LLM response):
     *  - launches agent, sends normal prompt, waits for response
     *  - sends !printf, asserts output visible
     *  - asserts Working... / Running... cleared
     */
    public function testShellPrefixAfterNormalTurnClearsWorkingStatus(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommandWithBash(),
            prefix: 'hatfield-sh-second',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for boot.
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // Send normal first prompt and wait for LLM response.
        $this->tmux->sendLiteral($pane, 'Say exactly: hello');
        $this->tmux->sendKey($pane, 'Enter');
        $this->waitForLlmResponse($pane, 'hello');

        // Send a shell command with a distinct marker.
        $shellMarker2 = 'e2e-shell-2nd-'.bin2hex(random_bytes(4));
        $this->tmux->sendLiteral($pane, '!printf '.$shellMarker2);
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for tool-result output.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture) use ($shellMarker2): bool {
                return str_contains($capture, $shellMarker2)
                    && str_contains($capture, '●');
            },
            timeout: 10.0,
            message: 'Shell output never appeared with tool-result prefix (second turn)',
            history: 2000,
        );

        // Wait for tick and assert working cleared.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture): bool {
                return !str_contains($capture, 'Working...')
                    && !str_contains($capture, 'Running...');
            },
            timeout: 5.0,
            message: 'Working status never cleared after second-turn shell command',
            history: 2000,
        );
    }

    /**
     * @test
     *
     * ! shell commands should be recallable via Up/Down prompt history.
     *
     * Sends a !printf, waits for output, then presses Up in the editor
     * and asserts the editor contains the submitted shell command.
     */
    public function testShellPrefixSubmissionIsInPromptHistory(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommandWithBash(),
            prefix: 'hatfield-sh-hist',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // Send shell command with distinct marker.
        $histMarker = 'e2e-shell-hist-'.bin2hex(random_bytes(4));
        $fullCmd = '!printf '.$histMarker;
        $this->tmux->sendLiteral($pane, $fullCmd);
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for tool-result output.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture) use ($histMarker): bool {
                return str_contains($capture, $histMarker)
                    && str_contains($capture, '●');
            },
            timeout: 10.0,
            message: 'Shell output never appeared in prompt-history test',
            history: 2000,
        );

        // Wait for tick processing.
        usleep(300_000);

        // Press Up to recall the shell command from prompt history.
        $this->tmux->sendKey($pane, 'Up');

        // Wait for editor to update.
        usleep(200_000);

        // Capture visible pane and assert editor contains the shell command.
        $capture = $this->tmux->capturePlain($pane);

        self::assertStringContainsString(
            $fullCmd,
            $capture,
            'Pressing Up after a shell command should recall it in the editor',
        );
    }

    /**
     * @test
     *
     * The !! prefix must always display a clear unsupported message and
     * must never execute any bash command.
     */
    public function testDoubleExclamationIsRejectedAndNeverExecutes(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommandWithBash(),
            prefix: 'hatfield-sh-bangbang',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // Submit !! which must not execute.
        $this->tmux->sendLiteral($pane, '!!echo should-not-run');
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for rejection message.
        usleep(500_000);

        $capture = $this->tmux->capturePlain($pane);

        self::assertStringContainsString(
            'not supported',
            $capture,
            '!! should produce a not-supported message',
        );

        // Assert the command was NOT executed — output should NOT appear.
        self::assertStringNotContainsString(
            'should-not-run',
            $capture,
            '!! must never execute the command',
        );
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Wait for an LLM response containing the expected text, falling
     * back from assistant block (◇) to error block (✕).
     */
    private function waitForLlmResponse(TmuxPane $pane, string $expectedText): void
    {
        $needle = '◇';
        try {
            $this->tmux->waitForCaptureContains($pane, $needle, 30.0);
        } catch (\RuntimeException $e) {
            $needle = '✕';
            $this->tmux->waitForCaptureContains($pane, $needle, 10.0);
        }

        // Verify the expected text is visible in history.
        // Use history search to catch content that scrolled off-screen.
        try {
            $capture = $this->tmux->waitForHistoryContains($pane, $expectedText, 5.0, 2000);
            self::assertStringContainsString($expectedText, $capture);
        } catch (\RuntimeException $e) {
            // If history search fails, check visible pane as well.
            $visible = $this->tmux->capturePlain($pane);
            self::assertStringContainsString(
                $expectedText,
                $visible,
                \sprintf(
                    'Expected LLM response to contain "%s". Visible pane (%d lines):'."\n%s",
                    $expectedText,
                    \substr_count($visible, "\n") + 1,
                    $visible,
                ),
            );
        }
    }

    private function agentCommandWithBash(): string
    {
        [$php, $script] = AgentTestExecutable::command();

        return \sprintf(
            'APP_ENV=dev HOME=%s %s %s agent --model=llama_cpp_test/test 2>&1',
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDirForShell(): string
    {
        $dir = \sprintf(
            '%s/var/tmp/tui-e2e-sh-%s',
            $this->projectRoot,
            \bin2hex(\random_bytes(6)),
        );
        @\mkdir($dir.'/.hatfield', 0o777, true);
        @\mkdir($dir.'/home/.hatfield', 0o777, true);

        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
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
                        'models' => [
                            'test' => [
                                'name' => 'test',
                                'context_window' => 32768,
                                'max_tokens' => 32768,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
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
                        // Allow deterministic harmless commands for testing.
                        'allow_command_patterns' => ['^printf\b', '^ls\b', '^echo\b'],
                        'allow_write_outside_cwd' => [],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                ],
            ],
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }
}
