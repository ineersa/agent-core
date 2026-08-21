<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Replay-backed tmux proof: ask_human Choice labels wrap fully at narrow width
 * (no truncation ellipsis), Down+Enter selects the long logical option, and the
 * exact selected value reaches the next model turn.
 */
#[Group('tui-e2e-replay')]
final class TuiAskHumanChoiceWrapE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->tmux->setSnapshotDir($this->testProjectDir);
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

    public function testAskHumanChoiceLabelsWrapAndDownEnterReturnsExactValue(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-ask-human-choice-wrap',
            width: 56,
            height: 40,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);
            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, 'Ask me a wrapping choice');
            $this->tmux->sendKey($pane, 'Enter');

            $capture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Choose an option')
                    && str_contains($cap, 'SHORT_OPTION_KEEP_SINGLE_LINE')
                    && str_contains($cap, 'LONG_OPTION_BEGIN')
                    && str_contains($cap, 'LONG_OPTION_TAIL_UNIQUE'),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'ask_human choice overlay did not show full wrapped long option',
                history: 3000,
            );

            $this->tmux->saveAnsiSnapshot($pane, 'ask-human-choice-wrap-overlay');

            $this->assertStringContainsString('SHORT_OPTION_KEEP_SINGLE_LINE', $capture);
            $this->assertStringContainsString('LONG_OPTION_BEGIN', $capture);
            $this->assertStringContainsString('LONG_OPTION_TAIL_UNIQUE', $capture);
            foreach (explode("\n", $capture) as $line) {
                if (str_contains($line, 'LONG_OPTION_') || str_contains($line, 'SHORT_OPTION_')) {
                    $this->assertStringNotContainsString('…', $line, 'Choice labels must wrap, not ellipsize');
                }
            }

            $this->tmux->sendKey($pane, 'Down');
            $this->tmux->sendKey($pane, 'Enter');

            $answered = $this->tmux->waitForCaptureContains(
                $pane,
                'CHOICE_WRAP_SELECTED_OK',
                TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
            );
            $this->tmux->saveAnsiSnapshot($pane, 'ask-human-choice-wrap-answered');
            $this->assertStringContainsString('CHOICE_WRAP_SELECTED_OK', $answered);
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'ask-human-choice-wrap-FAILURE');
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-ask-human-choice-wrap');
        $dbPath = $paths['app'];
        $transportDbPath = $paths['transport'];

        $fixturePath = implode(';', [
            $projectDir.'/tests/Tui/E2E/fixtures/tui-ask-human-choice-wrap.json',
            $projectDir.'/tests/Tui/E2E/fixtures/tui-ask-human-choice-wrap-after-answer.json',
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
            .'--prompt="Ask me a wrapping choice" '
            .'2>&1',
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixturePath),
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-ask-human-choice-wrap');
        @mkdir($dir.'/.hatfield', 0o777, true);

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

        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }
}
