<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Startup snapshot test for the agent TUI.
 *
 * Launches the agent in a detached tmux session at 120×40,
 * waits for the startup layout to render, captures a plain-text
 * snapshot, normalises dynamic content, and compares against
 * the committed golden fixture.
 *
 * After capture, sends Ctrl+D to exit the interactive TUI cleanly.
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
final class TuiStartupSnapshotTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $goldenPath;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->goldenPath = __DIR__.'/../Snapshots/startup-120x40.txt';
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    /**
     * Verify the agent TUI startup layout matches the golden snapshot.
     *
     * Starts the interactive TUI in a tmux pane, waits for the
     * Hatfield logo to render, captures the snapshot, then exits
     * cleanly via Ctrl+D.
     */
    #[Group('tui-e2e')]
    public function testStartupLayoutMatchesGoldenSnapshot(): void
    {
        $pane = $this->tmux->startDetached(
            command: 'php bin/console agent --prompt="hello from tmux e2e"; echo; echo "── TUI exited ──"; exec sleep 3600',
            prefix: 'hatfield-startup',
        );

        // Wait for the TUI to render — looking for the Hatfield logo
        $capture = $this->tmux->waitForCaptureContains(
            pane: $pane,
            needle: '█',
            timeout: 10.0,
        );

        // Wait a bit more for the full layout to render
        usleep(500_000);

        $capture = $this->tmux->capturePlain($pane);

        // Send Ctrl+D to exit the interactive TUI cleanly
        $this->tmux->sendKey($pane, 'C-d');
        usleep(300_000);

        $normalized = $this->tmux->normalizeSnapshot($capture);

        if ($this->shouldUpdateSnapshots()) {
            file_put_contents($this->goldenPath, $normalized);
            self::markTestSkipped(sprintf(
                'Golden snapshot updated: %s (commit this change)',
                basename($this->goldenPath),
            ));
        }

        // Load expected golden
        self::assertFileExists($this->goldenPath, sprintf(
            'Golden fixture not found: %s. Run HATFIELD_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --group tui-e2e to generate it.',
            $this->goldenPath,
        ));

        $expected = file_get_contents($this->goldenPath);

        self::assertSame(
            $expected,
            $normalized,
            sprintf(
                "TUI startup snapshot does not match golden fixture.\n"
                ."Expected: %s\n"
                ."Got (normalized):\n%s\n"
                ."If this change is intentional, run HATFIELD_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --group tui-e2e",
                $this->goldenPath,
                $normalized !== $expected ? $this->diffHint($expected, $normalized) : '(same)',
            ),
        );
    }

    /**
     * Verify the startup snapshot contains expected key strings.
     *
     * This is a less brittle assertion than an exact golden match.
     */
    #[Group('tui-e2e')]
    public function testStartupContainsExpectedElements(): void
    {
        $pane = $this->tmux->startDetached(
            command: 'php bin/console agent --prompt="hello from tmux e2e"; echo; echo "── TUI exited ──"; exec sleep 3600',
            prefix: 'hatfield-startup-elements',
        );

        $capture = $this->tmux->waitForCaptureContains(
            pane: $pane,
            needle: '█',
            timeout: 10.0,
        );

        usleep(500_000);
        $capture = $this->tmux->capturePlain($pane);

        // Send Ctrl+D to exit cleanly
        $this->tmux->sendKey($pane, 'C-d');

        // Key layout elements should be present
        self::assertStringContainsString('█', $capture, 'Hatfield logo (box drawing) missing');
        self::assertStringContainsString('idle', $capture, 'Working status widget missing');
        self::assertStringContainsString('agent-core', $capture, 'Footer widget missing');
        self::assertStringContainsString('session ', $capture, 'Session ID in footer missing');
        self::assertStringContainsString('Welcome', $capture, 'Welcome message missing');
        self::assertStringContainsString('Run started:', $capture, 'Run started message missing');
    }
    // ── helpers ────────────────────────────────────────────

    private function shouldUpdateSnapshots(): bool
    {
        return in_array(getenv('HATFIELD_UPDATE_SNAPSHOTS'), ['1', 'true', 'yes'], true);
    }

    private function diffHint(string $expected, string $actual): string
    {
        $expectedLines = explode("\n", $expected);
        $actualLines = explode("\n", $actual);
        $maxLen = max(count($expectedLines), count($actualLines));

        $diff = [];
        for ($i = 0; $i < $maxLen; $i++) {
            $exp = $expectedLines[$i] ?? '<<< missing >>>';
            $act = $actualLines[$i] ?? '<<< missing >>>';
            if ($exp !== $act) {
                $diff[] = sprintf(
                    '  line %3d: -"%s"',
                    $i + 1,
                    substr($exp, 0, 100),
                );
                $diff[] = sprintf(
                    '           +"%s"',
                    substr($act, 0, 100),
                );
            }
        }

        return implode("\n", $diff);
    }
}
