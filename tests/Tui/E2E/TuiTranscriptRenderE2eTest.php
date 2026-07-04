<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal TmuxHarness proof: transcript blocks render through the Symfony TUI
 * widget-tree renderer in a real terminal.
 *
 * Launches the TUI with a replay fixture that responds to "hello" with
 * "Follow-up acknowledged." — no thinking blocks, no tool calls, just
 * a clean assistant text response. Asserts the assistant glyph (◇) and
 * response text appear in the terminal output, proving the widget-tree
 * renderer path works end-to-end through ChatScreen → TranscriptBlockWidget
 * → SymfonyTuiWidgetRenderer in a real TUI process.
 *
 * No live LLM. One prompt → one assertion → clean exit.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiTranscriptRenderE2eTest extends TestCase
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
        if (isset($this->testProjectDir)) {
            TestDirectoryIsolation::removeDirectory($this->testProjectDir);
        }
    }

    /**
     * Launch TUI, submit a prompt, assert the assistant response renders
     * through the Symfony widget-tree renderer, then cleanly exit.
     */
    public function testAssistantBlockRendersThroughWidgetTree(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-transcript-render',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup (logo, idle status, footer)
            $this->tmux->waitForCaptureContains(
                $pane,
                '█',
                TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL,
            );

            $this->tmux->waitForTuiReadyAfterLogo($pane);

            // Submit a prompt matching the replay fixture
            $this->tmux->sendKey($pane, 'C-u');
            \usleep(100_000);
            $this->tmux->sendLiteral($pane, 'hello');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the assistant block glyph — proves widget-tree renderer path
            $capture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => \str_contains($cap, '◇'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Assistant block (◇) never appeared — widget-tree renderer may not be rendering transcript blocks',
                history: 2000,
            );

            // Assert the fixture response text is visible
            self::assertStringContainsString(
                'Follow-up acknowledged.',
                $capture,
                'Replay fixture response text missing from transcript output',
            );

            // Save ANSI snapshot for inspection
            $this->saveAnsiSnapshot($pane, 'transcript-render-assistant');

            // Clean exit
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'transcript-render-FAILURE');
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
        $fixturePath = __DIR__.'/fixtures/tui-followup-response.json';
        if (!\is_file($fixturePath)) {
            $this->fail("Replay fixture not found: {$fixturePath}");
        }

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-transcript-render-');

        $dbPath = $paths['app'];

        $transportDbPath = $paths['transport'];

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($fixturePath),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-transcript-render');
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
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = \date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }
}
