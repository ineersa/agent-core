<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\SubagentChildHitlEventsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Real-TTY proof that child HITL surfaces through agents-live and that leaving
 * live view drops the child question without fabricating a cancel confirmation.
 *
 * @group tui-e2e-replay
 */
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

    public function testChildHitlSurfacesAndLeaveDropsQuestionWithoutFalseCancel(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-child-hitl',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        $sessionId = $this->createSessionAndWaitForAssistant($pane);
        SubagentChildHitlEventsFixture::write($this->testProjectDir, $sessionId);

        $this->tmux->sendKey($pane, 'C-u');
        $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForTuiReady($pane);
        $this->tmux->waitForCaptureContains($pane, 'needs input', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Main transcript card must show child needs input');

        $this->tmux->sendKey($pane, 'C-u');
        $this->tmux->sendLiteral($pane, '/agents-live');
        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForCaptureContains($pane, 'Agents live', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Agents live picker must open');
        $this->tmux->waitForCaptureContains($pane, '⚠ needs input', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Picker must mark waiting child');

        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForCaptureContains($pane, 'Child waiting for your input', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Live view working line must show child waiting');
        $this->tmux->waitForCaptureContains($pane, 'Which file should the scout inspect next?', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Child question overlay prompt must appear');

        $this->tmux->sendKey($pane, 'C-\\');
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return !str_contains($cap, 'agents-live scout')
                    && !str_contains($cap, 'Child waiting for your input')
                    && !str_contains($cap, 'Which file should the scout inspect next?')
                    && (str_contains($cap, '● idle') || str_contains($cap, '◆'));
            },
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Ctrl+\\ leave must drop live chrome and child question from visible UI',
            history: 0,
        );

        $this->tmux->sendKey($pane, 'Escape');
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap): bool {
                return !str_contains($cap, 'Child cancelled')
                    && !str_contains($cap, 'Cancelling child')
                    && !str_contains($cap, 'Cancelled by parent')
                    && (str_contains($cap, '● idle') || str_contains($cap, '◆'));
            },
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Esc on main after leave must not fabricate child-cancel confirmation',
            history: 0,
        );
    }

    private function createSessionAndWaitForAssistant(TmuxPane $pane): string
    {
        $this->tmux->waitForTuiReady($pane);
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
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-subagent-hitl');

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
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
        $settings = TuiE2eDatabaseEnv::replayBaseSettings();
        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }
}
