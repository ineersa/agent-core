<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Replay-backed tmux proof: active ask_human text overlay renders markdown prompt
 * with compact left indent (not raw ** / backticks) via the real TUI path.
 */
#[Group('tui-e2e-replay')]
final class TuiAskHumanOverlayMarkdownE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
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

    public function testAskHumanTextOverlayRendersMarkdownPromptWithIndent(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-ask-human-overlay',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            $this->tmux->waitForTuiReadyAfterLogo($pane);
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $this->tmux->sendLiteral($pane, 'Ask me a markdown question');
            $this->tmux->sendKey($pane, 'Enter');

            $capture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Human input required')
                    && str_contains($cap, 'Overlay proof')
                    && str_contains($cap, '[type answer and press Enter]'),
                timeout: 25.0,
                message: 'ask_human text overlay did not appear with prompt and hint',
                history: 3000,
            );

            $this->saveAnsiSnapshot($pane, 'ask-human-overlay-markdown');

            self::assertStringContainsString('Human input required', $capture);
            self::assertStringContainsString('Overlay proof', $capture);
            self::assertStringContainsString('inline', $capture);
            self::assertStringContainsString('[type answer and press Enter]', $capture);
            self::assertStringNotContainsString('**Overlay proof**', $capture,
                'Active overlay must not show raw markdown bold markers');
            self::assertStringNotContainsString('`inline`', $capture,
                'Active overlay must not show raw markdown code markers');

            // Compact left indent: header and prompt should not start at column 0.
            foreach (explode("\n", $capture) as $line) {
                if (str_contains($line, 'Human input required') || str_contains($line, 'Overlay proof')) {
                    self::assertMatchesRegularExpression('/^\s+\S/', $line,
                        'Overlay header/prompt lines should be indented, not flush-left');
                }
            }

            $this->tmux->sendLiteral($pane, 'markdown answer');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCaptureContains($pane, 'Thanks for the answer', 20.0);
            $this->saveAnsiSnapshot($pane, 'ask-human-overlay-answered');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'ask-human-overlay-FAILURE');
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-ask-human-overlay');
        $dbPath = $paths['app'];
        $transportDbPath = $paths['transport'];

        $fixturePath = \implode(';', [
            $projectDir.'/tests/Tui/E2E/fixtures/tui-ask-human-markdown-overlay.json',
            $projectDir.'/tests/Tui/E2E/fixtures/tui-ask-human-after-answer-text.json',
        ]);

        return \sprintf(
            'APP_ENV=test '
            .TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath)
            .'HOME=%s '
            .'HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s '
            .'%s %s agent '
            .'--model=llama_cpp_test/test '
            .'--tools=ask_human '
            .'--tools-excluded=bash,write,edit,read,subagent '
            .'--prompt="Ask me a markdown question" '
            .'2>&1',
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($fixturePath),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-ask-human-overlay');
        @\mkdir($dir.'/.hatfield', 0o777, true);

        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
                'default_reasoning' => 'off',
                'providers' => [
                    'llama_cpp_test' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'http://127.0.0.1:9052/v1',
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
                                'cost' => ['input' => 0, 'output' => 0],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $yaml = Yaml::dump($settings, 6, 4);
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        @\mkdir($dir.'/home/.hatfield', 0o777, true);
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
