<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test for the ! shell prefix feature.
 *
 * Starts the agent TUI with bash available, sends !-prefixed shell
 * commands, and verifies actual command output appears (not just the
 * submitted command echo).
 *
 * Test integrity design:
 *  - Creates a unique marker file in the isolated project CWD.
 *  - Sends `!ls -1` — the marker filename is NOT part of the
 *    submitted command, so it can ONLY appear if real ls output
 *    is captured.
 *  - Asserts the unique marker file name appears in pane history.
 *  - Asserts working status is cleared after shell command.
 *  - Verifies follow-up normal prompt works after first-input shell.
 *  - Verifies prompt history (Up) recalls submitted shell command.
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
     * First-input !ls -1: creates a unique marker file, sends `!ls -1`
     * (NOT including the marker in the command text), and asserts the
     * marker appears in the captured output — proving real command
     * output was shown. Then sends a normal prompt and verifies the
     * LLM responds, proving the session is not stuck.
     */
    public function testFirstInputShellLsShowsOutputAndAllowsNextPrompt(): void
    {
        // Create a unique marker file whose name is NOT in the command.
        $marker = 'shell-e2e-marker-'.bin2hex(random_bytes(4)).'.txt';
        touch($this->testProjectDir.'/'.$marker);

        $pane = $this->tmux->startDetached(
            command: $this->agentCommandWithBash(),
            prefix: 'hatfield-sh-first',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for agent boot (logo visible).
        $this->tmux->waitForCaptureContains($pane, '█', 5.0);

        // Send `!ls -1` — does NOT include the marker filename.
        $this->tmux->sendLiteral($pane, '!ls -1');
        $this->tmux->sendKey($pane, 'Enter');

        // Assert the unique marker file appears in full pane history
        // (the marker is NOT in the command, so this proves real output).
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture) use ($marker): bool {
                return str_contains($capture, $marker);
            },
            timeout: 5.0,
            message: sprintf(
                'Marker file "%s" never appeared in captured output for !ls -1',
                $marker,
            ),
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
            message: 'Working/Running status never cleared after !ls -1',
            history: 2000,
        );

        // ── Send a normal prompt to verify the session is not stuck ──
        $this->tmux->sendLiteral($pane, 'Say exactly: hello');
        $this->tmux->sendKey($pane, 'Enter');
        $this->waitForLlmResponse($pane, 'hello');
    }

    /**
     * @test
     *
     * Second-turn !ls -1 after a normal LLM prompt:
     *  - Sends normal prompt, waits for response
     *  - Sends !ls -1 with a unique marker file (marker NOT in command)
     *  - Asserts marker appears in captured output
     *  - Asserts working/status clears
     */
    public function testShellPrefixAfterNormalTurnShowsOutputAndClearsStatus(): void
    {
        // Create a unique marker file for the second turn.
        $marker2 = 'shell-e2e-2nd-'.bin2hex(random_bytes(4)).'.txt';
        touch($this->testProjectDir.'/'.$marker2);

        $pane = $this->tmux->startDetached(
            command: $this->agentCommandWithBash(),
            prefix: 'hatfield-sh-second',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        $this->tmux->waitForCaptureContains($pane, '█', 5.0);

        // Send normal first prompt and wait for LLM response.
        $this->tmux->sendLiteral($pane, 'Say exactly: hello');
        $this->tmux->sendKey($pane, 'Enter');
        $this->waitForLlmResponse($pane, 'hello');

        // Send !ls -1 (marker NOT in the command text).
        $this->tmux->sendLiteral($pane, '!ls -1');
        $this->tmux->sendKey($pane, 'Enter');

        // Assert marker file appears in captured output.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture) use ($marker2): bool {
                return str_contains($capture, $marker2);
            },
            timeout: 5.0,
            message: sprintf(
                'Marker file "%s" never appeared in captured output for second-turn !ls -1',
                $marker2,
            ),
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
            message: 'Working status never cleared after second-turn shell command',
            history: 2000,
        );
    }

    /**
     * @test
     *
     * !ls shell command should be recallable via Up prompt history.
     */
    public function testShellPrefixSubmissionIsInPromptHistory(): void
    {
        $marker3 = 'shell-e2e-hist-'.bin2hex(random_bytes(4)).'.txt';
        touch($this->testProjectDir.'/'.$marker3);

        $pane = $this->tmux->startDetached(
            command: $this->agentCommandWithBash(),
            prefix: 'hatfield-sh-hist',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        $this->tmux->waitForCaptureContains($pane, '█', 5.0);

        $fullCmd = '!ls -1';
        $this->tmux->sendLiteral($pane, $fullCmd);
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for marker file to appear — proves command executed.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture) use ($marker3): bool {
                return str_contains($capture, $marker3);
            },
            timeout: 5.0,
            message: 'Shell output never appeared for prompt-history test',
            history: 2000,
        );

        // Wait for working status to clear before checking prompt history.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture): bool {
                return !str_contains($capture, 'Working...')
                    && !str_contains($capture, 'Running...');
            },
            timeout: 2.0,
            message: 'Working/Running status never cleared before prompt-history recall.',
            history: 2000,
        );

        // Press Up to recall the shell command from prompt history.
        $this->tmux->sendKey($pane, 'Up');

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
     * The !! prefix must display a clear unsupported message and
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

        $this->tmux->waitForCaptureContains($pane, '█', 5.0);

        $this->tmux->sendLiteral($pane, '!!echo should-not-run');
        $this->tmux->sendKey($pane, 'Enter');

        $capture = $this->tmux->waitForCaptureContains(
            $pane,
            'not supported',
            2.0,
        );

        self::assertStringContainsString(
            'not supported',
            $capture,
            '!! should produce a not-supported message',
        );

        self::assertStringNotContainsString(
            'should-not-run',
            $capture,
            '!! must never execute the command',
        );
    }

    // ── Helpers ───────────────────────────────────────────────

    private function waitForLlmResponse(TmuxPane $pane, string $expectedText): void
    {
        try {
            $this->tmux->waitForCaptureContains($pane, '◇', 5.0);
        } catch (\RuntimeException $e) {
            $this->tmux->waitForCaptureContains($pane, '✕', 2.0);
        }

        try {
            $capture = $this->tmux->waitForHistoryContains($pane, $expectedText, 5.0, 2000);
            self::assertStringContainsString($expectedText, $capture);
        } catch (\RuntimeException $e) {
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
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }
}
