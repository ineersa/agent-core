<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Prove that LLM cost computed from model pricing flows through
 * the full runtime pipeline and appears as non-$0.00 in the TUI
 * footer after a turn completes.
 *
 * The test uses a deliberately high per-token pricing for
 * llama_cpp_test/test so even a tiny turn (18 prompt + 5 output
 * tokens) produces a visible non-zero cost in the footer.
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
final class CostFooterE2ETest extends TestCase
{
    private TmuxHarness $tmux;
    private string $snapshotDir;
    private string $projectRoot;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
        $this->testProjectDir = $this->createPricedProjectDir();
        $this->snapshotDir = $this->testProjectDir . '/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
        // Snapshots are kept under var/tmp/tui-e2e-*/ for inspection.
        // Run `castor cleanup` to remove all temp/test artifacts.
    }

    /**
     * Submit a prompt and assert the footer cost is not $0.00
     * after the assistant response appears.
     *
     * Uses high pricing (input=1000.0, output=100000.0 per 1M tokens)
     * so any successful generation produces a visible non-zero cost.
     */
    public function testFooterCostIsNonZeroAfterTurn(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-cost-e2e',
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Step 1: Wait for TUI to render.
            $this->tmux->waitForCaptureContains(
                pane: $pane,
                needle: '█',   // Hatfield logo
                timeout: 5.0,
            );

            // Step 2: Type and submit a prompt.
            $prompt = 'Respond with exactly one word: hello.';
            $this->tmux->sendLiteral($pane, $prompt);
            $this->tmux->sendKey($pane, 'Enter');

            // Step 3: Wait for the assistant response block (◇) or
            // error block (✕) to appear.
            $this->tmux->waitForCallback(
                $pane,
                static function (string $capture): bool {
                    return \str_contains($capture, '◇') || \str_contains($capture, '✕');
                },
                timeout: 20.0,
                message: 'Assistant or error block did not appear after prompt submission',
                history: 500,
            );

            // Step 4: Assert footer cost is NOT $0.00.
            $capture = $this->tmux->capturePlain($pane);
            self::assertStringNotContainsString(
                '$0.00',
                $capture,
                'Footer cost must NOT be $0.00 after a turn '
                . 'with highly-priced test model configured',
            );

            // Save ANSI snapshot for manual inspection.
            $this->saveAnsiSnapshot($pane, 'cost-non-zero');
        } finally {
            // Exit cleanly.
            $this->tmux->sendKey($pane, 'C-d');
        }
    }

    // ── Helpers ───────────────────────────────────────────────

    private function agentCommand(): string
    {
        [$php, $script] = AgentTestExecutable::command();

        return \sprintf(
            'APP_ENV=dev HOME=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            \escapeshellarg($this->testProjectDir . '/home'),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    /**
     * Create an isolated project directory with high pricing for
     * llama_cpp_test/test so any successful turn produces a visible
     * non-zero cost in the footer.
     */
    private function createPricedProjectDir(): string
    {
        $dir = \sprintf(
            '%s/var/tmp/tui-e2e-%s',
            $this->projectRoot,
            \bin2hex(\random_bytes(6)),
        );
        @\mkdir($dir . '/.hatfield', 0o777, true);
        @\mkdir($dir . '/home/.hatfield', 0o777, true);

        // Intentional high pricing — input $1000/M, output $100000/M.
        // A tiny turn (~18 prompt + ~5 output tokens) yields ~$0.52.
        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
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
                                'cost' => [
                                    'input' => 1000.0,
                                    'output' => 100000.0,
                                ],
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
        \file_put_contents($dir . '/.hatfield/settings.yaml', $yaml);
        \file_put_contents($dir . '/home/.hatfield/settings.yaml', $yaml);

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
