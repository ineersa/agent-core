<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Transcript\TranscriptGlyphs;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mandatory TmuxHarness product validation for rich transcript rendering:
 * assistant markdown, hidden thinking placeholder, tool exchange (edit diff + YAML args),
 * stable glyphs, and footer chrome. Replay-backed, no live LLM.
 *
 * Ctrl+O preview expansion is proven primarily at the virtual/input layer in
 * {@see \Ineersa\Tui\Tests\Listener\PreviewExpansionInputListenerTest} and also
 * smoke-asserted here in tmux for end-to-end product validation.
 */
#[Group('tui-e2e-replay')]
final class TuiRichTranscriptProductValidationE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private ?string $comparisonDir = null;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->tmux->setSnapshotDir($this->testProjectDir);
        $this->comparisonDir = $this->projectRoot.'/var/tmp/tui-visual-readability/after';
        @mkdir($this->comparisonDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
        // Keep ANSI smoke snapshots for before/after comparison; only remove the
        // rest of the isolated project tree after copying artifacts out.
        if (isset($this->testProjectDir) && is_dir($this->testProjectDir)) {
            $smokeDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
            if (null !== $this->comparisonDir && is_dir($smokeDir)) {
                foreach (glob($smokeDir.'/*.ansi') ?: [] as $ansi) {
                    $target = $this->comparisonDir.'/'.basename($ansi);
                    @copy($ansi, $target);
                }
            }
            TestDirectoryIsolation::removeDirectory($this->testProjectDir);
        }
    }

    public function testRichTranscriptPathMarkdownAndEditToolExchange(): void
    {
        file_put_contents($this->testProjectDir.'/target.txt', "before\n");

        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-rich-product',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains(
                $pane,
                '█',
                TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL,
            );
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, 'hello');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Assistant block never appeared after markdown fixture prompt',
                history: 2000,
            );

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, 'Edit target.txt');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '-before')
                    || str_contains($cap, 'Applied patch'),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Edit tool diff preview never appeared in transcript',
                history: 3000,
            );

            // Expand collapsed diff preview in the real terminal (Ctrl+O).
            $this->tmux->sendKey($pane, 'C-o');
            $fullCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '+after'),
                timeout: 5.0,
                message: 'Ctrl+O did not expand edit diff preview (+after)',
                history: 3000,
            );

            $this->assertStringContainsString(trim(TranscriptGlyphs::GLYPH_USER_MESSAGE), $fullCapture, 'User glyph missing from rich transcript path');
            $this->assertStringContainsString(trim(TranscriptGlyphs::GLYPH_ASSISTANT_MESSAGE), $fullCapture, 'Assistant glyph missing');
            $this->assertStringNotContainsString(
                'I need to respond with a friendly markdown message.',
                $fullCapture,
                'Thinking content leaked with thinking.visible=false',
            );
            $this->assertStringNotContainsString('**bold**', $fullCapture, 'Raw markdown bold leaked');
            $this->assertStringContainsString('bold', $fullCapture, 'Markdown body should render without delimiters');
            $this->assertStringContainsString('path:', $fullCapture, 'Tool argument key should render');
            $this->assertStringContainsString('target.txt', $fullCapture);
            $this->assertStringContainsString('+after', $fullCapture, 'Ctrl+O should expand edit diff preview in tmux');
            $this->assertStringNotContainsString('patch: |', $fullCapture);
            $this->assertStringNotContainsString('```', $fullCapture);
            $this->assertStringContainsString('session ', $fullCapture, 'Footer session chrome expected');

            $ansiHistory = $this->tmux->captureAnsiWithHistory($pane, 3000);
            // Default replay theme is cyberpunk: tool title electric (#00ffff), argument key neon (#ff00ff).
            $this->assertStringContainsString(
                "\033[38;2;0;255;255m",
                $ansiHistory,
                'Cyberpunk tool title (electric) ANSI missing around tool exchange',
            );
            $this->assertStringContainsString(
                "\033[38;2;255;0;255m",
                $ansiHistory,
                'Cyberpunk tool argument key (neon) ANSI missing around path label',
            );
            $this->assertMatchesRegularExpression(
                '/\x1b\[38;2;255;0;255mpath\x1b\[39m:/',
                $ansiHistory,
                'Argument key path must use neon, distinct from tool title electric',
            );

            $this->assertMatchesRegularExpression(
                '/hello\n\s*\n.*◇/s',
                $fullCapture,
                'User prompt and assistant block should be separated by a blank transcript row',
            );
            $this->assertMatchesRegularExpression(
                '/◇.*\n\s*\n.*●/s',
                $fullCapture,
                'Assistant block and tool exchange should be separated by a blank transcript row',
            );

            $this->tmux->saveAnsiSnapshot($pane, 'rich-transcript-product-validation');
            $this->persistComparisonArtifacts($pane, 'rich-transcript-product-validation');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'rich-transcript-product-validation-FAILURE');
            $this->persistComparisonArtifacts($pane, 'rich-transcript-product-validation-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
                // The original assertion/failure is more useful than a best-effort cleanup error.
            }
            throw $e;
        }
    }

    private function persistComparisonArtifacts(TmuxPane $pane, string $tag): void
    {
        if (null === $this->comparisonDir) {
            return;
        }

        @mkdir($this->comparisonDir, 0o777, true);
        $stamp = date('Ymd-His');
        // Visible-pane ANSI can be logo-only once content scrolls; keep history for before/after proof.
        $ansiHistory = $this->tmux->captureAnsiWithHistory($pane, 3000);
        file_put_contents(\sprintf('%s/%s-history-%s.ansi', $this->comparisonDir, $tag, $stamp), $ansiHistory);
        file_put_contents(
            \sprintf('%s/%s-history-%s.txt', $this->comparisonDir, $tag, $stamp),
            $this->tmux->capturePlainWithHistory($pane, 3000),
        );
    }

    private function agentCommand(): string
    {
        $fixtureChain = implode(';', [
            $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-markdown-thinking-response.json',
            $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-tool-call-edit.json',
        ]);

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-rich-product-');

        $dbPath = $paths['app'];

        $transportDbPath = $paths['transport'];

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixtureChain),
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-rich-product');
        TestDirectoryIsolation::createHatfieldTree($dir);
        TestDirectoryIsolation::createHatfieldTree($dir.'/home');

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
                'theme' => 'cyberpunk',
                'transcript' => [
                    'thinking' => [
                        'visible' => false,
                        'style' => 'dim_italic',
                    ],
                    'previews' => [
                        'expanded_by_default' => false,
                        'tool_result_lines' => 8,
                        'diff_lines' => 4,
                    ],
                ],
            ],
        ];

        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }
}
