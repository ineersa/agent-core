<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal real-terminal proof that a prebuilt/installed Hatfield artifact
 * boots the interactive TUI from an isolated directory.
 *
 * Uses HATFIELD_BINARY_PATH (PHAR or native) or worktree-local built PHAR.
 * No live LLM / no prompt — boot + logo + clean Ctrl+D exit only.
 *
 * Hard requirement: a real packaged artifact must be present. Castor
 * `test:tui` ensures the PHAR via phar:ensure when filtering this test /
 * when HATFIELD_REQUIRE_ARTIFACT is set for the gate.
 */
#[Group('tui-e2e-replay')]
#[Group('phar')]
final class TuiArtifactBootE2eTest extends TestCase
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

    public function testInstalledArtifactBootsTuiInRealTerminal(): void
    {
        $binary = $this->resolvePackagedArtifactPath();
        $this->assertNotNull(
            $binary,
            'No packaged Hatfield artifact found. Expected var/tmp/phar/hatfield.phar, '
            .'var/tmp/dist/hatfield.*, or HATFIELD_BINARY_PATH / HATFIELD_NATIVE_BINARY_PATH. '
            .'Run: castor phar:build (Castor test:tui now ensures this for artifact filters).',
        );

        // Ensure child command sees an absolute packaged path.
        putenv('HATFIELD_BINARY_PATH='.$binary);
        $_ENV['HATFIELD_BINARY_PATH'] = $binary;

        $pane = $this->tmux->startDetached(
            command: $this->artifactAgentCommand($binary),
            prefix: 'hatfield-artifact-boot',
            cwd: $this->testProjectDir,
        );

        $this->tmux->waitForCaptureContains(
            pane: $pane,
            needle: '█',
            timeout: TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL,
        );

        $capture = $this->tmux->capturePlain($pane);
        $this->assertStringContainsString('█', $capture, 'Hatfield logo missing when launching artifact TUI');

        $this->tmux->sendKey($pane, 'C-d');
        // Prove the packaged process exits on Ctrl+D; tearDown killAll remains fallback.
        $this->tmux->waitUntilPaneExits($pane, 10.0);
        $this->assertFalse(
            $this->tmux->paneExists($pane),
            'Packaged artifact TUI pane still alive after Ctrl+D; expected clean process exit',
        );
    }

    private function artifactAgentCommand(string $resolved): string
    {
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-artifact-');
        $dbPath = $paths['app'];
        $transportDbPath = $paths['transport'];

        // PHAR needs php runner; fused native is self-executing.
        $isPhar = str_ends_with($resolved, '.phar');
        $launch = $isPhar
            ? escapeshellarg(\PHP_BINARY).' '.escapeshellarg($resolved)
            : escapeshellarg($resolved);

        // Production artifact: APP_ENV=prod (no test DI). No prompt — boot smoke only.
        return \sprintf(
            'APP_ENV=prod APP_DEBUG=0 %sHOME=%s %s agent --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            $launch,
        );
    }

    /**
     * Absolute path to a packaged hatfield artifact, or null when unset/source-only.
     */
    private function resolvePackagedArtifactPath(): ?string
    {
        $root = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
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

        // Default worktree-local PHAR / dist artifacts when present.
        $candidates[] = $root.'/var/tmp/phar/hatfield.phar';
        $candidates[] = $root.'/var/tmp/dist/hatfield.phar';
        $candidates[] = $root.'/var/tmp/dist/hatfield.linux-amd64';
        $candidates[] = $root.'/var/tmp/dist/hatfield.linux-arm64';
        $candidates[] = $root.'/var/tmp/dist/hatfield.darwin-amd64';
        $candidates[] = $root.'/var/tmp/dist/hatfield.darwin-arm64';

        foreach ($candidates as $binary) {
            if (!str_starts_with($binary, '/')) {
                $binary = $root.'/'.$binary;
            }
            $resolved = realpath($binary);
            if (false === $resolved || !is_file($resolved)) {
                continue;
            }
            // Reject plain source console — this test is about packaged artifacts.
            if (str_ends_with($resolved, '/bin/console')) {
                continue;
            }
            $base = basename($resolved);
            if (!str_contains($base, 'hatfield')) {
                continue;
            }

            return $resolved;
        }

        return null;
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-artifact', 0o777);
        TestDirectoryIsolation::createHatfieldTree($dir, withSessions: true);
        TestDirectoryIsolation::ensureDirectory($dir.'/home/.hatfield');
        file_put_contents(
            $dir.'/home/.hatfield/settings.yaml',
            "ai:\n  default_model: null\n",
        );

        return $dir;
    }
}
