<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Startup snapshot test for the agent TUI.
 *
 * Launches the agent in a detached tmux session at 120×40,
 * waits for the startup layout to render, captures a plain-text
 * snapshot, normalises dynamic content, and compares against
 * the committed golden fixture.
 *
 * After capture, sends Ctrl+D to exit the interactive TUI cleanly.
 *
 * Runs in an isolated project directory under var/tmp/tui-e2e-*
 * so it does NOT hit the stale project-root .hatfield/messenger.sqlite.
 *
 */
#[Group('tui-e2e-replay')]
final class TuiStartupSnapshotTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $goldenPath;
    private string $projectRoot;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
        $this->goldenPath = $this->projectRoot.'/tests/Tui/Snapshots/startup-120x40.txt';
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
     * Verify the agent TUI startup layout matches the golden snapshot.
     *
     * Starts the interactive TUI in a tmux pane, waits for the
     * Hatfield logo to render, captures the snapshot, then exits
     * cleanly via Ctrl+D.
     */
    public function testStartupLayoutMatchesGoldenSnapshot(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-startup',
            cwd: $this->testProjectDir,
        );

        // Wait for the TUI to render — looking for the Hatfield logo
        $this->tmux->waitForCaptureContains(
            pane: $pane,
            needle: '█',
            timeout: 5.0,
        );

        // Wait for the auto-submitted prompt to appear in the transcript.
        // This ensures the snapshot captures the TUI *after* the prompt
        // was submitted (showing the prompt + ◐ Working...) rather than
        // at the pre-submission idle state (which lacks the prompt line).
        $this->tmux->waitForCaptureContains(
            pane: $pane,
            needle: 'hello from tmux e2e',
            timeout: 5.0,
        );

        $capture = $this->tmux->capturePlain($pane);

        // Send Ctrl+D to exit the interactive TUI cleanly
        $this->tmux->sendKey($pane, 'C-d');

        $normalized = $this->tmux->normalizeSnapshot($capture);

        if ($this->shouldUpdateSnapshots()) {
            file_put_contents($this->goldenPath, $normalized);
            self::markTestSkipped(sprintf(
                'Golden snapshot updated: %s (commit this change)',
                basename($this->goldenPath),
            ));
        }

        // Load expected golden
        self::assertFileExists($this->goldenPath, sprintf(
            'Golden fixture not found: %s. Run HATFIELD_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --group tui-e2e to generate it.',
            $this->goldenPath,
        ));

        $expected = file_get_contents($this->goldenPath);

        self::assertSame(
            $expected,
            $normalized,
            sprintf(
                "TUI startup snapshot does not match golden fixture.\n"
                ."Expected: %s\n"
                ."Got (normalized):\n%s\n"
                ."If this change is intentional, run HATFIELD_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --group tui-e2e",
                $this->goldenPath,
                $normalized !== $expected ? $this->diffHint($expected, $normalized) : '(same)',
            ),
        );
    }

    /**
     * Verify the startup snapshot contains expected key strings.
     *
     * This is a less brittle assertion than an exact golden match.
     */
    public function testStartupContainsExpectedElements(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-startup-elements',
            cwd: $this->testProjectDir,
        );

        $this->tmux->waitForCaptureContains(
            pane: $pane,
            needle: '█',
            timeout: 5.0,
        );

        // Wait for the auto-submitted prompt to appear in the transcript.
        // This ensures the capture reflects the post-submission state
        // (showing the prompt + ◐ Working...) rather than idle startup.
        $this->tmux->waitForCaptureContains(
            pane: $pane,
            needle: 'hello from tmux e2e',
            timeout: 5.0,
        );

        $capture = $this->tmux->capturePlain($pane);

        // Send Ctrl+D to exit cleanly
        $this->tmux->sendKey($pane, 'C-d');

        // Key layout elements should be present
        self::assertStringContainsString('█', $capture, 'Hatfield logo (box drawing) missing');
        // Working status widget shows '● idle' when idle or '◐ Working...' when active
        self::assertTrue(
            str_contains($capture, '● idle') || str_contains($capture, '◐ Work'),
            'Working status widget missing. Capture: '.substr($capture, 0, 2000),
        );
        self::assertStringContainsString('◆', $capture, 'Footer widget missing');
        self::assertStringContainsString('session ', $capture, 'Session ID in footer missing');
        self::assertStringContainsString('Welcome', $capture, 'Welcome message missing');
    }
    // ── helpers ────────────────────────────────────────────

    private function agentCommand(): string
    {
        // Use source bin/console (not PHAR) so APP_ENV=test loads
        // config/services_test.yaml with ControllerReplayHttpClientFactory
        // for deterministic replay-based model interaction.
        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';

        $startupFixture = __DIR__.'/fixtures/tui-startup-prompt-response.json';
        $fixtureEnv = \is_file($startupFixture)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.\escapeshellarg($startupFixture).' '
            : '';

        $dbPath = 'app_test-tui-snapshot-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s %s %s agent --model=llama_cpp_test/test --prompt="hello from tmux e2e" --tools-excluded=bash 2>&1',
            \escapeshellarg($dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e', 0o777);
        @\mkdir($dir.'/.hatfield', 0o777, true);
        @\mkdir($dir.'/home/.hatfield', 0o777, true);

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
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function shouldUpdateSnapshots(): bool
    {
        return in_array(getenv('HATFIELD_UPDATE_SNAPSHOTS'), ['1', 'true', 'yes'], true);
    }

    private function diffHint(string $expected, string $actual): string
    {
        $expectedLines = explode("\n", $expected);
        $actualLines = explode("\n", $actual);
        $maxLen = max(count($expectedLines), count($actualLines));

        $diff = [];
        for ($i = 0; $i < $maxLen; $i++) {
            $exp = $expectedLines[$i] ?? '<<< missing >>>';
            $act = $actualLines[$i] ?? '<<< missing >>>';
            if ($exp !== $act) {
                $diff[] = sprintf(
                    '  line %3d: -"%s"',
                    $i + 1,
                    substr($exp, 0, 100),
                );
                $diff[] = sprintf(
                    '           +"%s"',
                    substr($act, 0, 100),
                );
            }
        }

        return implode("\n", $diff);
    }
}
