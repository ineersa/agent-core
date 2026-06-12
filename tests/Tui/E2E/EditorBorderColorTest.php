<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test for editor border colour following reasoning level.
 *
 * Verifies that pressing Shift+Tab to cycle the reasoning level changes
 * the ANSI colour of the editor widget's frame.
 *
 * Proof strategy:
 *  1. Start the TUI with a model that supports thinking levels.
 *  2. Wait for the TUI to boot (verify border chars visible).
 *  3. Send Shift+Tab to cycle reasoning from 'off' → 'minimal'.
 *  4. Wait for the status panel to confirm 'minimal'.
 *  5. Assert the ANSI capture contains smoke-coloured border runs.
 *  6. Send second Shift+Tab: minimal → low.
 *  7. Wait for the status panel to confirm 'low'.
 *  8. Assert the ANSI capture contains electric-coloured border runs.
 *
 * Coloured border runs are identified as \e[38;2;R;G;Bm followed by
 * 10+ BOX DRAWINGS LIGHT HORIZONTAL characters (U+2500 ─).  These
 * uniquely identify the editor frame rendered by EditorRenderer.
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
final class EditorBorderColorTest extends TestCase
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
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
        if (isset($this->testProjectDir)) {
            TestDirectoryIsolation::removeDirectory($this->testProjectDir);
        }
    }

    /**
     * @test
     *
     * Shift+Tab cycles reasoning and the editor border ANSI colour changes.
     */
    public function testEditorBorderColorChangesWithReasoningLevel(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-border-color',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for agent boot (logo █ visible).
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // ── Verify border characters are present ──
        $initialPlain = $this->tmux->capturePlainWithHistory($pane, 200);
        self::assertStringContainsString(
            '─',
            $initialPlain,
            'Editor border ─ chars should be visible in the initial capture',
        );

        // Save initial ANSI snapshot for manual inspection.
        $this->saveAnsiSnapshot($pane, 'border-off');

        // Capture initial state.
        $offAnsi = $this->tmux->captureAnsi($pane);
        self::assertNotEmpty($offAnsi, 'Initial ANSI capture should not be empty');

        // ── Send Shift+Tab to cycle reasoning from 'off' → 'minimal' ──
        $this->tmux->sendLiteral($pane, "\x1b[Z");

        // Wait for the status panel to confirm the reasoning changed.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture): bool {
                return str_contains($capture, 'minimal');
            },
            timeout: 5.0,
            message: 'Reasoning level "minimal" did not appear in the status panel after Shift+Tab',
            history: 500,
        );

        // Capture ANSI after first reasoning change.
        $minimalAnsi = $this->tmux->captureAnsi($pane);
        self::assertNotEmpty($minimalAnsi, 'ANSI capture after Shift+Tab to minimal should not be empty');

        $this->saveAnsiSnapshot($pane, 'border-minimal');

        // Assert the editor border colour changed from off (steel=74;85;104)
        // to minimal (smoke=113;128;150).  The editor frame is rendered by
        // EditorRenderer applying a colour Style to full-width ─ rows.
        // Search for the smoke-coloured border run.
        // \x{2500} is U+2500 BOX DRAWINGS LIGHT HORIZONTAL (─).
        self::assertMatchesRegularExpression(
            '/\x1b\[38;2;113;128;150m\x{2500}{10,}/u',
            $minimalAnsi,
            'Editor border should show minimal/smoke colour after first Shift+Tab',
        );

        // ── Send second Shift+Tab: minimal → low ──
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

        // Capture ANSI after second reasoning change.
        $lowAnsi = $this->tmux->captureAnsi($pane);
        self::assertNotEmpty($lowAnsi, 'ANSI capture after Shift+Tab to low should not be empty');

        $this->saveAnsiSnapshot($pane, 'border-low');

        // Assert the editor border colour changed from minimal (smoke=113;128;150)
        // to low (electric=0;255;255).  Search for electric-coloured border runs.
        self::assertMatchesRegularExpression(
            '/\x1b\[38;2;0;255;255m\x{2500}{10,}/u',
            $lowAnsi,
            'Editor border should show low/electric colour after second Shift+Tab',
        );

        // Send Ctrl+D to exit cleanly
        $this->tmux->sendKey($pane, 'C-d');
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Save an ANSI snapshot of the current pane content.
     *
     * Snapshots are written to the test's isolated var/tmp directory
     * and can be inspected with `less -R <path>` for colour verification.
     */
    private function saveAnsiSnapshot(TmuxPane $pane, string $label): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $dir = \sprintf(
            '%s/var/tmp/tui-e2e-border-snap-%s',
            $this->projectRoot,
            \bin2hex(\random_bytes(4)),
        );
        @\mkdir($dir, 0o777, true);
        \file_put_contents("{$dir}/{$label}.ansi", $ansi);

        // Count the snapshot save as an assertion for reporting.
        $this->addToAssertionCount(1);
    }

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
     * Create an isolated project directory with the oh-p-dark theme.
     *
     * The llama_cpp_test/test model supports thinking levels so
     * Shift+Tab cycles through off/minimal/low/medium/high/xhigh.
     */
    private function createIsolatedProjectDir(): string
    {
        $dir = \sprintf(
            '%s/var/tmp/tui-e2e-border-%s',
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
