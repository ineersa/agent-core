<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Replay-backed tmux proof: real Ctrl+V inserts [Image #1], submit promotes
 * attachment, and canonical events contain the view_image path (GitHub #119).
 *
 * Non-blocking paste while clipboard helpers are slow is covered by
 * ImagePasteInputVirtualTest + ClipboardImageReaderTest.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiImagePasteE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $fakeBinDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->fakeBinDir = $this->testProjectDir.'/fake-bin';
        $this->installFakeWlPaste();
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

    public function testCtrlVPastePromotesSessionAttachmentAndCanonicalReference(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-image-paste',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            $this->tmux->sendKey($pane, 'C-v');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '[Image #1]'),
                timeout: 8.0,
                message: '[Image #1] placeholder did not appear after Ctrl+V',
                history: 500,
            );

            $this->tmux->sendLiteral($pane, ' describe pasted image');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Image paste acknowledged'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Expected replay assistant response after image paste submit',
                history: 2000,
            );

            $sessionId = $this->resolveSingleCreatedSessionId();
            $this->assertNotNull($sessionId);

            $eventsPath = $this->testProjectDir.'/.hatfield/sessions/'.$sessionId.'/events.jsonl';
            $this->assertFileExists($eventsPath);
            $events = file_get_contents($eventsPath);
            $this->assertNotFalse($events);
            $this->assertStringContainsString('view_image', $events);
            $this->assertStringContainsString('pasted-image-1.png', $events);

            $attachment = $this->testProjectDir.'/.hatfield/sessions/'.$sessionId.'/attachments/pasted-image-1.png';
            $this->assertFileExists($attachment);

            $this->tmux->saveAnsiSnapshot($pane, 'image-paste');
            $this->tmux->sendKey($pane, 'C-d');
            $this->tmux->waitUntilPaneExits($pane, 10.0);
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'image-paste-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function installFakeWlPaste(): void
    {
        @mkdir($this->fakeBinDir, 0o777, true);
        $png = __DIR__.'/fixtures/paste-test-1x1.png';
        $script = '#!/bin/sh'."\n".'cat '.escapeshellarg($png)."\n";
        file_put_contents($this->fakeBinDir.'/wl-paste', $script);
        chmod($this->fakeBinDir.'/wl-paste', 0o755);
    }

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-image-paste-response.json';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-image-paste');

        return \sprintf(
            'APP_ENV=test PATH=%s XDG_SESSION_TYPE=wayland WAYLAND_DISPLAY=wayland-test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            escapeshellarg($this->fakeBinDir.':'.(getenv('PATH') ?: '')),
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixturePath),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($this->projectRoot.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-paste');
        TestDirectoryIsolation::createHatfieldTree($dir, withSessions: true, permissions: 0o777);
        TestDirectoryIsolation::createHatfieldTree($dir.'/home', withSessions: true, permissions: 0o777);
        TuiE2eDatabaseEnv::writeReplaySettings($dir, TuiE2eDatabaseEnv::replayBaseSettings());

        return $dir;
    }

    private function resolveSingleCreatedSessionId(): ?string
    {
        $sessionsRoot = $this->testProjectDir.'/.hatfield/sessions';
        if (!is_dir($sessionsRoot)) {
            return null;
        }

        $dirs = array_values(array_filter(
            scandir($sessionsRoot) ?: [],
            static fn (string $entry): bool => !\in_array($entry, ['.', '..'], true)
                && is_dir($sessionsRoot.'/'.$entry),
        ));
        if (1 !== \count($dirs)) {
            return null;
        }

        return $dirs[0];
    }
}
