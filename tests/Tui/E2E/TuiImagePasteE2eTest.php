<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Replay-backed tmux proof: real Ctrl+V inserts [Image #1], submit promotes attachment
 * and canonical prompt contains view_image path (GitHub issue #119).
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
    }

    public function testCtrlVPasteDoesNotBlockEditorWhileClipboardHelperIsSlow(): void
    {
        $this->installFakeWlPaste(delaySeconds: 1);

        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-image-paste-slow',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            $marker = 'PASTE_RESPONSIVE_'.bin2hex(random_bytes(4));
            $this->tmux->sendKey($pane, 'C-v');
            $this->tmux->sendLiteral($pane, ' '.$marker);

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '[Image #1]') && str_contains($cap, $marker),
                timeout: 1.5,
                message: 'Placeholder and typed input must appear before slow clipboard helper finishes (~1s)',
                history: 500,
            );

            // Wait until the delayed fake wl-paste (sleep 1) has finished producing PNG bytes.
            // Elapsed wall time is not the contract; readiness is the fake helper exiting.
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '[Image #1]') && str_contains($cap, $marker),
                timeout: 4.0,
                message: 'Image placeholder must remain visible while delayed clipboard completes',
                history: 500,
            );
            // Bounded poll for no in-flight wl-paste child under the fake bin (process readiness).
            $deadline = microtime(true) + 4.0;
            while (microtime(true) < $deadline) {
                $running = trim((string) shell_exec('pgrep -f '.escapeshellarg($this->fakeBinDir.'/wl-paste').' || true'));
                if ('' === $running) {
                    break;
                }
                usleep(50_000);
            }

            $this->tmux->sendLiteral($pane, ' describe pasted image');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Image paste acknowledged'),
                timeout: 12.0,
                message: 'Expected replay assistant response after slow image paste submit',
                history: 3000,
            );

            $sessionId = $this->resolveSingleCreatedSessionId();
            $this->assertNotNull($sessionId);
            $attachment = $this->testProjectDir.'/.hatfield/sessions/'.$sessionId.'/attachments/pasted-image-1.png';
            $this->assertFileExists($attachment);

            $this->tmux->saveAnsiSnapshot($pane, 'image-paste-slow');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'image-paste-slow-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable $shutdownFailure) {
                // Best-effort graceful shutdown before rethrowing the original assertion failure.
            }
            throw $e;
        }
    }

    public function testCtrlVPastePromotesSessionAttachmentAndCanonicalReference(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-image-paste',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            $this->tmux->sendKey($pane, 'C-v');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '[Image #1]'),
                timeout: 10.0,
                message: '[Image #1] placeholder did not appear after Ctrl+V',
                history: 500,
            );

            $this->tmux->sendLiteral($pane, ' describe pasted image');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Image paste acknowledged'),
                timeout: 12.0,
                message: 'Expected replay assistant response after image paste submit',
                history: 3000,
            );

            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 3000);
            $this->assertStringContainsString('view_image', $fullCapture);

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
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'image-paste-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable $shutdownFailure) {
                // Best-effort graceful shutdown before rethrowing the original assertion failure.
            }
            throw $e;
        }
    }

    private function installFakeWlPaste(int $delaySeconds = 0): void
    {
        @mkdir($this->fakeBinDir, 0o777, true);
        $png = __DIR__.'/fixtures/paste-test-1x1.png';
        $delay = $delaySeconds > 0 ? 'sleep '.(int) $delaySeconds.'
' : '';
        $script = '#!/bin/sh'."\n".$delay.'cat '.escapeshellarg($png)."\n";
        file_put_contents($this->fakeBinDir.'/wl-paste', $script);
        chmod($this->fakeBinDir.'/wl-paste', 0o755);
    }

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-image-paste-response.json';
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-image-paste');
        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $pathPrefix = 'PATH='.escapeshellarg($this->fakeBinDir.':'.getenv('PATH')).' ';
        $wayland = 'XDG_SESSION_TYPE=wayland WAYLAND_DISPLAY=wayland-test ';

        return \sprintf(
            'APP_ENV=test %s%s%sHOME=%s %s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            $pathPrefix,
            $wayland,
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-paste');
        TestDirectoryIsolation::createHatfieldTree($dir, withSessions: true, permissions: 0o777);

        $settings = TuiE2eDatabaseEnv::replayBaseSettings();

        TestDirectoryIsolation::createHatfieldTree($dir.'/home', withSessions: true, permissions: 0o777);
        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }

    private function resolveSingleCreatedSessionId(): ?string
    {
        $sessionsRoot = $this->testProjectDir.'/.hatfield/sessions';
        if (!is_dir($sessionsRoot)) {
            return null;
        }

        $dirs = array_values(array_filter(scandir($sessionsRoot) ?: [], static fn (string $entry): bool => !\in_array($entry, ['.', '..'], true) && is_dir($sessionsRoot.'/'.$entry)));
        if (1 !== \count($dirs)) {
            return null;
        }

        return $dirs[0];
    }
}
