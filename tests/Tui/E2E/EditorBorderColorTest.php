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
 *  2. Wait for the TUI to boot.
 *  3. Capture ANSI and extract the editor border colour after boot (off).
 *  4. Send Shift+Tab to cycle reasoning from 'off' → 'minimal'.
 *  5. Wait for the status panel to confirm 'minimal'.
 *  6. Capture ANSI, extract border colour, assert it differs from 'off'.
 *  7. Send second Shift+Tab: minimal → low.
 *  8. Capture ANSI, extract border colour, assert it differs from 'minimal'.
 *
 * The oh-p-dark theme maps reasoning to hex colours:
 *   off     → gray1  (#3e4452)
 *   minimal → gray2  (#5c6370)
 *   low     → blue   (#5b9bf5)
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

        // Collect all border-line colours after boot (reasoning = off).
        // The editor frame border ─-rows are full-width; collecting colours
        // from those rows creates a fingerprint that changes when the
        // reasoning-level colour is applied to the editor frame.
        $offAnsi = $this->tmux->captureAnsi($pane);
        $offBorderColors = $this->extractBorderColors($offAnsi);
        self::assertNotEmpty(
            $offBorderColors,
            'Could not extract any border-line ANSI colours from initial capture',
        );

        $this->saveAnsiSnapshot($pane, 'border-off');

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
        $minimalBorderColors = $this->extractBorderColors($minimalAnsi);
        self::assertNotEmpty(
            $minimalBorderColors,
            'Could not extract border-line ANSI colours after Shift+Tab to minimal',
        );

        $this->saveAnsiSnapshot($pane, 'border-minimal');

        // Assert the border-line colour fingerprint differs from 'off'.
        self::assertNotSame(
            $offBorderColors,
            $minimalBorderColors,
            'Editor border colours should change when reasoning cycles from off → minimal',
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
        $lowBorderColors = $this->extractBorderColors($lowAnsi);
        self::assertNotEmpty(
            $lowBorderColors,
            'Could not extract border-line ANSI colours after Shift+Tab to low',
        );

        $this->saveAnsiSnapshot($pane, 'border-low');

        // Assert the border-line colour fingerprint differs from 'minimal'.
        self::assertNotSame(
            $minimalBorderColors,
            $lowBorderColors,
            'Editor border colours should change when reasoning cycles from minimal → low',
        );

        // Send Ctrl+D to exit cleanly
        $this->tmux->sendKey($pane, 'C-d');
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Collect unique ANSI true-colour sequences from full-width border
     * rows (lines containing many ─ characters).
     *
     * The editor frame borders are full-width ─-rows at the bottom of
     * the pane; collecting colours from ALL such rows creates a
     * fingerprint that changes when the reasoning-level colour is
     * applied to the editor frame (even when other separators use
     * constant colours).
     *
     * Returns a sorted list of unique colour strings like
     * ["38;2;62;68;82", "38;2;92;99;112"].
     * Returns an empty list if no qualifying border line is found.
     *
     * @return list<string>
     */
    private function extractBorderColors(string $ansi): array
    {
        $lines = explode("\n", $ansi);
        $colors = [];
        $dashCounts = [];

        // First pass: count ─ chars per line to find the widest border rows.
        foreach ($lines as $idx => $line) {
            $dashCounts[$idx] = mb_substr_count($line, '─');
        }

        // Heuristic: full-width border rows have significantly more ─
        // chars than incidental dashes in logo lines or status text.
        // Use ≥ half the max dash count as the threshold (e.g. 60 of 120).
        $maxDashes = !empty($dashCounts) ? max($dashCounts) : 0;
        $threshold = (int) max(floor($maxDashes * 0.5), 1);

        foreach ($lines as $idx => $line) {
            if ($dashCounts[$idx] < $threshold) {
                continue;
            }

            // Extract ANSI true-colour SGR: \e[38;2;R;G;Bm
            if (preg_match('/\e\[(38;2;\d+;\d+;\d+)m/', $line, $matches)) {
                $colors[$matches[1]] = true;
                continue;
            }

            // Also match 256-colour: \e[38;5;Nm
            if (preg_match('/\e\[(38;5;\d+)m/', $line, $matches)) {
                $colors[$matches[1]] = true;
            }
        }

        $result = array_keys($colors);
        sort($result);

        return $result;
    }

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
