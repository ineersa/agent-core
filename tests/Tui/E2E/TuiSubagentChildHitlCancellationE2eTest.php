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
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->tmux->waitForCaptureContains($pane, 'needs input', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Main transcript card must show child needs input');

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/agents-live');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Agents live', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Agents live picker must open');
            $this->tmux->waitForCaptureContains($pane, '⚠ needs input', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Picker must mark waiting child');

            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Child waiting for your input', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Live view working line must show child waiting');
            $this->tmux->waitForCaptureContains($pane, 'Which file should the scout inspect next?', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Child question overlay prompt must appear');
            $this->tmux->waitForCaptureContains($pane, 'awaiting answer', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Child HITL must surface in transcript');
            // Child cancel target/precedence: SubagentLiveCommandRegistrarTest + CancelListenerTest (overlay blocks ESC/cancel underneath).
        } finally {
            // snapshot optional; TmuxHarness has no saveAnsiSnapshot helper on this test class
        }
    }

    public function testLeaveChildLiveViewDropsChildQuestionAndEscDoesNotFalseCancel(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-child-hitl-leave',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        $sessionId = $this->createSessionAndWaitForAssistant($pane);
        SubagentChildHitlEventsFixture::write($this->testProjectDir, $sessionId);

        $this->tmux->sendKey($pane, 'C-u');
        $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
        $this->tmux->waitForTuiReadyAfterLogo($pane);

        $this->tmux->waitForCaptureContains($pane, 'needs input', 12.0, 'Main transcript card must show child needs input');

        $this->tmux->sendKey($pane, 'C-u');
        $this->tmux->sendLiteral($pane, '/agents-live');
        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForCaptureContains($pane, 'Agents live', 10.0, 'Agents live picker must open');
        $this->tmux->waitForCaptureContains($pane, '⚠ needs input', 10.0, 'Picker must mark waiting child');

        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForCaptureContains($pane, 'Child waiting for your input', 10.0, 'Live view working line must show child waiting');
        $this->tmux->waitForCaptureContains($pane, 'Which file should the scout inspect next?', 12.0, 'Child question overlay prompt must appear');
        $this->tmux->waitForCaptureContains($pane, 'agents-live scout', 10.0, 'Live-view footer must show agents-live chrome');

        // Production leave path: Ctrl+\ → SubagentLiveMainReturn (same as /agents-main).
        // Do not wait for the transient "Returned to main session" working line — ticks overwrite it.
        $this->tmux->sendKey($pane, 'C-\\');
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                $leftLiveChrome = !str_contains($cap, 'agents-live scout')
                    && !str_contains($cap, 'Child waiting for your input');
                $questionGone = !str_contains($cap, 'Which file should the scout inspect next?');

                return $leftLiveChrome && $questionGone;
            },
            timeout: 12.0,
            message: 'Ctrl+\\ leave must drop live chrome and child question from main UI',
            history: 2000,
        );

        $captureAfterLeave = $this->tmux->capturePlainWithHistory($pane, 2000);
        $this->assertStringNotContainsString(
            'Which file should the scout inspect next?',
            $captureAfterLeave,
            'Child question must not remain visible in main after leaving live view',
        );
        $this->assertStringNotContainsString(
            'Child waiting for your input',
            $captureAfterLeave,
            'Child live working line must not remain after Ctrl+\\',
        );
        $this->assertStringNotContainsString(
            'agents-live scout',
            $captureAfterLeave,
            'Live-view footer chrome must clear after leave',
        );

        // Esc on main after leave must not fabricate child-cancel confirmation.
        $this->tmux->sendKey($pane, 'Escape');
        usleep(500_000);
        $captureAfterEsc = $this->tmux->capturePlainWithHistory($pane, 2000);

        $this->assertStringNotContainsString(
            'Child cancelled',
            $captureAfterEsc,
            'Esc on main must not show false child-cancel confirmation',
        );
        $this->assertStringNotContainsString(
            'Cancelling child',
            $captureAfterEsc,
            'Esc on main must not request selected-child cancellation after leave',
        );
        $this->assertStringNotContainsString(
            'Cancelled by parent',
            $captureAfterEsc,
            'Esc after leave must not indicate parent-driven child cancellation',
        );
    }

    private function createSessionAndWaitForAssistant(TmuxPane $pane): string
    {
        $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
        $this->tmux->waitForTuiReadyAfterLogo($pane);
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
        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }
}
