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
 * Shift+Tab cycles reasoning; the editor frame border (full-width ─ rows)
 * should change colour.  This test does NOT submit prompts or wait for LLM
 * responses, so the terminal layout is completely deterministic.
 *
 * Layout at 120×40 (verified via ANSI snapshots):
 *
 *   Lines 1-6   Logo (electric 0;255;255)
 *   Line  7     Header separator (steel 74;85;104) ─
 *   Line  8     Welcome message
 *   Line  9     Status (● idle)
 *   Line 10     Editor TOP BORDER (reasoning color) ─
 *   Line 11-14  Editor area (empty / prompt text)
 *   Line 15     Editor BOTTOM BORDER (reasoning color) ─
 *   Line 16     Footer separator (steel) ─
 *   Line 17     Footer bar (◆)
 *
 * The footer bar line (contains ◆) is the anchor.  Editor bottom border
 * is exactly 2 lines above it (skip the steel footer separator).
 * Both borders are full-width ─ (U+2500) rows ≥ 100 characters wide.
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

        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // ── off (default) ────────────────────────────────────────────

        $off = $this->editorBorderColour($pane);
        self::assertNotNull($off, 'Border colour at reasoning=off should not be null');
        self::assertNotEmpty($off, 'Border colour at reasoning=off should not be empty');

        // ── Shift+Tab: off → minimal ─────────────────────────────────

        $this->tmux->sendLiteral($pane, "\x1b[Z");

        // Wait for the editor border colour itself to change, not just
        // the status text.  The status panel may update before the TUI
        // repaints the editor frame; polling ANSI avoids the race.
        $minimal = $this->waitForBorderColorChange($pane, $off, 5.0);
        self::assertNotNull($minimal, 'Border colour at reasoning=minimal should not be null');
        self::assertNotSame(
            $off,
            $minimal,
            \sprintf(
                'Border colour should change off(%s) → minimal(%s)',
                $off,
                $minimal,
            ),
        );

        // ── Shift+Tab: minimal → low ─────────────────────────────────

        $this->tmux->sendLiteral($pane, "\x1b[Z");

        $low = $this->waitForBorderColorChange($pane, $minimal, 5.0);
        self::assertNotNull($low, 'Border colour at reasoning=low should not be null');
        self::assertNotSame(
            $minimal,
            $low,
            \sprintf(
                'Border colour should change minimal(%s) → low(%s)',
                $minimal,
                $low,
            ),
        );
        $this->tmux->sendKey($pane, 'C-d');
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Wait until the editor border colour differs from a known previous
     * colour, polling ANSI captures at 100ms intervals.
     *
     * The TUI status panel updates before the editor frame is repainted,
     * so waiting for the reasoning status text alone races with the
     * stylesheet → invalidate → requestRender → tmux paint cycle.
     * Polling the ANSI border colour itself eliminates that race.
     *
     * Returns the new colour string, or null on timeout.
     */
    private function waitForBorderColorChange(TmuxPane $pane, string $previous, float $timeout = 5.0): ?string
    {
        $deadline = \microtime(true) + $timeout;

        while (\microtime(true) < $deadline) {
            $colour = $this->editorBorderColour($pane);
            if (null !== $colour && $colour !== $previous) {
                return $colour;
            }
            \usleep(100_000); // 100ms
        }

        // One last attempt; return the current colour (for assertions).
        return $this->editorBorderColour($pane);
    }

    /**
     * Extract the ANSI colour of the editor BOTTOM border.
     *
     * Strategy: find the footer bar anchor (unique ◆ character), count
     * up 2 lines to the editor bottom border (skipping the steel footer
     * separator).  The editor bottom border is always a full-width ─ row
     * immediately above the steel footer separator.
     *
     * This anchor is deterministic regardless of whether the status
     * panel shows "reasoning" text (it was removed from startup seeding
     * in issue #117, only appearing after the first Shift+Tab).
     *
     * Returns the colour portion of the ANSI SGR sequence, e.g.
     * "38;2;113;128;150" for smoke, "38;2;0;255;255" for electric,
     * or "default" if no colour SGR is found.
     *
     * Returns null if the anchor row cannot be located.
     */
    private function editorBorderColour(TmuxPane $pane): ?string
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $lines = explode("\n", $ansi);

        // Find the footer bar anchor: the last line containing ◆.
        // Search backward so trailing blank lines don't shift the index.
        $footerIdx = null;
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            if (str_contains($lines[$i], '◆')) {
                $footerIdx = $i;

                break;
            }
        }

        if (null === $footerIdx) {
            return null;
        }

        // Editor bottom border is 2 lines above the footer bar:
        //   footerIdx - 0  = footer bar (◆ test | 0/0 ...)
        //   footerIdx - 1  = footer separator (steel ─)
        //   footerIdx - 2  = editor BOTTOM border (reasoning colour ─)
        $borderIdx = $footerIdx - 2;
        if ($borderIdx < 0 || !isset($lines[$borderIdx])) {
            return null;
        }

        $borderLine = $lines[$borderIdx];

        // Ensure it's actually a dash row (belt-and-suspenders).
        if (\mb_substr_count($borderLine, "\u{2500}") < 100) {
            return null;
        }

        // Extract the first ANSI true-colour SGR from the line.
        if (\preg_match('/\x1b\[38;2;(\d+);(\d+);(\d+)m/', $borderLine, $m)) {
            return \vsprintf('38;2;%s;%s;%s', [$m[1], $m[2], $m[3]]);
        }

        // 256-colour fallback.
        if (\preg_match('/\x1b\[38;5;(\d+)m/', $borderLine, $m)) {
            return \sprintf('38;5;%s', $m[1]);
        }

        return 'default';
    }

    // ── Test infrastructure ─────────────────────────────────────

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
     * Create an isolated project directory with a model that supports
     * thinking levels so Shift+Tab cycles off/minimal/low/medium/high/xhigh.
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
