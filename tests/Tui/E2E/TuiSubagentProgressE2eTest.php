<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture;
use Ineersa\Tui\Tests\Support\SubagentProgressEventsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TmuxHarness proof: structured subagent progress renders inline after resume replay.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiSubagentProgressE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @mkdir($this->snapshotDir, 0o777, true);
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

    public function testResumeShowsStructuredSubagentProgressWithoutSpam(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-progress',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $sessionId = $this->createSessionAndWaitForAssistant($pane);
            SubagentProgressEventsFixture::write($this->testProjectDir, $sessionId);

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            usleep(200_000);

            $capture = $this->tmux->capturePlainWithHistory($pane, 2500);

            // Polished card format after SubagentTranscriptCardBuilder:
            //   ✓ scout [completed] — glyph + agent_name + status badge
            //   Task Inspect TUI subagent rendering — no colon
            //   Artifact artifacts/agents/agent_e2e_progress_fixture — full path, singular, no colon
            $this->assertStringContainsString('✓ scout [completed]', $capture);
            $this->assertStringContainsString('Task Inspect TUI subagent rendering', $capture);
            $this->assertStringContainsString('Artifact artifacts/agents/agent_e2e_progress_fixture', $capture);
            $this->assertStringContainsString('agent_e2e_progress_fixture', $capture);
            $this->assertStringContainsString('3 turns', $capture);
            $this->assertStringContainsString('deepseek/deepseek-v4-flash', $capture);
            $this->assertStringContainsString(ChildContextStatisticsFixture::TRANSCRIPT_CTX_LINE, $capture, 'Resumed parent transcript card must show child context usage');
            $this->assertStringContainsString('Use agent_retrieve', $capture);
            $this->assertStringNotContainsString('running scout |', $capture);
            $this->assertStringNotContainsString('parallel subagents running', $capture);

            $turnOneCount = substr_count($capture, 'turn 1');
            $this->assertLessThanOrEqual(1, $turnOneCount, 'Coalesced progress must not repeat stale turn 1 spam');

            $this->saveAnsiSnapshot($pane, 'subagent-progress-resume');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'subagent-progress-resume-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function createSessionAndWaitForAssistant(TmuxPane $pane): string
    {
        $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
        usleep(150_000);

        $this->tmux->sendLiteral($pane, 'hi');
        $this->tmux->sendKey($pane, 'Enter');

        $sessionId = null;
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap) use (&$sessionId): bool {
                if (!str_contains($cap, '◇') && !str_contains($cap, '✕')) {
                    return false;
                }
                if (!preg_match('/session\s+(\d+)/', $cap, $matches)) {
                    return false;
                }
                $sessionId = $matches[1];

                return true;
            },
            timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
            message: 'Assistant block and session id must both appear in capture',
            history: 2000,
        );
        $this->assertNotEmpty($sessionId, 'Session id must appear in the same capture as assistant/error glyph');

        return $sessionId;
    }

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-resume-minimal.json';
        if (!is_file($fixturePath)) {
            $this->fail("Fixture not found: {$fixturePath}");
        }

        $projectDir = ProjectDir::get();
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-subagent-progress-');

        $dbPath = $paths['app'];

        $transportDbPath = $paths['transport'];

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixturePath),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($projectDir.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-subagent-progress');
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);

        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
                'default_reasoning' => 'off',
                'providers' => [
                    'deepseek' => ChildContextStatisticsFixture::deepseekProviderSettings(),
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
                                    'off' => '0', 'minimal' => '0', 'low' => '0', 'medium' => '0', 'high' => '0', 'xhigh' => '0',
                                ],
                                'cost' => ['input' => 0, 'output' => 0],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        @mkdir($dir.'/home/.hatfield', 0o777, true);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $name): void
    {
        $path = $this->snapshotDir.'/'.$name.'.ansi';
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        file_put_contents(\sprintf('%s/%s-%s.ansi', $this->snapshotDir, $name, $ts), $ansi);
    }
}
