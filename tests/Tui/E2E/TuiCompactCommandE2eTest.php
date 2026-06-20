<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E proof for the /compact slash command.
 *
 * Exercises the real interactive TUI via TmuxHarness and verifies
 * user-visible /compact behaviour:
 *  1. /compact is a registered slash command (visible in /help).
 *  2. Without an active session, /compact shows "No active session to compact."
 *  3. With an active session, /compact shows "Compacting conversation..." progress.
 *  4. A second /compact while compacting shows "Compaction already in progress."
 *
 * Design:
 *  - Single tmux session with APP_ENV=test + replay fixture for model interaction.
 *  - Phase 1: verify /compact without session → error message.
 *  - Phase 2: submit prompt, receive assistant response (replay fixture), then
 *    submit /compact → progress message.
 *  - Phase 3: submit /compact again → "already in progress" message.
 *  - Captures ANSI snapshot on success/failure.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiCompactCommandE2eTest extends TestCase
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
     * Verify /compact slash command visibility and behaviour.
     *
     * Asserts in order:
     *  1. /compact appears in /help output.
     *  2. /compact without active session shows error.
     *  3. After starting a session with a prompt, /compact shows progress.
     *  4. Second /compact shows "already in progress".
     */
    public function testCompactCommandVisibleAndFunctional(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-compact',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup (logo visible).
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            usleep(500_000);

            // ── Phase 1: /compact appears in /help ──
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $this->tmux->sendLiteral($pane, '/help');
            $this->tmux->sendKey($pane, 'Enter');

            $helpCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '/compact'),
                timeout: 5.0,
                message: '/compact not found in /help output',
                history: 2000,
            );

            self::assertStringContainsString(
                '/compact',
                $helpCapture,
                '/compact should appear in /help command listing',
            );
            self::assertStringContainsString(
                'Compact',
                $helpCapture,
                '/compact description should appear in /help',
            );

            // ── Phase 2: /compact without active session → error ──
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $this->tmux->sendLiteral($pane, '/compact');
            $this->tmux->sendKey($pane, 'Enter');

            $noSessionCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'No active session'),
                timeout: 5.0,
                message: 'No active session message not shown',
                history: 2000,
            );

            self::assertStringContainsString(
                'No active session to compact.',
                $noSessionCapture,
                '/compact without active session must show error message',
            );

            // ── Phase 3: Start a session via prompt submission ──
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $prompt = 'Respond with exactly: OK.';
            $this->tmux->sendLiteral($pane, $prompt);
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for assistant response (◇ block).
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇'),
                timeout: 15.0,
                message: 'Assistant response block ◇ did not appear',
                history: 2000,
            );

            // ── Phase 4: /compact with active session → progress ──
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $this->tmux->sendLiteral($pane, '/compact');
            $this->tmux->sendKey($pane, 'Enter');

            $progressCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Compacting conversation'),
                timeout: 10.0,
                message: 'Compacting conversation progress message not shown',
                history: 2000,
            );

            self::assertStringContainsString(
                'Compacting conversation',
                $progressCapture,
                '/compact with active session must show progress message',
            );

            // ── Phase 5: /compact with custom instructions ──
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $this->tmux->sendLiteral($pane, '/compact Focus on key points.');
            $this->tmux->sendKey($pane, 'Enter');

            $customCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Compaction already in progress'),
                timeout: 5.0,
                message: 'Already in progress message not shown',
                history: 2000,
            );

            self::assertStringContainsString(
                'Compaction already in progress.',
                $customCapture,
                'Second /compact must show "already in progress" message',
            );

            // Save ANSI snapshot for inspection.
            $this->saveAnsiSnapshot($pane, 'compact-success');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'compact-FAILURE');
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
        $fixturePath = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-startup-prompt-response.json';
        $fixtureEnv = \is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.\escapeshellarg($fixturePath).' '
            : '';

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';

        $dbPath = 'app_test-tui-compact-'.bin2hex(random_bytes(4)).'.sqlite';

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
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-compact');
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
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }
}
