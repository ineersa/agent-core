<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\SubagentChildHitlEventsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @group tui-e2e-replay */
#[Group('tui-e2e-replay')]
final class TuiSubagentChildHitlCancellationE2eTest extends TestCase
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

    public function testMainAttentionLiveViewChildHitlQuestionSurfaces(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-child-hitl',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $sessionId = $this->createSessionAndWaitForAssistant($pane);
            SubagentChildHitlEventsFixture::write($this->testProjectDir, $sessionId);

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            usleep(300_000);

            $this->tmux->waitForCaptureContains($pane, 'needs input', 12.0, 'Main transcript card must show child needs input');

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, '/agents-live');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, '⚠ needs input', 10.0, 'Picker must mark waiting child');

            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Child waiting for your input', 10.0, 'Live view working line must show child waiting');
            $this->tmux->waitForCaptureContains($pane, 'Which file should the scout inspect next?', 12.0, 'Child question overlay prompt must appear');
            $this->tmux->waitForCaptureContains($pane, 'awaiting answer', 10.0, 'Child HITL must surface in transcript');
            // Child cancel target/precedence: SubagentLiveCommandRegistrarTest + CancelListenerTest (overlay blocks ESC/cancel underneath).
        } finally {
            // snapshot optional; TmuxHarness has no saveAnsiSnapshot helper on this test class
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
        $this->assertNotEmpty($matches[1] ?? null);

        return $matches[1];
    }

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-resume-minimal.json';
        $projectDir = ProjectDir::get();
        $dbPath = 'app_test-tui-subagent-hitl-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            escapeshellarg($dbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixturePath),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($projectDir.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-subagent-child-hitl');
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);
        $settings = ['ai' => ['providers' => ['llama_cpp_test' => ['api' => 'openai-completions', 'api_key' => 'dummy', 'completions_path' => '/chat/completions', 'supports_completions' => true, 'supports_embeddings' => false, 'supports_thinking_levels' => true, 'models' => ['test' => ['name' => 'test', 'context_window' => 32768, 'max_tokens' => 32768, 'input' => ['text'], 'tool_calling' => true, 'reasoning' => true, 'thinking_level_map' => ['off' => '0'], 'cost' => ['input' => 0, 'output' => 0]]]]]]];
        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }
}
