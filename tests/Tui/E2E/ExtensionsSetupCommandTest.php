<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Fixture retention and diagnostics only.
 *
 * User-authorized deletion removed
 * testRegisteredCommandInstallsConfiguresAndExitsThroughRealTerminalInput.
 * Lost coverage: packaged PHAR extensions:setup end-to-end for Composer/path
 * install, Project/User scope switching, settings persistence, model picker,
 * Unicode/inline YAML editing, copied-bootstrap retention, and restart refusal
 * after an enabled older user loader. No replacement journey was added.
 */
#[Group('tui-e2e-replay')]
final class ExtensionsSetupCommandTest extends TestCase
{
    public function testFixtureIsRetainedUntilShutdownSucceeds(): void
    {
        $temporary = TestDirectoryIsolation::createProjectTempDir('extension-setup-retention');
        try {
            file_put_contents($temporary.'/stderr.log', 'synthetic shutdown evidence');
            $this->removeFixtureAfterShutdown($temporary, new \RuntimeException('synthetic shutdown refusal'));
            $this->assertDirectoryExists($temporary);
            $this->assertSame('synthetic shutdown evidence', file_get_contents($temporary.'/stderr.log'));

            $this->removeFixtureAfterShutdown($temporary, null);
            $this->assertDirectoryDoesNotExist($temporary);
        } finally {
            TestDirectoryIsolation::removeDirectory($temporary);
        }
    }

    public function testFailureDiagnosticsRetainOwnedPaneEvidence(): void
    {
        $temporary = TestDirectoryIsolation::createProjectTempDir('extension-setup-diagnostics');
        $tmux = new TmuxHarness();
        $tmux->setSnapshotDir($temporary);
        $stderr = $temporary.'/stderr.log';
        $pane = null;
        try {
            $pane = $tmux->startDetached('exec '.escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg(
                'fwrite(STDERR, "synthetic startup diagnostic\n"); fwrite(STDOUT, "diagnostics ready pid=".getmypid()."\n"); fread(STDIN, 1);'
            ).' 2>'.escapeshellarg($stderr), prefix: 'extension-setup-diagnostics');
            $tmux->waitForCaptureContains($pane, 'diagnostics ready', 3);
            $tmux->saveFailureDiagnostics($pane, $stderr);

            $reports = (string) getenv('HATFIELD_QA_REPORTS_DIR');
            if (!str_starts_with($reports, '/')) {
                $reports = ProjectDir::get().'/'.$reports;
            }
            $diagnostics = $reports.'/diagnostics/'.basename($temporary);
            $this->assertSame("synthetic startup diagnostic\n", file_get_contents($diagnostics.'/stderr.log'));
            $this->assertStringContainsString('diagnostics ready', file_get_contents($diagnostics.'/failure.txt'));
            $this->assertCount(1, glob($diagnostics.'/failure-*.ansi'));
            $processes = json_decode(file_get_contents($diagnostics.'/processes.json'), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame($pane->paneId, $processes['pane']);
            $pid = $tmux->panePid($pane);
            $this->assertSame($pid, $processes['pane_pid']);
            $this->assertStringContainsString('pid='.$pid.' dead=0', $processes['pane_state']);
            $this->assertIsBool($processes['pane_proc_readable']);
            $this->assertIsArray($processes['processes']);
        } finally {
            try {
                if (null !== $pane && $tmux->paneExists($pane)) {
                    $tmux->sendKey($pane, 'C-d');
                    $tmux->waitUntilPaneExits($pane, 3);
                }
            } finally {
                $tmux->killAll();
                TestDirectoryIsolation::removeDirectory($temporary);
            }
        }
    }

    private function removeFixtureAfterShutdown(string $temporary, ?\Throwable $shutdownFailure): void
    {
        if (null !== $shutdownFailure) {
            fwrite(\STDERR, 'Retaining extension setup fixture after failed shutdown: '.$temporary.\PHP_EOL);

            return;
        }
        TestDirectoryIsolation::removeDirectory($temporary);
    }
}
