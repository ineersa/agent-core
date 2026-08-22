<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal tmux proof for /reload process bootstrap:
 * same pane PID, synchronous old-controller teardown, fresh session-owner lock,
 * and resume of the SAME session to idle.
 *
 * Slash-command guards live in ReloadCommandHandlerTest.
 * Argv rebuild lives in ReloadArgvBuilderTest.
 * Output-cap rendering lives in TuiOutputCapNoticeE2eTest.
 * This class only covers the real process-reload loop that those layers cannot.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiReloadSettingsE2eTest extends TestCase
{
    private const string REPLAY_PROMPT = 'Respond with exactly one sentence: the sky is blue.';

    private TmuxHarness $tmux;
    private string $testProjectDir;

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

    public function testReloadKeepsProcessAndResumesSameSession(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-reload',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, self::REPLAY_PROMPT);
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

                    return str_contains($cap, '● idle');
                },
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Assistant block, session id, and idle must appear before /reload',
                history: 2000,
            );
            $this->assertNotEmpty($sessionId);

            $this->tmux->waitForCallback(
                $pane,
                fn (string $_): bool => str_contains($this->readAgentLog(), 'controller.session_owner_lock_acquired'),
                timeout: 8.0,
                message: 'First controller must acquire the session-owner lock',
                history: 0,
            );

            $pidBefore = $this->tmux->panePid($pane);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/reload');
            $this->tmux->sendKey($pane, 'Enter');

            $reloaded = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Resumed run')
                    && str_contains($cap, '● idle')
                    && str_contains($cap, 'session '.$sessionId),
                timeout: TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL,
                message: 'Reload must resume the same session and reach idle',
                history: 2000,
            );

            $this->assertSame($pidBefore, $this->tmux->panePid($pane), 'Reload must keep the same process');
            $this->assertStringContainsString('session '.$sessionId, $reloaded);

            $this->tmux->waitForCallback(
                $pane,
                fn (string $_): bool => 1 <= mb_substr_count($this->readAgentLog(), 'Controller shutting down gracefully')
                    && 2 <= mb_substr_count($this->readAgentLog(), 'controller.session_owner_lock_acquired'),
                timeout: 8.0,
                message: 'Reload must shut down the old controller and acquire a fresh session-owner lock',
                history: 0,
            );
            $this->assertStringNotContainsString(
                'controller.session_owner_lock_conflict',
                $this->readAgentLog(),
                'No controller overlap may occur during reload',
            );

            $this->tmux->saveAnsiSnapshot($pane, 'reload-settings');
            $this->tmux->sendKey($pane, 'C-d');
            $this->tmux->waitUntilPaneExits($pane, 10.0);
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'reload-settings-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function readAgentLog(): string
    {
        $content = '';
        foreach (glob($this->testProjectDir.'/.hatfield/logs/agent*.log') ?: [] as $path) {
            $chunk = @file_get_contents($path);
            if (false !== $chunk) {
                $content .= $chunk;
            }
        }

        return $content;
    }

    private function agentCommand(): string
    {
        $projectDir = ProjectDir::get();
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-reload-');

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg(__DIR__.'/fixtures/tui-simple-text-response.json'),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($projectDir.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-reload');
        TestDirectoryIsolation::createHatfieldTree($dir, withSessions: true, permissions: 0o777);
        TestDirectoryIsolation::createHatfieldTree($dir.'/home', withSessions: true, permissions: 0o777);
        TuiE2eDatabaseEnv::writeReplaySettings($dir, TuiE2eDatabaseEnv::replayBaseSettings());

        return $dir;
    }
}
