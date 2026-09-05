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
 * Minimal tmux lifecycle proof for /resume direct repaint.
 *
 * Seeds a real hatfield_session row + canonical events without an LLM turn.
 * Canonical block reconstruction remains virtual-owned
 * ({@see \Ineersa\Tui\Tests\Screen\TuiResumeSessionVirtualTest}).
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiResumeSessionSwitchE2eTest extends TestCase
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
        // Controller/session children can briefly hold files under the isolated
        // tree after pane exit; remove only after tmux sessions are gone.
        if (isset($this->testProjectDir)) {
            TestDirectoryIsolation::removeDirectory($this->testProjectDir);
        }
    }

    public function testResumeRepaintsSelectedSessionInVisiblePane(): void
    {
        $paths = $this->dbPaths ?? $this->fail('DB paths must be allocated before seeding');
        $sessionId = TuiE2eSessionCatalogSeeder::createSession(
            $this->testProjectDir,
            $paths['appEnv'],
            $paths['transportEnv'],
            'Tell me about testing.',
        );
        ResumeCanonicalEventsFixture::write($this->testProjectDir, $sessionId);

        $pane = $this->startPane('tui-resume-repaint');

        try {
            $this->tmux->waitForTuiReady($pane);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');

            $visiblePane = $this->tmux->waitForCallback(
                $pane,
                static function (string $cap) use ($sessionId): bool {
                    if (!str_contains($cap, $sessionId)) {
                        return false;
                    }
                    if (!str_contains($cap, 'Here is the answer you requested.')) {
                        return false;
                    }
                    if (!str_contains($cap, '█') || !str_contains($cap, '◆')) {
                        return false;
                    }

                    return str_contains($cap, '● idle') || str_contains($cap, '◐ Work');
                },
                timeout: TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL,
                message: 'Resumed pane must show target session id, canonical fixture text, chrome, and active status',
                history: 2500,
            );

            $this->assertStringContainsString($sessionId, $visiblePane);
            $this->assertStringContainsString('Here is the answer you requested.', $visiblePane);
            $this->assertStringContainsString('█', $visiblePane);
            $this->assertStringContainsString('◆', $visiblePane);
            $this->assertTrue(
                str_contains($visiblePane, '● idle') || str_contains($visiblePane, '◐ Work'),
                'Resumed session must show active TUI status',
            );

            $this->tmux->saveAnsiSnapshot($pane, 'resume-repaint');
            $this->tmux->sendKey($pane, 'C-d');
            // Prove clean exit before tearDown removeDirectory races leftover writers.
            $this->tmux->waitUntilPaneExits($pane, 10.0);
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'resume-repaint-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
                $this->tmux->waitUntilPaneExits($pane, 10.0);
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function startPane(string $prefix): TmuxPane
    {
        return $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: $prefix,
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );
    }

    private function agentCommand(): string
    {
        $paths = $this->dbPaths ?? $this->fail('DB paths must be allocated before building agent command');

        // Draft boot only — no LLM fixture/env; resume loads seeded canonical events.
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
            'tui-resume-',
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
