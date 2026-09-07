<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\E2E\Support\TuiE2eSessionCatalogSeeder;
use Ineersa\Tui\Widget\SelectListKeybindings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal tmux proof for an overheight /resume picker open → navigate → close.
 *
 * Virtual tests own label/accent and differential confirm contracts. This case
 * only proves a real finite pane settles picker focus and chrome.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiResumePickerOverlayE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;

    /** @var array{app: string, transport: string, appEnv: string, transportEnv: string}|null */
    private ?array $dbPaths = null;

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
        // Keep snapshot artifacts under the isolated project tree for inspection.
        // Intentionally do not removeDirectory() here.
    }

    public function testResumePickerOpenNavigateCloseSettlesVisibleChrome(): void
    {
        $paths = $this->dbPaths ?? $this->fail('DB paths must be allocated before seeding');

        // One more than maxVisible so the list emits a scroll indicator and
        // exceeds a short pane without depending on scrollback leftovers.
        $sessionCount = SelectListKeybindings::MAX_VISIBLE + 1;
        $sessionIds = [];
        for ($i = 1; $i <= $sessionCount; ++$i) {
            $sessionIds[] = TuiE2eSessionCatalogSeeder::createSession(
                $this->testProjectDir,
                $paths['appEnv'],
                $paths['transportEnv'],
                \sprintf('seeded resume picker session %02d', $i),
            );
        }

        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-resume-picker',
            width: 120,
            height: 24,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/resume');
            $this->tmux->sendKey($pane, 'Enter');

            $opened = $this->tmux->waitForCaptureContains(
                $pane,
                'Resume session — arrows move, Enter resumes, d deletes, Esc cancels',
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Resume picker header did not appear',
            );
            $this->assertStringContainsString('#'.$sessionIds[0], $opened);
            $this->assertStringContainsString('(1/'.$sessionCount.')', $opened);

            $this->tmux->sendKey($pane, 'Down');
            $this->tmux->sendKey($pane, 'Down');

            $selected = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '(3/'.$sessionCount.')'),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Resume picker selection must reach index 3 before Escape',
                history: 0,
            );
            $this->assertStringContainsString('#'.$sessionIds[2], $selected);
            $this->assertStringContainsString('(3/'.$sessionCount.')', $selected);

            $this->tmux->sendKey($pane, 'Escape');

            $closed = $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    if (str_contains($cap, 'arrows move, Enter resumes')) {
                        return false;
                    }

                    return str_contains($cap, '● idle')
                        && str_contains($cap, '◆')
                        && (str_contains($cap, 'Welcome to Hatfield')
                            || str_contains($cap, 'Welcome to Agent Core'));
                },
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Resume picker must close and restore focused editor chrome in the current pane',
                history: 0,
            );

            $this->assertStringContainsString('● idle', $closed);
            $this->assertStringContainsString('◆', $closed);
            $this->assertTrue(
                str_contains($closed, 'Welcome to Hatfield')
                || str_contains($closed, 'Welcome to Agent Core'),
                'Closed pane must show the welcome/editor body again',
            );
            $this->assertStringNotContainsString('arrows move, Enter resumes', $closed);

            $this->tmux->saveAnsiSnapshot($pane, 'resume-picker-overlay');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'resume-picker-overlay-FAILURE');
            // tearDown() kills the tmux tree; do not swallow secondary exit errors.
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $paths = $this->dbPaths ?? $this->fail('DB paths must be allocated before building agent command');

        // Source bin/console with APP_ENV=test (same as other journey/snapshot
        // cases). Packaged PHAR boot is owned by TuiArtifactBootE2eTest.
        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefixWithLowLatencyMessenger(
                $paths['appEnv'],
                $paths['transportEnv'],
                $this->testProjectDir,
            ),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(ProjectDir::get().'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e');
        @mkdir($dir.'/.hatfield', 0o777, true);

        $allocated = TuiE2eDatabaseEnv::allocateIsolatedPaths(
            ProjectDir::get(),
            $dir,
            'tui-resume-picker-',
        );
        $this->dbPaths = [
            'app' => $allocated['app'],
            'transport' => $allocated['transport'],
            'appEnv' => $allocated['appEnv'],
            'transportEnv' => $allocated['transportEnv'],
        ];

        $settings = TuiE2eDatabaseEnv::replayBaseSettings();
        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }
}
