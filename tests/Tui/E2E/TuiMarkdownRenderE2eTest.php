<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Minimal TmuxHarness proof for RENDER-03: markdown rendering and
 * hidden thinking in a real terminal.
 *
 * Launches the TUI with `thinking.visible=false` and a replay fixture
 * that produces a thinking delta followed by a markdown text delta.
 * Asserts:
 * - Thinking content is hidden and only the ⋯ placeholder appears
 * - Markdown in the assistant message is rendered (no raw **delimiters**)
 * - Standard glyphs (❯, ◇, ⋯) are present
 *
 * No live LLM. One prompt → focused assertions → clean exit.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiMarkdownRenderE2eTest extends TestCase
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
     * Launch TUI with hidden thinking and markdown-laden assistant response.
     * Assert thinking content is hidden, markdown delimiters are consumed,
     * and standard glyphs appear.
     */
    public function testThinkingHiddenAndMarkdownRendered(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-markdown-render',
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

            // Wait for the assistant block glyph — proves widget-tree path
            $capture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => \str_contains($cap, '◇'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Assistant block (◇) never appeared — widget-tree renderer may not be rendering transcript blocks',
                history: 2000,
            );

            // 1) Raw thinking content must NOT be visible (hidden by config)
            self::assertStringNotContainsString(
                'I need to respond with a friendly markdown message.',
                $capture,
                'Thinking content leaked despite thinking.visible=false in settings',
            );

            // 2) Markdown **bold** delimiters must NOT appear literally
            self::assertStringNotContainsString(
                '**bold**',
                $capture,
                'Markdown bold delimiters leaked through — MarkdownWidget not rendering',
            );

            // 3) Markdown `code` backtick delimiters must NOT appear literally
            self::assertStringNotContainsString(
                '`code`',
                $capture,
                'Markdown code backticks leaked through — MarkdownWidget not rendering',
            );

            // 4) The assistant response text (rendered) must appear
            self::assertStringContainsString(
                'Hello!',
                $capture,
                'Assistant response text not found',
            );

            // 5) The thinking placeholder glyph must appear
            self::assertStringContainsString(
                '⋯',
                $capture,
                'Thinking placeholder glyph (⋯) not found for hidden thinking block',
            );

            // 6) The user message glyph must appear
            self::assertStringContainsString(
                '❯',
                $capture,
                'User message glyph (❯) not found',
            );

            // Save ANSI snapshot for inspection
            $this->saveAnsiSnapshot($pane, 'markdown-thinking-hidden');

            // Clean exit
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'markdown-thinking-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
                // Best-effort cleanup during failure path — explicitly intentional per AGENTS.md caught-exception policy.
                // This catch prevents a secondary exception from masking the original test failure.
            }
            throw $e;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-markdown-thinking-response.json';
        if (!\is_file($fixturePath)) {
            $this->fail("Replay fixture not found: {$fixturePath}");
        }

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $dbPath = 'app_test-tui-markdown-render-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            \escapeshellarg($dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($fixturePath),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-markdown-render');
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
            'tui' => [
                'transcript' => [
                    'thinking' => [
                        'visible' => false,
                    ],
                ],
            ],
        ];

        $yaml = Yaml::dump($settings, 6, 4);
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
