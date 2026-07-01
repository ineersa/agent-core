<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Replay-backed tmux proof: /tree file restore rewinds workspace files.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiTreeFileRestoreE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        file_put_contents($this->testProjectDir.'/target.txt', "before\n");
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    public function testTreeRestoreFilesRewindsWorkspaceToCheckpoint(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-tree-file-restore',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            // Turn 1: no file mutation — checkpoint should capture "before"
            $this->submitPrompt($pane, 'hello');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Turn 1 assistant block did not appear',
                history: 2000,
            );
            self::assertSame("before\n", file_get_contents($this->testProjectDir.'/target.txt'));

            // Turn 2: edit tool changes target.txt to "after"
            $this->submitPrompt($pane, 'Edit target.txt');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => substr_count($cap, '◇') >= 2,
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Turn 2 assistant block did not appear',
                history: 2500,
            );
            self::assertStringContainsString('after', (string) file_get_contents($this->testProjectDir.'/target.txt'));

            $this->openTreePicker($pane);

            // Current leaf is turn 2; move to turn 1
            $this->tmux->sendKey($pane, 'Up');
            usleep(80_000);
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'File rewind'),
                timeout: 10.0,
                message: 'File rewind choice overlay did not appear',
                history: 2000,
            );

            // Options: keep (0), cancel (1), restore (2), undo (3)
            $this->tmux->sendKey($pane, 'Down');
            usleep(40_000);
            $this->tmux->sendKey($pane, 'Down');
            usleep(40_000);
            $this->tmux->sendKey($pane, 'Enter');

            usleep(500_000);

            self::assertSame("before\n", file_get_contents($this->testProjectDir.'/target.txt'));

            $this->saveAnsiSnapshot($pane, 'tree-file-restore-ok');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'tree-file-restore-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function submitPrompt(TmuxPane $pane, string $text): void
    {
        $this->tmux->sendKey($pane, 'C-u');
        usleep(50_000);
        $this->tmux->sendLiteral($pane, $text);
        $this->tmux->sendKey($pane, 'Enter');
    }

    private function openTreePicker(TmuxPane $pane): void
    {
        $this->tmux->sendKey($pane, 'C-u');
        usleep(50_000);
        $this->tmux->sendLiteral($pane, '/tree');
        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, 'Turn 1:') && str_contains($cap, 'rewind'),
            timeout: 10.0,
            message: 'Tree picker did not open',
            history: 2000,
        );
    }

    private function agentCommand(): string
    {
        $edit = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-tool-call-edit.json';
        $follow = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-followup-response.json';
        $fixtureEnv = '';
        if (is_file($edit) && is_file($follow)) {
            $fixtureEnv = 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($follow.';'.$edit).' ';
        }

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $dbPath = 'app_test-tui-tree-file-restore-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            escapeshellarg($dbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-tree-file-restore');
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

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
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
