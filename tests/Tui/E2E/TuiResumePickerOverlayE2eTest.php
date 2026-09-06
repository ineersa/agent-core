<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\E2E\Support\TuiE2eSessionCatalogSeeder;
use Ineersa\Tui\Tests\Support\ResumeCanonicalEventsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal tmux proof for overheight /resume picker open → navigate → close.
 *
 * Virtual tests own label/accent and force-reset contracts. This case only
 * proves the packaged TUI settles picker chrome without stranded overlay text.
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
        if (isset($this->testProjectDir)) {
            TestDirectoryIsolation::removeDirectory($this->testProjectDir);
        }
    }

    public function testResumePickerOpenNavigateCloseSettlesVisibleChrome(): void
    {
        $paths = $this->dbPaths ?? $this->fail('DB paths must be allocated before seeding');

        $sessionIds = [];
        for ($i = 1; $i <= 12; ++$i) {
            $sessionIds[] = TuiE2eSessionCatalogSeeder::createSession(
                $this->testProjectDir,
                $paths['appEnv'],
                $paths['transportEnv'],
                \sprintf('seeded resume picker session %02d', $i),
            );
        }
        $targetId = $sessionIds[array_key_last($sessionIds)];
        ResumeCanonicalEventsFixture::write($this->testProjectDir, $targetId);

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
            $this->assertStringContainsString('(1/12)', $opened);

            $this->tmux->sendKey($pane, 'Down');
            $this->tmux->sendKey($pane, 'Down');
            $this->tmux->sendKey($pane, 'Escape');

            $closed = $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    if (str_contains($cap, 'arrows move, Enter resumes')) {
                        return false;
                    }

                    return str_contains($cap, '█') && str_contains($cap, '◆');
                },
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Resume picker must close without leaving header text and must keep chrome',
                history: 2000,
            );

            $this->assertStringContainsString('█', $closed);
            $this->assertStringContainsString('◆', $closed);
            $this->assertStringNotContainsString('arrows move, Enter resumes', $closed);

            $this->tmux->saveAnsiSnapshot($pane, 'resume-picker-overlay');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'resume-picker-overlay-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $paths = $this->dbPaths ?? $this->fail('DB paths must be allocated before building agent command');

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
