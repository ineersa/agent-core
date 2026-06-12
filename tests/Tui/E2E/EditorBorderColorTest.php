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

        $initialPlain = $this->tmux->capturePlainWithHistory($pane, 200);
        self::assertStringContainsString(
            '─',
            $initialPlain,
            'Editor border ─ chars should be visible in the initial capture',
        );

        $offBorderColour = $this->editorBorderColour($pane);

        // Shift+Tab: off → minimal
        $this->tmux->sendLiteral($pane, "\x1b[Z");

        $this->tmux->waitForCallback(
            $pane,
            static fn (string $c): bool => str_contains($c, 'minimal'),
            timeout: 5.0,
            message: 'Reasoning "minimal" did not appear',
            history: 500,
        );

        $minimalBorderColour = $this->editorBorderColour($pane);
        self::assertNotNull($minimalBorderColour, 'minimal border colour');

        self::assertNotSame(
            $offBorderColour,
            $minimalBorderColour,
            'Border colour should change from off → minimal',
        );

        // Shift+Tab: minimal → low
        $this->tmux->sendLiteral($pane, "\x1b[Z");

        $this->tmux->waitForCallback(
            $pane,
            static fn (string $c): bool => str_contains($c, 'low'),
            timeout: 5.0,
            message: 'Reasoning "low" did not appear',
            history: 500,
        );

        $lowBorderColour = $this->editorBorderColour($pane);
        self::assertNotNull($lowBorderColour, 'low border colour');

        self::assertNotSame(
            $minimalBorderColour,
            $lowBorderColour,
            'Border colour should change from minimal → low',
        );

        $this->tmux->sendKey($pane, 'C-d');
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Capture the pane and extract the ANSI true-colour sequence from the
     * first pure-dash editor frame border line that is NOT a fixed steel
     * separator (header / footer separators always use 38;2;74;85;104).
     *
     * Editor frame borders are full-width ─ (U+2500) rows with no other
     * glyphs.  If all pure-dash lines are steel separators (or uncoloured),
     * returns null.
     *
     * Returns e.g. "38;2;113;128;150" or null.
     */
    private function editorBorderColour(TmuxPane $pane): ?string
    {
        $ansi = $this->tmux->captureAnsi($pane);

        foreach (explode("\n", $ansi) as $line) {
            if (\mb_substr_count($line, "\u{2500}") < 40) {
                continue;
            }

            $plain = \preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            $plain = \trim($plain);

            // Only pure-dash lines (no logo/text/status mixed in).
            if (\preg_match('/[^─ \t]/u', $plain)) {
                continue;
            }

            // Extract colour.
            if (\preg_match('/\x1b\[38;2;(\d+);(\d+);(\d+)m/', $line, $m)) {
                $colour = \vsprintf('38;2;%s;%s;%s', [$m[1], $m[2], $m[3]]);
                // Skip fixed steel separators (header / footer).
                if ('38;2;74;85;104' !== $colour) {
                    return $colour;
                }
                continue;
            }
            if (\preg_match('/\x1b\[38;5;(\d+)m/', $line, $m)) {
                return \sprintf('38;5;%s', $m[1]);
            }

            // Pure-dash line with no colour — editor border in default.
            return 'default';
        }

        return null;
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
