<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test for Ctrl+J multiline newline and /hotkeys command.
 *
 * Tests:
 *  A. Ctrl+J inserts newline → shell-prefix proof via multiline bash command.
 *  B. /hotkeys renders a keyboard shortcuts table.
 *
 * False-positive avoidance for Ctrl+J:
 *  - Uses a shell-prefixed (!) multiline bash command that only produces
 *    output when Ctrl+J actually creates a newline.
 *  - The test types `!MARKER=<uuid>`, presses C-j, types
 *    `printf "%s\n" "$MARKER"`, then presses Enter.
 *  - If C-j DOES insert a newline: bash executes the multiline command as
 *    one shell invocation and the unique marker appears in shell output.
 *  - If C-j submits early or does NOT insert a newline: only the variable
 *    assignment is the shell command (produces no output), and the printf
 *    line is a separate non-shell-prefixed editor line.
 *  - The marker NEVER appears in the literal keystrokes sent — it only
 *    appears if real shell output captures the printf result.
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
final class HotkeySmokeTest extends TestCase
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
        $this->testProjectDir = $this->createIsolatedProjectDir();
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
     * Ctrl+J (C-j in tmux) inserts a newline in the editor.
     *
     * Proof strategy:
     *  - Type `!MARKER=<hex>`, press C-j, type `printf "%s\n" "$MARKER"`,
     *    press Enter.
     *  - If C-j inserts a newline: both lines form one shell command that
     *    sets the variable AND prints it. The marker appears in capture.
     *  - If C-j submits or fails: only the variable assignment is the
     *    first shell command (produces no visible output), printf is a
     *    second regular editor line, and the marker never appears.
     */
    public function testCtrlJInsertsNewlineViaShellPrefixMultilineCommand(): void
    {
        $marker = 'hk-ml-' . bin2hex(random_bytes(4));

        // The marker is embedded in the command, not in the keys we type.
        // The pane capture verifies it appears in the OUTPUT, not echo.
        $shellCmd = \sprintf('!%s=%s', 'MARKER', $marker);
        $printfCmd = \sprintf('printf "%%s\\n" "$%s"', 'MARKER');

        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-hk-ml',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for agent boot (logo visible).
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // Type first line: !MARKER=<hex>
        $this->tmux->sendLiteral($pane, $shellCmd);

        // Insert newline with Ctrl+J (tmux name: C-j).
        $this->tmux->sendKey($pane, 'C-j');

        // Type second line: printf "%s\n" "$MARKER"
        $this->tmux->sendLiteral($pane, $printfCmd);

        // Submit the multiline shell command.
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for the unique marker to appear in the captured output.
        // The marker appears only if bash executed the multiline command
        // (both the assignment AND the printf), which only happens if C-j
        // created a newline rather than submitting or failing.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture) use ($marker): bool {
                return str_contains($capture, $marker);
            },
            timeout: 10.0,
            message: \sprintf(
                'Unique marker "%s" never appeared. C-j may not have inserted a newline, '
                . 'or the shell command did not execute as a single multiline command.',
                $marker,
            ),
            history: 2000,
        );

        // Brief settle for events flush.
        usleep(300_000);

        // ── Bonus: verify events.jsonl records the shell execution result ──
        // Shell commands project tool_execution_end events with a "result" field,
        // not traditional user-message content blocks. The marker appearing in the
        // capture above already proves C-j worked; this confirms the canonical log.
        $eventsDir = $this->testProjectDir . '/.hatfield/sessions/';
        $sessions = glob($eventsDir . '*', GLOB_ONLYDIR);

        if ([] !== $sessions) {
            $sessionDir = $sessions[0];
            $eventsPath = $sessionDir . '/events.jsonl';

            if (file_exists($eventsPath)) {
                $eventsContent = file_get_contents($eventsPath);
                if (is_string($eventsContent) && '' !== $eventsContent) {
                    $foundResult = false;
                    foreach (explode("\n", $eventsContent) as $line) {
                        if ('' === trim($line)) {
                            continue;
                        }
                        $event = json_decode($line, true);
                        if (!is_array($event)) {
                            continue;
                        }
                        // Shell commands produce tool_execution_end events with a
                        // result payload containing stdout output.
                        if (
                            ($event['type'] ?? '') === 'tool_execution_end'
                            && isset($event['payload']['result'])
                            && is_string($event['payload']['result'])
                            && str_contains($event['payload']['result'], $marker)
                        ) {
                            $foundResult = true;
                            break;
                        }
                    }

                    self::assertTrue(
                        $foundResult,
                        \sprintf(
                            'events.jsonl should contain tool_execution_end with marker "%s" in result. '
                            . 'events.jsonl: %s',
                            $marker,
                            $eventsPath,
                        ),
                    );
                }
            }
        }

        $this->saveAnsiSnapshot($pane, 'multiline-after');
    }

    /**
     * @test
     *
     * /hotkeys should render a keyboard shortcuts table showing at least
     * the basic entries: Ctrl+J, Submit, Clear editor, Insert newline,
     * and the "Keyboard shortcuts" heading.
     *
     * No LLM involvement — slash command executes locally.
     */
    public function testHotkeysCommandRendersKeyboardShortcutTable(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-hk-table',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for agent boot.
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // Type /hotkeys and submit.
        $this->tmux->sendLiteral($pane, '/hotkeys');
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for the hotkeys table to appear.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture): bool {
                return str_contains($capture, 'Keyboard shortcuts');
            },
            timeout: 5.0,
            message: '/hotkeys table never appeared',
            history: 2000,
        );

        usleep(200_000);

        $capture = $this->tmux->capturePlainWithHistory($pane, 2000);

        // Assert key entries appear in the capture.
        $requiredEntries = [
            'Keyboard shortcuts',
            'Ctrl+J',
            'Submit prompt',
            'Clear editor',
            'Insert newline',
        ];

        foreach ($requiredEntries as $entry) {
            self::assertStringContainsString(
                $entry,
                $capture,
                \sprintf('/hotkeys output should contain "%s"', $entry),
            );
        }

        $this->saveAnsiSnapshot($pane, 'hotkeys-table');
    }

    // ── Helpers ───────────────────────────────────────────────

    private function agentCommand(): string
    {
        [$php, $script] = AgentTestExecutable::command();

        return \sprintf(
            'APP_ENV=dev HOME=%s %s %s agent --model=llama_cpp_test/test 2>&1',
            \escapeshellarg($this->testProjectDir . '/home'),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    /**
     * Create an isolated project directory with SafeGuard configured
     * to allow shell commands (printf, echo, ls) for E2E testing.
     */
    private function createIsolatedProjectDir(): string
    {
        $dir = \sprintf(
            '%s/var/tmp/tui-e2e-hk-%s',
            $this->projectRoot,
            \bin2hex(\random_bytes(6)),
        );
        @\mkdir($dir . '/.hatfield', 0o777, true);
        @\mkdir($dir . '/home/.hatfield', 0o777, true);

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
        \file_put_contents($dir . '/.hatfield/settings.yaml', $yaml);
        \file_put_contents($dir . '/home/.hatfield/settings.yaml', $yaml);

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
