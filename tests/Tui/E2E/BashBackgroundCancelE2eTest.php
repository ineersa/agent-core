<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TUI E2E: cancel during bash background-prompt path (issue #205).
 *
 * Thesis: a long bash call that reaches the background confirmation overlay can
 * be cancelled and the TUI returns to idle/cancelled without wedging on schema
 * warnings or dropping terminal events from the poll batch.
 *
 * Escape may first dismiss the confirm overlay (decline backgrounding); a second
 * Escape cancels the run — same as user flow when declining background is not enough.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class BashBackgroundCancelE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $testDatabasePath;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->testDatabasePath = 'app_test-tui-bg-cancel-'.bin2hex(random_bytes(4)).'.sqlite';
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    public function testBashBackgroundPromptPathCanBeCancelledWithoutLeaks(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'bash-bg-cancel',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->tmux->sendKey($pane, 'Escape');
            usleep(100_000);
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);

            $this->tmux->sendLiteral($pane, 'Run sleep 15');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForHistoryContains($pane, 'Running', 20.0);

            // Low background_prompt_threshold_seconds (1s) + sleep 15 → background prompt.
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'background')
                    || str_contains($cap, 'Move it to the background'),
                timeout: 25.0,
                message: 'Bash background prompt did not appear',
                history: 2000,
            );

            // First Escape: decline background confirm overlay if active.
            $this->tmux->sendKey($pane, 'Escape');
            usleep(200_000);
            // Second Escape: cancel the run.
            $this->tmux->sendKey($pane, 'Escape');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Cancelling')
                    || str_contains($cap, 'cancelling')
                    || str_contains($cap, 'Cancelled')
                    || str_contains($cap, '● idle'),
                timeout: 25.0,
                message: 'TUI did not reach cancelling/cancelled/idle after Escape',
                history: 2000,
            );

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Cancelled')
                    || str_contains($cap, '● idle'),
                timeout: 20.0,
                message: 'TUI did not settle to cancelled or idle',
                history: 2000,
            );

            $this->tmux->sendKey($pane, 'C-d');
            usleep(500_000);

            $this->assertNoLeakedWorkersForThisTest();
        } catch (\Throwable $e) {
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function assertNoLeakedWorkersForThisTest(): void
    {
        $root = ProjectDir::get();
        $dbFragment = $this->testDatabasePath;
        $projectFragment = $this->testProjectDir;
        $uid = \function_exists('posix_geteuid') ? posix_geteuid() : null;

        $output = [];
        @exec('ps -eo pid=,uid=,args= 2>/dev/null', $output);
        $leaks = [];
        foreach ($output as $line) {
            $trim = trim($line);
            if ('' === $trim || !preg_match('/^\s*(\d+)\s+(\d+)\s+(.+)$/s', $trim, $m)) {
                continue;
            }
            $pid = (int) $m[1];
            $procUid = (int) $m[2];
            if (null !== $uid && $procUid !== $uid) {
                continue;
            }
            $cmd = $m[3];
            if (!str_contains($cmd, 'messenger:consume') && !str_contains($cmd, 'agent --controller')) {
                continue;
            }
            if (!str_contains($cmd, $root.'/bin/console') && !str_contains($cmd, 'hatfield.phar')) {
                continue;
            }
            if (!str_contains($cmd, $dbFragment) && !str_contains($cmd, $projectFragment)) {
                continue;
            }
            $leaks[] = $pid.' '.$cmd;
        }

        self::assertSame([], $leaks, 'Current-user controller/messenger workers from this test must not survive teardown');
    }

    private function agentCommand(): string
    {
        $fixture = __DIR__.'/fixtures/tui-tool-call-bash-sleep.json';
        $fixtureEnv = is_file($fixture)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixture).' '
            : '';

        $projectDir = ProjectDir::get();

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s%s %s agent --model=llama_cpp_test/test 2>&1',
            escapeshellarg($this->testDatabasePath),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($projectDir.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-bash-bg-cancel');
        @mkdir($dir.'/.hatfield', 0o777, true);

        $settings = [
            'tools' => [
                'bash' => [
                    'background_prompt_threshold_seconds' => 1,
                ],
            ],
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
                                    'off' => '0', 'minimal' => '0', 'low' => '0',
                                    'medium' => '0', 'high' => '0', 'xhigh' => '0',
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
                        'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b', '^sleep\b'],
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
        file_put_contents($dir.'/home/test.txt', 'Hello from bash-bg-cancel test');

        return $dir;
    }
}
