<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal real-terminal smoke for the interactive agent TUI.
 *
 * Launches the agent in a detached tmux session, waits for the Hatfield logo,
 * asserts it appears in the pane, then sends Ctrl+D to exit.
 *
 * Runs in an isolated project directory under var/tmp/tui-e2e-* so it does NOT
 * hit the stale project-root .hatfield/state.sqlite.
 *
 * Element-level startup layout assertions live in
 * {@see \Ineersa\Tui\Tests\Screen\TuiStartupVirtualRenderTest} (no tmux).
 */
#[Group('tui-e2e-replay')]
final class TuiStartupSnapshotTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
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

    /**
     * Minimal real-terminal smoke: interactive agent boots and renders the logo.
     *
     * Layout element assertions are covered by
     * {@see \Ineersa\Tui\Tests\Screen\TuiStartupVirtualRenderTest}.
     */
    public function testRealTerminalBootsAndRendersHatfieldLogo(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(withPrompt: false),
            prefix: 'hatfield-startup-smoke',
            cwd: $this->testProjectDir,
        );

        $this->tmux->waitForCaptureContains(
            pane: $pane,
            needle: '█',
            timeout: 10.0,
        );

        $this->tmux->waitForCaptureContains(
            pane: $pane,
            needle: 'ctrl+r to expand',
            timeout: 10.0,
        );

        $capture = $this->tmux->capturePlain($pane);
        $this->assertStringContainsString('█', $capture, 'Hatfield logo missing in real tmux pane');
        $this->assertStringContainsString('ctrl+r to expand', $capture, 'Loaded-resources affordance missing in real tmux pane');

        $this->tmux->sendKey($pane, 'C-r');
        $this->tmux->waitForCaptureContains(
            pane: $pane,
            needle: 'ctrl+r to collapse',
            timeout: 5.0,
        );
        $expanded = $this->tmux->capturePlainWithHistory($pane, 800);
        $normalizedExpanded = str_replace(["\r", "\n"], '', $expanded);
        $this->assertStringContainsString('e2e-startup/SKILL.md', $normalizedExpanded, 'Expanded loaded-resources block should show planted skill source path');

        $this->tmux->sendKey($pane, 'C-d');
    }

    // ── helpers ────────────────────────────────────────────

    private function agentCommand(bool $withPrompt = true): string
    {
        // Use source bin/console (not PHAR) so APP_ENV=test loads
        // config/services_test.yaml with ControllerReplayHttpClientFactory
        // for deterministic replay-based model interaction.
        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';

        $startupFixture = __DIR__.'/fixtures/tui-startup-prompt-response.json';
        $fixtureEnv = is_file($startupFixture)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($startupFixture).' '
            : '';

        $dbPath = 'app_test-tui-snapshot-'.bin2hex(random_bytes(4)).'.sqlite';
        $promptArg = $withPrompt ? ' --prompt="hello from tmux e2e"' : '';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s %s %s agent --model=llama_cpp_test/test%s --tools-excluded=bash 2>&1',
            escapeshellarg($dbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
            $promptArg,
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e', 0o777);
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/.agents', 0o777, true);
        @mkdir($dir.'/.agents/skills/e2e-startup', 0o777, true);
        file_put_contents(
            $dir.'/.agents/skills/e2e-startup/SKILL.md',
            "---\nname: e2e-startup\ndescription: tmux loaded-resources expand proof\n---\n",
        );

        @mkdir($dir.'/home/.hatfield', 0o777, true);

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
                        'allow_command_patterns' => [],
                        'allow_write_outside_cwd' => [],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                ],
            ],
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }
}
