<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal TmuxHarness proof that the real TUI boot path accepts the new
 * tui.transcript settings and starts up without error.
 *
 * Creates an isolated project directory with .hatfield/settings.yaml
 * containing the new tui.transcript settings (thinking.visible=false,
 * previews.expanded_by_default=true) and launches the TUI via
 * bin/console agent. Asserts the visible startup path reaches the
 * real TUI (logo, status, footer) and exits cleanly with Ctrl+D.
 *
 * No live LLM, no broad journey phases — just the TUI startup path
 * proof that new config keys are accepted at boot.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiStartupTranscriptConfigE2eTest extends TestCase
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
     * Launch the TUI with tui.transcript settings, assert startup is reached
     * (logo, idle status, footer), then exit cleanly with Ctrl+D.
     */
    public function testTranscriptConfigSettingsAcceptedAtBoot(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-transcript-config',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for Hatfield logo (proves TUI booted)
            $this->tmux->waitForCaptureContains(
                pane: $pane,
                needle: '█',
                timeout: TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL,
            );

            // Wait for full startup (idle status + footer)
            $capture = $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->assertStringContainsString('█', $capture, 'Hatfield logo missing');
            $this->assertTrue(
                str_contains($capture, '● idle') || str_contains($capture, '◐ Work'),
                'Working/idle status widget missing',
            );
            $this->assertStringContainsString('◆', $capture, 'Footer widget missing');

            // Save snapshot on success for inspection
            $this->saveAnsiSnapshot($pane, 'transcript-config-startup');

            // Clean exit
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'transcript-config-FAILURE');
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
        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';

        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-transcript-config-');

        $dbPath = $paths['app'];

        $transportDbPath = $paths['transport'];

        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e');
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);

        // Include the new tui.transcript settings alongside minimal AI config.
        $settings = [
            'tui' => [
                'theme' => 'cyberpunk',
                'transcript' => [
                    'thinking' => [
                        'visible' => false,
                        'style' => 'dim',
                    ],
                    'previews' => [
                        'expanded_by_default' => true,
                        'tool_result_lines' => 3,
                        'diff_lines' => 5,
                    ],
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

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @mkdir($snapshotDir, 0o777, true);
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $snapshotDir, $tag, $ts);
        file_put_contents($path, $ansi);
    }
}
