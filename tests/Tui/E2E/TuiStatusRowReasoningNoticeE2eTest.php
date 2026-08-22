<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal tmux proof for status-row stability and transient reasoning notice.
 *
 * Test thesis (reasoning): Shift+Tab shows the panel-only reasoning line; submitting
 * the next turn removes it while footer ◆ remains.
 *
 * Test thesis (layout): adding one status-panel row (Shift+Tab reasoning notice) must
 * shift footer and footer-separator anchors down by exactly one line in the real terminal;
 * a spurious working-slot collapse would shift them by an additional line (virtual layer
 * covers hidden-working reserve in ChatScreenStatusRowVirtualRenderTest).
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiStatusRowReasoningNoticeE2eTest extends TestCase
{
    private const string SHIFT_TAB = "\x1b[Z";

    private const string REPLAY_PROMPT = 'Respond with exactly one sentence: the sky is blue.';

    private const string FOOTER_ANCHOR = '⎇';

    private TmuxHarness $tmux;

    private string $projectRoot;

    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
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

    public function testShiftTabReasoningNoticeClearsOnSubmitWithoutShiftingFooterAnchor(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-status-reasoning',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            $baselineCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            $baselineFooterIndex = $this->footerLineIndexLast($baselineCapture);
            $baselineSeparatorIndex = $this->footerSeparatorLineIndexAboveFooter($baselineCapture);

            $this->tmux->sendLiteral($pane, self::SHIFT_TAB);

            $withNotice = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => 1 === preg_match('/^  reasoning\s+minimal\s*$/m', $cap),
                timeout: 5.0,
                message: 'Shift+Tab reasoning status panel line did not appear',
                history: 2000,
            );

            $this->assertStringContainsString('◆', $withNotice, 'Footer diamond must remain after Shift+Tab');
            $this->assertMatchesRegularExpression('/^  reasoning\s+minimal\s*$/m', $withNotice, 'Status panel reasoning row expected');

            $noticeFooterIndex = $this->footerLineIndexLast($withNotice);
            $noticeSeparatorIndex = $this->footerSeparatorLineIndexAboveFooter($withNotice);
            $this->assertSame($baselineFooterIndex + 1, $noticeFooterIndex,
                'Footer anchor must move down exactly one line when status panel gains the reasoning row');
            $this->assertSame($baselineSeparatorIndex + 1, $noticeSeparatorIndex,
                'Footer separator must move down exactly one line when status panel gains the reasoning row');

            $this->tmux->saveAnsiSnapshot($pane, 'status-reasoning-after-shift-tab');

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, self::REPLAY_PROMPT);
            $this->tmux->sendKey($pane, 'Enter');

            $afterSubmit = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => !preg_match('/\s{2}reasoning\s+\S+/', $cap)
                    && (str_contains($cap, 'Working...') || str_contains($cap, '◐')),
                timeout: 10.0,
                message: 'Transient reasoning panel line still visible after submit',
                history: 2000,
            );

            $this->assertStringContainsString('◆', $afterSubmit);
            $this->tmux->saveAnsiSnapshot($pane, 'status-reasoning-after-submit');

            $this->tmux->waitForCallback(
                $pane,
                fn (string $cap): bool => $this->captureShowsIdleWithoutActiveWorking($cap),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Idle status not restored after replay turn',
                history: 2000,
            );

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇') || str_contains($cap, '✕'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Replay assistant block did not appear',
                history: 2000,
            );

            $idleCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            $this->assertDoesNotMatchRegularExpression('/\s{2}reasoning\s+\S+/', $idleCapture, 'Transient reasoning panel line must stay cleared after turn');
            $this->assertTrue($this->captureShowsIdleWithoutActiveWorking($idleCapture), 'Live status row must show idle after replay turn');

            $this->tmux->saveAnsiSnapshot($pane, 'status-reasoning-idle-baseline');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'status-reasoning-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
                // Best-effort tmux detach during failure diagnostics; pane may already be gone.
            }
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $fixturePath = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-simple-text-response.json';
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-status-reasoning-');

        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-status-reasoning');
        @mkdir($dir.'/.hatfield', 0o777, true);

        $settings = TuiE2eDatabaseEnv::replayBaseSettings();

        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }

    /**
     * Last footer session-branch anchor line in the full capture (absolute index).
     */
    private function footerLineIndexLast(string $capture): int
    {
        return $this->lineIndexLast($capture, self::FOOTER_ANCHOR);
    }

    /**
     * Full-width separator line immediately above the footer anchor (not the editor separator).
     */
    private function footerSeparatorLineIndexAboveFooter(string $capture): int
    {
        $lines = explode("\n", $capture);
        $footerIndex = $this->footerLineIndexLast($capture);

        for ($i = $footerIndex - 1; $i >= 0; --$i) {
            if (str_contains($lines[$i], '─')) {
                return $i;
            }
        }

        $this->fail('Footer separator line missing above footer anchor in tmux capture');
    }

    private function lineIndexLast(string $capture, string $needle): int
    {
        $last = null;
        foreach (explode("\n", $capture) as $i => $line) {
            if (str_contains($line, $needle)) {
                $last = $i;
            }
        }

        if (null === $last) {
            $this->fail('Anchor missing from tmux capture: '.$needle);
        }

        return $last;
    }

    /**
     * True when the newest working-status line in the capture is idle, not an active spinner.
     */
    private function captureShowsIdleWithoutActiveWorking(string $capture): bool
    {
        $lines = explode("\n", $capture);

        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            $line = $lines[$i];
            if (preg_match('/^\s+●\s*idle/', $line)) {
                return true;
            }
            if (preg_match('/^\s+◐/', $line)) {
                return false;
            }
        }

        return false;
    }
}
