<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal tmux proof for the compaction-specific visible progress path.
 *
 * Handler/help/re-entrancy/failure/async lifecycle coverage lives in
 * listener, virtual, controller-replay, and subscriber tests.
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
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    public function testCompactProgressMessageVisibleInRealTui(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-compact-smoke',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->tmux->sendLiteral($pane, 'Respond with exactly: OK.');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇') || str_contains($cap, '✕'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Assistant response block did not appear before /compact',
                history: 2000,
            );

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, '/compact');
            $this->tmux->sendKey($pane, 'Enter');

            $progressCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Compacting conversation'),
                timeout: 10.0,
                message: 'Compacting conversation progress message not shown',
                history: 2000,
            );

            $this->assertStringContainsString('Compacting conversation', $progressCapture);

            $this->saveAnsiSnapshot($pane, 'compact-progress-smoke');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'compact-progress-smoke-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $fixturePath = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-startup-prompt-response.json';
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $dbPath = 'app_test-tui-compact-'.bin2hex(random_bytes(4)).'.sqlite';

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
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-compact');
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
