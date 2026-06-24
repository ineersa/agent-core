<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TUI E2E proof: Escape after controller transport restart-limit failure
 * must not silently no-op — user sees cancel recovery or explicit error.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiTransportRestartLimitEscapeE2eTest extends TestCase
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

    public function testEscapeAfterTransportRestartLimitShowsRecovery(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-transport-restart-escape',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->tmux->sendKey($pane, 'C-u');
            \usleep(100_000);

            $this->tmux->sendLiteral($pane, 'Run sleep 20');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForHistoryContains($pane, 'Running', 25.0);

            $panePid = $this->tmux->panePid($pane);
            $this->killControllerDescendantsUntilRestartLimit($panePid);

            $this->tmux->waitForHistoryContains(
                $pane,
                'crashed too many times',
                60.0,
            );

            $this->tmux->sendKey($pane, 'Escape');

            $afterEscape = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Cancel failed:')
                    || str_contains($cap, 'Please restart the agent'),
                timeout: 15.0,
                message: 'Escape must show cancel recovery after transport restart limit',
                history: 3000,
            );

            self::assertTrue(
                str_contains($afterEscape, 'Cancel failed:')
                    || str_contains($afterEscape, 'Please restart the agent'),
                'Visible recovery text required after Escape on transport failure',
            );

            $this->saveAnsiSnapshot($pane, 'transport-restart-limit-escape-ok');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'transport-restart-limit-escape-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * SIGKILL controller children under the tmux pane until events() hits restart limit.
     */
    private function killControllerDescendantsUntilRestartLimit(int $panePid): void
    {
        $uid = posix_getuid();
        $deadline = \microtime(true) + 40.0;
        $kills = 0;

        while (\microtime(true) < $deadline && $kills < 8) {
            foreach ($this->findControllerPidsUnder($panePid, $uid) as $pid) {
                if ($pid > 1) {
                    posix_kill($pid, \SIGKILL);
                    ++$kills;
                    \usleep(400_000);
                }
            }
            \usleep(600_000);
        }
    }

    /**
     * @return list<int>
     */
    private function findControllerPidsUnder(int $rootPid, int $uid): array
    {
        $descendants = $this->collectDescendantPids($rootPid);
        $found = [];

        foreach ($descendants as $pid) {
            $cmdline = @\file_get_contents('/proc/'.$pid.'/cmdline');
            if (!\is_string($cmdline) || !str_contains($cmdline, 'agent') || !str_contains($cmdline, '--controller')) {
                continue;
            }
            $stat = @\file_get_contents('/proc/'.$pid.'/status');
            if (!\is_string($stat) || !\preg_match('/^Uid:\s+(\d+)/m', $stat, $m) || (int) $m[1] !== $uid) {
                continue;
            }
            $found[] = $pid;
        }

        return $found;
    }

    /**
     * @return list<int>
     */
    private function collectDescendantPids(int $rootPid): array
    {
        $ppidMap = [];
        foreach (\scandir('/proc') ?: [] as $entry) {
            if (!\ctype_digit($entry)) {
                continue;
            }
            $pid = (int) $entry;
            $stat = @\file_get_contents('/proc/'.$pid.'/stat');
            if (!\is_string($stat)) {
                continue;
            }
            if (!\preg_match('/^\d+ \([^)]*\) \w (\d+)/', $stat, $m)) {
                continue;
            }
            $ppidMap[$pid] = (int) $m[1];
        }

        $descendants = [];
        $queue = [$rootPid];
        $seen = [$rootPid => true];

        while ([] !== $queue) {
            $current = (int) \array_shift($queue);
            foreach ($ppidMap as $pid => $ppid) {
                if ($ppid !== $current || isset($seen[$pid])) {
                    continue;
                }
                $seen[$pid] = true;
                $descendants[] = $pid;
                $queue[] = $pid;
            }
        }

        return $descendants;
    }

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-tool-call-bash-sleep.json';
        $fixtureEnv = \is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.\escapeshellarg($fixturePath).' '
            : '';

        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';
        $dbPath = 'app_test-tui-transport-restart-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s %s %s agent '
                .'--transport=process '
                .'--model=llama_cpp_test/test '
                .'2>&1',
            \escapeshellarg($dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-transport-restart');
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