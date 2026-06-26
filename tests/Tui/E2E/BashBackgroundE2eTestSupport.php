<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Symfony\Component\Yaml\Yaml;

/**
 * Shared isolated project + leak detection for bash background TUI E2E tests.
 */
trait BashBackgroundE2eTestSupport
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $testDatabasePath;
    private string $leakTag;

    protected function setUpBashBackgroundE2e(string $leakTagPrefix, string $tempDirPrefix): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedBashBackgroundProjectDir($tempDirPrefix);
        $this->testDatabasePath = 'app_test-'.$tempDirPrefix.'-'.bin2hex(random_bytes(4)).'.sqlite';
        $this->leakTag = $leakTagPrefix.'-'.bin2hex(random_bytes(8));
    }

    protected function tearDownBashBackgroundE2e(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    protected function agentCommandWithFixtures(string ...$fixtureRelativePaths): string
    {
        $paths = [];
        foreach ($fixtureRelativePaths as $rel) {
            $full = __DIR__.'/fixtures/'.$rel;
            if (is_file($full)) {
                $paths[] = $full;
            }
        }

        $fixtureEnv = [] !== $paths
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg(implode(';', $paths)).' '
            : '';

        $projectDir = ProjectDir::get();

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HATFIELD_E2E_LEAK_TAG=%s HOME=%s %s%s %s agent --model=llama_cpp_test/test 2>&1',
            escapeshellarg($this->testDatabasePath),
            escapeshellarg($this->leakTag),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($projectDir.'/bin/console'),
        );
    }

    protected function prepareEditorForUserPrompt(TmuxHarness $tmux, TmuxPane $pane): void
    {
        $tmux->waitForCaptureContains($pane, '█', 10.0);
        $tmux->waitForTuiReadyAfterLogo($pane);
        $tmux->sendKey($pane, 'Escape');
        usleep(100_000);
        $tmux->sendKey($pane, 'C-u');
        usleep(100_000);
    }

    protected function waitForBashBackgroundPrompt(TmuxHarness $tmux, TmuxPane $pane): void
    {
        $tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, 'Move it to the background')
                || (str_contains($cap, 'Confirmation required') && str_contains($cap, 'Yes')),
            timeout: 12.0,
            message: 'Bash background confirm overlay did not appear',
            history: 4000,
        );
    }

    protected function assertNoLeakedWorkersForThisTestWithRetry(): void
    {
        $deadline = microtime(true) + 5.0;
        $lastLeaks = [];

        while (microtime(true) < $deadline) {
            $lastLeaks = $this->collectLeakedWorkersForThisTest();
            if ([] === $lastLeaks) {
                self::assertSame([], $lastLeaks, 'Current-user controller/messenger workers from this test must not survive teardown');

                return;
            }
            usleep(200_000);
        }

        self::assertSame([], $lastLeaks, 'Current-user controller/messenger workers from this test must not survive teardown');
    }

    /**
     * @return list<string>
     */
    private function collectLeakedWorkersForThisTest(): array
    {
        $root = ProjectDir::get();
        $dbFragment = $this->testDatabasePath;
        $projectFragment = $this->testProjectDir;
        $leakTag = $this->leakTag;
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
            $matchesArgsScope = str_contains($cmd, $dbFragment) || str_contains($cmd, $projectFragment);
            $matchesLeakTag = $this->processEnvironContainsLeakTag($pid, $leakTag);
            if (!$matchesArgsScope && !$matchesLeakTag) {
                continue;
            }
            $leaks[] = $pid.' '.$cmd;
        }

        return $leaks;
    }

    private function processEnvironContainsLeakTag(int $pid, string $leakTag): bool
    {
        if ($pid <= 0 || '' === $leakTag) {
            return false;
        }

        $environPath = '/proc/'.$pid.'/environ';
        if (!is_readable($environPath)) {
            return false;
        }

        $raw = @file_get_contents($environPath);
        if (false === $raw || '' === $raw) {
            return false;
        }

        $needle = 'HATFIELD_E2E_LEAK_TAG='.$leakTag;

        return str_contains($raw, $needle);
    }

    private function createIsolatedBashBackgroundProjectDir(string $prefix): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir($prefix);
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
                            'bg_status' => 'bg_status',
                        ],
                        'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b', '^sleep\b'],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                ],
            ],
        ];

        $yaml = Yaml::dump($settings, 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        @mkdir($dir.'/home/.hatfield', 0o777, true);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);
        file_put_contents($dir.'/home/test.txt', 'Hello from bash background E2E test');

        return $dir;
    }
}
