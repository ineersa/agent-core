<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\LargeResumeCanonicalEventsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Packaged PHAR boot/cap integration for a substantial synthetic resume under 128M.
 *
 * Seeds an isolated APP_ENV=prod project (paired DBs + HOME), writes a synthetic
 * canonical transcript (~220 steps), then boots the worktree PHAR with
 * memory_limit=128M --resume=<id>. This is not the user session-2 OOM reproduction;
 * that archive is larger and proved separately. Lowest-layer sharing regression:
 * {@see \Ineersa\Tui\Tests\Transcript\LargeResumeRenderMemoryTest}.
 */
#[Group('tui-e2e-replay')]
#[Group('phar')]
final class TuiLargeResumePharMemoryE2eTest extends TestCase
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

    public function testPackagedPharResumesLargeTranscriptUnderStrict128M(): void
    {
        $binary = $this->resolvePackagedArtifactPath();
        $this->assertNotNull(
            $binary,
            'No packaged Hatfield artifact found. Expected var/tmp/phar/hatfield.phar or HATFIELD_BINARY_PATH.',
        );

        // Fixed positive-digit orphan session: prod PHAR startup migrates schema and
        // SessionCatalogRecoveryService inserts the hatfield_session row from events.jsonl.
        $sessionId = '42';
        LargeResumeCanonicalEventsFixture::write($this->testProjectDir, $sessionId);

        $pane = $this->tmux->startDetached(
            command: $this->artifactResumeCommand($binary, $sessionId),
            prefix: 'hatfield-large-resume-phar',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        try {
            $visible = $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    return str_contains($cap, 'MEMORY_MARKER_LATE')
                        && (str_contains($cap, '● idle') || str_contains($cap, '◐ Work') || str_contains($cap, '█'));
                },
                timeout: TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL,
                message: 'Packaged resume must show late retained marker and active chrome under 128M',
                history: 4000,
            );

            $this->assertStringContainsString('MEMORY_MARKER_LATE', $visible);
            $history = $this->tmux->capturePlainWithHistory($pane, 4000);
            $this->assertStringContainsString(
                'MEMORY_MARKER_EARLY',
                $history,
                'Native scrollback must still contain the early retained marker',
            );
            // paneExists alone can be true for remain-on-exit dead panes; require pane_dead=0.
            $this->assertTrue(
                $this->tmux->isPaneLive($pane),
                'PHAR pane must remain live (pane_dead=0) after first resume frame',
            );
            $this->assertGreaterThan(0, $this->tmux->panePid($pane), 'PHAR pane_pid must still resolve');

            $this->tmux->sendKey($pane, 'C-d');
            $this->tmux->waitUntilPaneExits($pane, 10.0);
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'large-resume-phar-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable $cleanupError) {
                // Intentional local degradation: failure path already owns the primary
                // exception; Ctrl+D best-effort cleanup must not replace it.
                fwrite(
                    \STDERR,
                    'large-resume-phar cleanup Ctrl+D ignored: '.$cleanupError::class.': '.$cleanupError->getMessage()."\n",
                );
            }
            throw $e;
        }
    }

    private function artifactResumeCommand(string $resolved, string $sessionId): string
    {
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-large-resume-');
        $isPhar = str_ends_with($resolved, '.phar');
        $launch = $isPhar
            ? escapeshellarg(\PHP_BINARY).' -d memory_limit=128M '.escapeshellarg($resolved)
            : escapeshellarg($resolved);

        // Production artifact: APP_ENV=prod. Session/events live under isolated CWD.
        return \sprintf(
            'APP_ENV=prod APP_DEBUG=0 %sHOME=%s HATFIELD_BINARY_PATH=%s %s --resume=%s --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($resolved),
            $launch,
            escapeshellarg($sessionId),
        );
    }

    private function resolvePackagedArtifactPath(): ?string
    {
        $root = ProjectDir::get();
        $candidates = [];
        foreach (['HATFIELD_BINARY_PATH', 'HATFIELD_ARTIFACT_PATH', 'HATFIELD_NATIVE_BINARY_PATH'] as $envName) {
            $value = getenv($envName);
            if (false === $value || '' === trim((string) $value)) {
                $value = $_ENV[$envName] ?? $_SERVER[$envName] ?? null;
            }
            if (\is_string($value) && '' !== trim($value)) {
                $candidates[] = trim($value);
            }
        }
        $candidates[] = $root.'/var/tmp/phar/hatfield.phar';
        $candidates[] = $root.'/var/tmp/dist/hatfield.phar';

        foreach ($candidates as $binary) {
            if (!str_starts_with($binary, '/')) {
                $binary = $root.'/'.$binary;
            }
            $resolved = realpath($binary);
            if (false === $resolved || !is_file($resolved) || str_ends_with($resolved, '/bin/console')) {
                continue;
            }
            if (!str_contains(basename($resolved), 'hatfield')) {
                continue;
            }

            return $resolved;
        }

        return null;
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-large-resume-phar', 0o777);
        TestDirectoryIsolation::createHatfieldTree($dir, withSessions: true);
        TestDirectoryIsolation::ensureDirectory($dir.'/home/.hatfield');
        file_put_contents(
            $dir.'/home/.hatfield/settings.yaml',
            "ai:\n  default_model: null\n",
        );
        file_put_contents(
            $dir.'/.hatfield/settings.yaml',
            "ai:\n  default_model: null\n",
        );

        return $dir;
    }
}
