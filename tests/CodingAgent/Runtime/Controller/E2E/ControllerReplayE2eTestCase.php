<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay\ControllerReplaySourceMessengerConsole;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\SessionSqliteQueueTestEnv;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Assert;

/** Offline source-controller harness for protocol and process lifecycle tests. */
abstract class ControllerReplayE2eTestCase extends ControllerE2eTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = ProjectDir::get();

        // Ephemeral replay E2E runs must not use all-digit ids: SessionAwareModelResolver treats
        // ctype_digit session ids as persisted Hatfield sessions and fails when metadata is missing.
        $this->sessionId = 'e2e-'.substr(bin2hex(random_bytes(16)), 0, 12);
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir($this->tempDirPrefix());

        $this->createIsolatedProjectDir();

        $this->process = null;
        $this->pipes = [];
        $this->stdoutBuf = '';
        $this->stderrBuf = '';
        $this->runId = '';
        $this->trackedControllerPids = [];
    }

    protected function tearDown(): void
    {
        $this->stopProcess();
        // Keep failing isolated controller artifacts when requested.
        // Parent tearDown always removes tempDir; clear it first when keeping
        // so the parent lifecycle cannot delete retained artifacts.
        $keepTmp = false !== getenv('HATFIELD_KEEP_TMP') && '' !== getenv('HATFIELD_KEEP_TMP');
        if (!$keepTmp && isset($this->tempDir) && '' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
        $this->tempDir = '';
        parent::tearDown();
    }

    abstract protected function tempDirPrefix(): string;

    /**
     * @return array<string, string> extra env vars passed to the
     *                               controller subprocess (added to the replay-aware defaults)
     */
    protected function replayExtraEnv(): array
    {
        return [];
    }

    // ── Process lifecycle with ownership ─────────────────────────

    /** Start the source controller with the isolated test container. */
    protected function spawnController(): void
    {
        // Controller replay E2E MUST use the source bin/console, not a
        // PHAR: the controller boots with APP_ENV=test which loads
        // config/packages/test/ bundles (e.g. DAMA\DoctrineTestBundle).
        // These test-only bundles are not included in the PHAR.
        $php = \PHP_BINARY;
        $projectDir = ProjectDir::get();
        $script = $projectDir.'/bin/console';
        Assert::assertFileExists(
            $script, 'Agent executable not found at '.$script,
        );

        // ConsumerSupervisor relaunches messenger:consume via HATFIELD_BINARY_PATH.
        // Point it at a test-only source wrapper that preserves omitted --sleep so
        // sqlite-queue native WAIT notifications arm like production. Controller
        // itself still boots from source bin/console below.
        $messengerConsole = ControllerReplaySourceMessengerConsole::install(
            $this->tempDir,
            $script,
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            // Hermetic home: skill materialization and user settings must not touch
            // the real developer HOME (proc_open env arrays do not inherit).
            'HOME' => $this->tempDir,
            // Give the subprocess its own isolated SQLite DB so
            // migrations run fresh (the parent PHPUnit migrated the
            // shared test DB already).  The path is relative to
            // %kernel.project_dir%/var/test/ per config/packages/test/doctrine.yaml.
            'HATFIELD_CONTROLLER_TEST_DATABASE_PATH' => $this->tempDir.'/app_test-replay-'.$this->sessionId.'.sqlite',
            // The test wrapper sets $_SERVER identity without tagging signal-owned PIDs.
            'HATFIELD_REPLAY_SESSION_ID' => $this->sessionId,
            ...SessionSqliteQueueTestEnv::forSession(
                $this->tempDir.'/.hatfield/sessions',
                $this->sessionId,
            ),
            // Inherited by ConsumerSupervisor children; ConfigExecutableLocator wins.
            'HATFIELD_BINARY_PATH' => $messengerConsole,
        ];

        $env = array_merge($env, $this->controllerSubprocessEnv());

        // Merge subclass extras.
        foreach ($this->replayExtraEnv() as $k => $v) {
            $env[$k] = $v;
        }

        $pipes = [];

        $process = @proc_open(
            array_merge(
                // setsid -w: proc_open child becomes session/PGID leader.
                ['setsid', '-w', $php, $messengerConsole, 'agent', '--controller', '--cwd='.$this->tempDir],
                $this->controllerExtraArgs(),
            ),
            $descriptors,
            $pipes,
            $this->tempDir,
            $env,
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('Failed to spawn controller process.');
        }

        $this->process = $process;
        $this->pipes = $pipes;

        stream_set_blocking($pipes[0], true);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->sqliteQueueDatabasePath = (string) ($env['HATFIELD_SQLITE_QUEUE_DATABASE'] ?? '');
        $this->trackControllerProcessTree($process);
    }

    protected function collectDiagnostics(array $events): string
    {
        $parentDiag = parent::collectDiagnostics($events);

        $ownershipLines = [
            'Tracked PIDs: '.('' !== implode(', ', $this->trackedControllerPids)
                ? implode(', ', $this->trackedControllerPids) : '(none)'),
        ];

        return $parentDiag."\n".implode("\n", $ownershipLines)."\n";
    }
}
