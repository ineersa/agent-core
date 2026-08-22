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
 * Minimal real-TTY proof for /agents-live open, child live view, and /agents-main return.
 *
 * Picker highlight/row formatting and stream-while-open behavior are covered by
 * virtual/runtime tests; this keeps one process-transport integration smoke.
 *
 * @group tui-e2e-replay
 */
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

    public function testAgentsLiveOpenAndAgentsMainReturnsToParent(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-live-view',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        $sessionId = $this->createSessionAndWaitForAssistant($pane);
        SubagentProgressEventsFixture::write($this->testProjectDir, $sessionId);

        $this->tmux->sendKey($pane, 'C-u');
        $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForTuiReady($pane);
        $this->tmux->waitForCaptureContains($pane, 'agent_e2e_progress_fixture', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Resumed transcript must show fixture artifact');

        $this->tmux->sendKey($pane, 'C-u');
        $this->tmux->sendLiteral($pane, '/agents-live');
        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForCaptureContains($pane, 'Agents live', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Agents live picker must open');
        $this->tmux->waitForCaptureContains($pane, ChildContextStatisticsFixture::CONTEXT_DETAIL, TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Picker row must show child context usage');

        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForCaptureContains($pane, 'Child agent', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Live view working line must appear');
        $this->tmux->waitForCaptureContains($pane, '[completed]', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Fixture child must show completed status in live view');

        $this->tmux->sendKey($pane, 'C-u');
        $this->tmux->sendLiteral($pane, '/agents-main');
        $this->tmux->sendKey($pane, 'Enter');
        $this->tmux->waitForCaptureContains($pane, 'scout [completed]', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL, 'Parent transcript must restore after /agents-main');
        $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, ChildContextStatisticsFixture::TRANSCRIPT_CTX_LINE)
                && !str_contains($cap, 'Subagent live:')
                && (str_contains($cap, '● idle') || str_contains($cap, '◆')),
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Parent must restore child card context and leave live chrome',
            history: 0,
        );

        $this->tmux->sendKey($pane, 'C-d');
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
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-subagent-live');

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
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-subagent-live');
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);
        TuiE2eDatabaseEnv::writeReplaySettings($dir, TuiE2eDatabaseEnv::replayBaseSettings());

        return $dir;
    }
}
