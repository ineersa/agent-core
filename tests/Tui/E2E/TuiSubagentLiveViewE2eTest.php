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
final class TuiSubagentLiveViewE2eTest extends TestCase
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

    public function testAgentsLivePickerOpenReadonlyAndAgentsMainReturnsToParent(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-live-view',
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

            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Subagent live:', 10.0, 'Interactive live view status must appear');
            $this->tmux->waitForCaptureContains($pane, '[completed]', 10.0, 'Fixture child must show completed status in live view');

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, 'continue after completion');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'has finished', 10.0, 'Terminal child input must show finished-subagent warning');
            $capAfterTerminal = $this->tmux->capturePlainWithHistory($pane, 2500);
            self::assertStringContainsString('has finished', strtolower($capAfterTerminal), 'Terminal child warning must mention finished subagent');

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, '/new');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Leave subagent live view', 10.0, 'Blocked slash must show leave-live-view warning');
            $capAfterBlock = $this->tmux->capturePlainWithHistory($pane, 2500);
            self::assertStringContainsString('agent_e2e_progress_fixture', $capAfterBlock, 'Must remain in live view after blocked /new');
            self::assertStringNotContainsString('subagent scout running', $capAfterBlock, 'Must not switch back to parent transcript after blocked /new');

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, '/agents-main');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'subagent scout', 10.0, 'Parent transcript must restore after /agents-main');
            self::assertStringNotContainsString('Subagent live:', $this->tmux->capturePlainWithHistory($pane, 2500));

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            try { $this->tmux->sendKey($pane, 'C-d'); } catch (\Throwable) {}
            throw $e;
        }
    }

    private function createSessionAndWaitForAssistant(TmuxPane $pane): string
    {
        $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
        usleep(150_000);
        $this->tmux->sendLiteral($pane, 'hi');
        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, '◇') || str_contains($cap, '✕'),
            timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
            message: 'Assistant block did not appear',
            history: 2000,
        );
        $cap = $this->tmux->capturePlainWithHistory($pane, 2000);
        preg_match('/session\s+(\d+)/', $cap, $matches);
        self::assertNotEmpty($matches[1] ?? null);
        return $matches[1];
    }

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-resume-minimal.json';
        $projectDir = ProjectDir::get();
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-subagent-live-');

        $dbPath = $paths['app'];

        $transportDbPath = $paths['transport'];
        return sprintf(
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
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-subagent-live');
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);
        $settings = ['ai' => ['providers' => ['llama_cpp_test' => ['api' => 'openai-completions', 'api_key' => 'dummy', 'completions_path' => '/chat/completions', 'supports_completions' => true, 'supports_embeddings' => false, 'supports_thinking_levels' => true, 'models' => ['test' => ['name' => 'test', 'context_window' => 32768, 'max_tokens' => 32768, 'input' => ['text'], 'tool_calling' => true, 'reasoning' => true, 'thinking_level_map' => ['off' => '0'], 'cost' => ['input' => 0, 'output' => 0]]]]]]];
        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);
        return $dir;
    }
}
