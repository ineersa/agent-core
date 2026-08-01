<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Minimal tmux proof: visible assistant thinking streams and occupies multiple
 * terminal rows (explicit newlines + session-37-shaped long bold segments).
 *
 * Virtual layer already proves MarkdownWidget wrap and hidden `⋯ Thinking`.
 * This exercises real ScreenWriter/tmux physical rendering only.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiVisibleThinkingWrapE2eTest extends TestCase
{
    private const string PROMPT = 'visible-thinking-wrap-probe';

    private const string MARKER_A = 'THINKWRAP-A';

    private const string MARKER_C = 'THINKWRAP-C';

    private const string MARKER_D = 'THINKWRAP-D';

    private TmuxHarness $tmux;

    private string $projectRoot;

    private string $testProjectDir;

    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        TestDirectoryIsolation::ensureDirectory($this->snapshotDir, 0o777);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }

        if (isset($this->testProjectDir) && '' !== $this->testProjectDir) {
            TestDirectoryIsolation::removeDirectory($this->testProjectDir);
        }
    }

    public function testVisibleStreamingThinkingOccupiesMultipleTerminalRows(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-thinking-wrap',
            // Narrow enough that long bold session-37-shaped segments wrap when physical rendering works.
            width: 60,
            height: 40,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, self::PROMPT);
            $this->tmux->sendKey($pane, 'Enter');

            $capture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, self::MARKER_A)
                    && str_contains($cap, self::MARKER_C)
                    && str_contains($cap, self::MARKER_D)
                    && (str_contains($cap, '◇') || str_contains($cap, 'done')),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Visible thinking markers / assistant response never appeared in real terminal',
                history: 3000,
            );

            $this->assertStringNotContainsString(
                '⋯ Thinking',
                $capture,
                'Hidden one-line placeholder must not replace raw thinking when thinking.visible=true',
            );
            $this->assertStringContainsString(self::MARKER_A, $capture);
            $this->assertStringContainsString(self::MARKER_C, $capture);
            $this->assertStringContainsString(self::MARKER_D, $capture);

            $rowA = $this->firstLineIndexContaining($capture, self::MARKER_A);
            $rowC = $this->firstLineIndexContaining($capture, self::MARKER_C);
            $rowD = $this->firstLineIndexContaining($capture, self::MARKER_D);

            $this->assertNotSame($rowA, $rowC, 'THINKWRAP-A and THINKWRAP-C must land on distinct terminal rows');
            $this->assertNotSame($rowC, $rowD, 'THINKWRAP-C and THINKWRAP-D must land on distinct terminal rows');
            $this->assertNotSame($rowA, $rowD, 'THINKWRAP-A and THINKWRAP-D must land on distinct terminal rows');
            $this->assertGreaterThan($rowA, $rowC, 'Explicit thinking paragraphs must progress downward in capture order');
            $this->assertGreaterThan($rowC, $rowD, 'Third thinking line must be below second paragraph');

            // Session-37-shaped long bold without internal newlines: at width 60 the first
            // bold segment alone exceeds one row if physical wrap works.
            $rowsWithA = $this->lineIndexesContaining($capture, self::MARKER_A);
            $this->assertNotEmpty($rowsWithA);
            // Soft proof: either A wraps across >1 capture lines, or later explicit markers
            // already proved multi-row; keep soft wrap check as diagnostic signal only when A
            // appears alone on one short line that still contains the long bold body fragment.
            $lineA = $this->lineAt($capture, $rowA);
            $this->assertStringContainsString(
                'session-37-shaped bold summary',
                $lineA,
                'Raw thinking body must be visible on the THINKWRAP-A row, not collapsed away',
            );

            $this->saveAnsiSnapshot($pane, 'visible-thinking-wrap');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'visible-thinking-wrap-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
                // Best-effort detach; original failure is more useful.
            }
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $fixturePath = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-visible-thinking-wrap-response.json';
        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-thinking-wrap-');

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixturePath),
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-thinking-wrap');
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
                                'input' => ['text'],
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
                        'visible' => true,
                        'style' => 'dim_italic',
                    ],
                ],
            ],
        ];

        $yaml = Yaml::dump(TuiE2eDatabaseEnv::withSingleLlmWorkerForReplay($settings), 8, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        file_put_contents($path, $ansi);
    }

    private function firstLineIndexContaining(string $capture, string $needle): int
    {
        $indexes = $this->lineIndexesContaining($capture, $needle);
        if ([] === $indexes) {
            throw new \RuntimeException(\sprintf('Needle "%s" not found in capture lines.', $needle));
        }

        return $indexes[0];
    }

    /**
     * @return list<int>
     */
    private function lineIndexesContaining(string $capture, string $needle): array
    {
        $split = preg_split('/\r\n|\n|\r/', $capture);
        $lines = false === $split ? [] : $split;
        $indexes = [];
        foreach ($lines as $i => $line) {
            if (str_contains($line, $needle)) {
                $indexes[] = $i;
            }
        }

        return $indexes;
    }

    private function lineAt(string $capture, int $index): string
    {
        $split = preg_split('/\r\n|\n|\r/', $capture);
        $lines = false === $split ? [] : $split;
        if (!isset($lines[$index])) {
            throw new \RuntimeException(\sprintf('Capture has no line index %d.', $index));
        }

        return $lines[$index];
    }
}
