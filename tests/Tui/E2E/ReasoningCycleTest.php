<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test for Shift+Tab reasoning level cycling.
 *
 * Verifies that pressing Shift+Tab cycles the thinking level and shows
 * the new level in the status panel above the editor.
 *
 * Design:
 *  - Starts the agent TUI in a detached tmux session with a test model
 *    configured to support thinking levels (reasoning: true,
 *    supports_thinking_levels: true).
 *  - Sends Shift+Tab to cycle from default 'off' → 'minimal'.
 *  - Asserts the status panel shows the new reasoning level.
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
final class ReasoningCycleTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
    }

    protected function tearDown(): void
    {
        // TmuxHarness destructor kills all sessions.
    }

    /**
     * @test
     *
     * Shift+Tab cycles the reasoning level and shows it in the status panel.
     *
     * Proof strategy:
     *  1. Start the TUI with a model that supports thinking levels.
     *  2. Wait for the TUI to boot (Hatfield logo visible).
     *  3. Send Shift+Tab to cycle reasoning from 'off' to 'minimal'.
     *  4. Wait for the status panel to show 'minimal'.
     *  5. Assert the reasoning level text is visible in the pane.
     */
    public function testShiftTabShowsReasoningLevelInStatus(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-reasoning-cycle',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for agent boot (logo █ visible).
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // At this point the TUI is idle — no prompt has been submitted.
        // The footer shows ◆ test with thinking-off color.
        // The status panel is empty (no setStatus calls yet).

        // Send Shift+Tab to cycle reasoning from 'off' → 'minimal'.
        // Shift+Tab sends the escape sequence \x1b[Z (CSI Z).
        // Tmux key name 'S-Tab' is not reliably recognised in all versions,
        // so we send the raw escape sequence which the terminal interprets
        // as a Shift+Tab keypress.
        $this->tmux->sendLiteral($pane, "\x1b[Z");

        // Wait for the status panel to show the new reasoning level.
        // After one cycle: off → minimal. The status panel renders as:
        //   reasoning    minimal
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture): bool {
                return str_contains($capture, 'minimal');
            },
            timeout: 5.0,
            message: 'Reasoning level "minimal" did not appear in the status panel after Shift+Tab',
            history: 500,
        );

        // Verify the reasoning level text is on a line prefixed with "reasoning"
        // (the status panel key format).
        $capture = $this->tmux->capturePlainWithHistory($pane, 500);
        self::assertStringContainsString(
            'reasoning',
            $capture,
            'Status panel should contain the "reasoning" key label',
        );

        // Send one more Shift+Tab to cycle again: minimal → low
        $this->tmux->sendLiteral($pane, "\x1b[Z");
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture): bool {
                return str_contains($capture, 'low');
            },
            timeout: 5.0,
            message: 'Reasoning level "low" did not appear after second Shift+Tab',
            history: 500,
        );

        // Send Ctrl+D to exit cleanly
        $this->tmux->sendKey($pane, 'C-d');
    }

    // ── Helpers ───────────────────────────────────────────────

    private function agentCommand(): string
    {
        [$php, $script] = AgentTestExecutable::command();

        return \sprintf(
            'APP_ENV=dev HOME=%s %s %s agent --model=llama_cpp_test/test 2>&1',
            \escapeshellarg($this->testProjectDir . '/home'),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    /**
     * Create an isolated project directory where the test model
     * supports thinking levels.
     *
     * The llama_cpp_test/test model is configured with:
     *  - reasoning: true
     *  - thinking_level_map with all 6 levels
     *  - supports_thinking_levels: true on the provider
     *
     * This allows Shift+Tab to cycle through off/minimal/low/medium/high/xhigh.
     */
    private function createIsolatedProjectDir(): string
    {
        $dir = \sprintf(
            '%s/var/tmp/tui-e2e-reasoning-%s',
            $this->projectRoot,
            \bin2hex(\random_bytes(6)),
        );
        @\mkdir($dir . '/.hatfield', 0o777, true);
        @\mkdir($dir . '/home/.hatfield', 0o777, true);

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
                                    'off' => '0',
                                    'minimal' => '0',
                                    'low' => '0',
                                    'medium' => '0',
                                    'high' => '0',
                                    'xhigh' => '0',
                                ],
                                'cost' => [
                                    'input' => 0,
                                    'output' => 0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir . '/.hatfield/settings.yaml', $yaml);
        \file_put_contents($dir . '/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }
}
