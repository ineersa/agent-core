<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\SubagentProgressEventsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @group tui-e2e-replay */
#[Group('tui-e2e-replay')]
final class TuiSubagentLiveChildExportE2eTest extends TestCase
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

    public function testAgentsLivePickerExportKeyWritesChildHtml(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-export',
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
            usleep(300_000);

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, '/agents-live');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'agent_e2e_progress_fixture', 10.0, 'Picker must list subagent artifact');

            $this->tmux->sendKey($pane, 'e');
            $this->tmux->waitForCaptureContains(
                $pane,
                'Child agent exported to:',
                10.0,
                'Export key must report child HTML path in working message',
            );

            $expectedHtml = $this->testProjectDir.'/hatfield-child-agent_e2e_progress_fixture.html';
            $this->assertFileExists($expectedHtml, 'Child export must write HTML in isolated project cwd');

            $html = file_get_contents($expectedHtml);
            $this->assertIsString($html);
            $this->assertStringContainsString('Child-only export marker scout-e2e', $html, 'HTML must contain child canonical events content');
            $this->assertStringNotContainsString('Export me', $html, 'Must not export parent session events');

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
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
        $projectDir = ProjectDir::get();
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-subagent-export-');

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
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-subagent-export');
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);
        $settings = ['ai' => ['providers' => ['llama_cpp_test' => ['api' => 'openai-completions', 'api_key' => 'dummy', 'completions_path' => '/chat/completions', 'supports_completions' => true, 'supports_embeddings' => false, 'supports_thinking_levels' => true, 'models' => ['test' => ['name' => 'test', 'context_window' => 32768, 'max_tokens' => 32768, 'input' => ['text'], 'tool_calling' => true, 'reasoning' => true, 'thinking_level_map' => ['off' => '0'], 'cost' => ['input' => 0, 'output' => 0]]]]]]];
        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }
}
