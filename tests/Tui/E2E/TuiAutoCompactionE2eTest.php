<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * E2E proof that auto-compaction becomes visible in the actual TUI flow.
 *
 * Configures a very low compact_after_tokens threshold so the auto-compaction
 * hook fires after the first turn commit.  Asserts the user-visible
 * "Compacting conversation..." progress message appears without manual /compact.
 *
 * Design:
 *  - Single tmux session with APP_ENV=test + replay fixture for model interaction.
 *  - Isolated project dir with compaction.auto_enabled=true, compact_after_tokens=10.
 *  - Submit prompt, receive assistant response (replay fixture).
 *  - Verify "Compacting conversation..." appears (auto trigger via HookSubscriberInterface).
 *  - Captures ANSI snapshot on success/failure.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiAutoCompactionE2eTest extends TestCase
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
        $this->testProjectDir = $this->createIsolatedProjectDirWithAutoCompaction();
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
     * Auto-compaction triggers after first turn and is visible in TUI.
     *
     * Asserts:
     *  1. Prompt submission works (response block appears).
     *  2. After the turn commit, "Compacting conversation..." appears
     *     WITHOUT typing /compact (proving auto trigger path).
     */
    public function testAutoCompactionTriggeredAndVisibleInTui(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-auto-compact',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup (logo visible).
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            usleep(500_000);

            // Submit a prompt.  The replay fixture provides a response
            // whose token count exceeds the 10-token auto threshold.
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $prompt = 'Respond with a paragraph about AI agents.';
            $this->tmux->sendLiteral($pane, $prompt);
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for assistant response (◇ block or ✕ error block).
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    || str_contains($cap, '✕'),
                timeout: 15.0,
                message: 'Assistant response block did not appear',
                history: 2000,
            );

            // After the turn commits, the AutoCompactionHookSubscriber
            // fires and dispatches CompactRun(trigger: 'auto').  The
            // CompactionProjectionSubscriber renders "Compacting
            // conversation..." as a user-visible block.
            //
            // Wait for that block — it proves the auto trigger path is
            // functional end-to-end (hook → dispatch → handler → runtime
            // events → projection → visible TUI).
            $autoCompactCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Compacting conversation'),
                timeout: 20.0,
                message: 'Auto-compaction progress not shown in TUI',
                history: 2000,
            );

            self::assertStringContainsString(
                'Compacting conversation',
                $autoCompactCapture,
                'Auto-compaction must produce visible "Compacting conversation..." in TUI without manual /compact',
            );

            // Save ANSI snapshot for inspection.
            $this->saveAnsiSnapshot($pane, 'auto-compact-success');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'auto-compact-FAILURE');
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
        $fixturePath = __DIR__.'/fixtures/tui-startup-prompt-response.json';
        $fixtureEnv = \is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.\escapeshellarg($fixturePath).' '
            : '';

        $php = \PHP_BINARY;
        $script = \dirname(__DIR__, 4).'/bin/console';
        $dbPath = 'app_test-tui-auto-compact-'.bin2hex(random_bytes(4)).'.sqlite';

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

    /**
     * Create an isolated project directory with auto-compaction enabled
     * and a very low threshold so compaction triggers on the first turn.
     */
    private function createIsolatedProjectDirWithAutoCompaction(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-auto-compact');
        @\mkdir($dir.'/.hatfield', 0o777, true);

        $homeDir = $dir.'/home';
        @\mkdir($homeDir.'/.hatfield', 0o777, true);

        $settings = $this->buildBaseSettings($dir, [
            'compaction' => [
                'auto_enabled' => true,
                'compact_after_tokens' => 10,
                'keep_recent_tokens' => 5,
            ],
        ]);

        $yaml = Yaml::dump($settings, 6, 4);
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    /**
     * Build base settings matching the TuiCompactCommandE2eTest pattern.
     *
     * @param array<string, mixed> $extra merged into base settings via array_merge_recursive
     *
     * @return array<string, mixed>
     */
    private function buildBaseSettings(string $projectDir, array $extra): array
    {
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
                            ],
                        ],
                    ],
                ],
            ],
            'tools' => [
                'bash' => [
                    'enabled' => true,
                    'working_dir' => $projectDir,
                    'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b'],
                    'allow_write_outside_cwd' => [],
                    'protected_read_patterns' => [],
                    'dangerous_command_patterns' => [],
                ],
            ],
        ];

        return array_merge_recursive($settings, $extra);
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }
}
