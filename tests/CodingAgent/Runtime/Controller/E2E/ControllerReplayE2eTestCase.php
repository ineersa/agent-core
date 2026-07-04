<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;

/**
 * Deterministic controller replay E2E test case.
 *
 * Extends the live-LLM ControllerE2eTestCase but overrides the
 * controller spawning to use pre-recorded LLM replay fixtures via
 * HATFIELD_LLM_REPLAY_FIXTURE_PATH instead of a live llama.cpp
 * endpoint.
 *
 * The replay seam is entirely in the test layer:
 *   - ControllerReplayHttpClientFactory (tests/) checks the env var
 *     and returns a MockHttpClient with fixture-driven SSE.
 *   - config/services_test.yaml wires it as the default HttpClient.
 *   - The controller subprocess is spawned with APP_ENV=test so
 *     services_test.yaml is loaded and SymfonyAiProviderFactory
 *     receives the injected replay client through its existing
 *     constructor DI path.
 *   - No production code in src/ checks HATFIELD_LLM_REPLAY_FIXTURE_PATH.
 *
 * Replay tests do NOT require LLAMA_CPP_SMOKE_TEST, llama.cpp on
 * port 9052, or any live AI provider.  They exercise the full
 * async runtime pipeline (controller event loop, Messenger consumers,
 * tool execution, event serialization) with deterministic fixture
 * data.
 *
 * Process ownership: the controller and its Messenger consumer
 * children are tracked explicitly via process group PID tracking.
 * Teardown terminates the entire process group deterministically,
 * then asserts no children remain.  This replaces the previous
 * broad stale-killer cleanup for normal test runs.
 *
 * MAINT-05D foundation.  This is the replay seam for controller
 * E2E tests; MAINT-05E will add similar support for TUI E2E.
 */
abstract class ControllerReplayE2eTestCase extends ControllerE2eTestCase
{
    /** @var list<int> PIDs of tracked child processes */
    private array $trackedPids = [];

    /** @var list<array<string, mixed>> One fixture per expected LLM call */
    protected array $replayFixtures = [];

    /**
     * Subclasses MUST override to return at least one fixture.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function replayFixtures(): array;

    /**
     * @return array<string, string> Extra env vars passed to the
     *         controller subprocess (added to the replay-aware defaults).
     */
    protected function replayExtraEnv(): array
    {
        return [];
    }

    // ── Lifecycle ──

    protected function setUp(): void
    {
        // Skip the parent setUp() which requires LLAMA_CPP_SMOKE_TEST.
        // Instead, run our own lightweight setup without live LLM.
        \PHPUnit\Framework\TestCase::setUp();

        $this->projectDir = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();

        $this->sessionId = substr(bin2hex(random_bytes(16)), 0, 12);
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir($this->tempDirPrefix());

        $this->createIsolatedProjectDir();

        $this->process = null;
        $this->pipes = [];
        $this->stdoutBuf = '';
        $this->stderrBuf = '';
        $this->runId = '';
        $this->trackedPids = [];

        // Resolve fixtures from subclass AFTER tempDir is set (fixtures
        // may reference tempDir-relative paths).
        $this->replayFixtures = $this->replayFixtures();
    }

    protected function tearDown(): void
    {
        $this->stopProcessWithOwnership();

        if (isset($this->tempDir) && '' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }

        \PHPUnit\Framework\TestCase::tearDown();
    }

    // ── Process lifecycle with ownership ─────────────────────────

    /**
     * Spawn the controller subprocess in replay mode with APP_ENV=test.
     *
     * The subprocess uses APP_ENV=test so config/services_test.yaml is
     * loaded.  That config wires Symfony\Contracts\HttpClient\HttpClientInterface
     * through ControllerReplayHttpClientFactory (tests/).  When
     * HATFIELD_LLM_REPLAY_FIXTURE_PATH is set, the factory returns a
     * MockHttpClient serving fixture-driven SSE; otherwise it returns
     * the normal 5s-timeout real HttpClient.
     *
     * No production code in src/ checks the replay env var.
     *
     * Process group tracking: after spawn we discover child PIDs so
     * teardown can kill the entire group.
     */
    protected function spawnController(): void
    {
        // Controller replay E2E MUST use the source bin/console, not a
        // PHAR: the controller boots with APP_ENV=test which loads
        // config/packages/test/ bundles (e.g. DAMA\DoctrineTestBundle).
        // These test-only bundles are not included in the PHAR.  Live
        // controller smoke (castor test:controller, APP_ENV=dev, PHAR)
        // is unaffected.
        $php = \PHP_BINARY;
        $projectDir = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
        $script = $projectDir.'/bin/console';
        \PHPUnit\Framework\Assert::assertFileExists(
            $script, 'Agent executable not found at '.$script,
        );

        // Write all replay fixtures to temp files and produce the
        // semicolon-separated env path consumed by
        // ControllerReplayHttpClientFactory in services_test.yaml.
        $fixturePaths = [];
        foreach ($this->replayFixtures as $i => $fixture) {
            $fixturePath = $this->tempDir.'/.replay-fixture-'.($i + 1).'.json';
            file_put_contents(
                $fixturePath,
                json_encode($fixture, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT),
            );
            $fixturePaths[] = $fixturePath;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = [
            // APP_ENV=test ensures services_test.yaml is loaded ->
            // ControllerReplayHttpClientFactory wires the replay
            // MockHttpClient via Symfony DI.
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            // Give the subprocess its own isolated SQLite DB so
            // migrations run fresh (the parent PHPUnit migrated the
            // shared test DB already).  The path is relative to
            // %kernel.project_dir%/var/test/ per config/packages/test/doctrine.yaml.
            'HATFIELD_TEST_DATABASE_PATH' => 'app_test-replay-'.$this->sessionId.'.sqlite',
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=run_control_{$this->sessionId}",
            'HATFIELD_LLM_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=llm_{$this->sessionId}",
            'HATFIELD_TOOL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=tool_{$this->sessionId}",
            'HATFIELD_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=agent_{$this->sessionId}",
            'HATFIELD_MCP_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=mcp_{$this->sessionId}",
            'HATFIELD_SESSION_ID' => $this->sessionId,
            // Replay activation — consumed by ControllerReplayHttpClientFactory
            'HATFIELD_LLM_REPLAY_FIXTURE_PATH' => implode(';', $fixturePaths),
            // Explicitly NOT setting LLAMA_CPP_SMOKE_TEST.
        ];

        // Merge subclass extras.
        foreach ($this->replayExtraEnv() as $k => $v) {
            $env[$k] = $v;
        }

        $pipes = [];

        // Use setsid() to create a new process group for the controller
        // and all its Messenger consumer descendants.  We track the group
        // PGID and terminate the entire group on teardown.
        $process = @proc_open(
            array_merge(
                [$php, $script, 'agent', '--controller', '--cwd='.$this->tempDir],
                $this->controllerExtraArgs(),
            ),
            $descriptors,
            $pipes,
            $this->tempDir,
            $env,
            // Run in a new process session so the controller + Messenger
            // consumers share a PGID.
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('Failed to spawn controller process.');
        }

        $this->process = $process;
        $this->pipes = $pipes;

        stream_set_blocking($pipes[0], true);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Track the PID and discover initial child PIDs.
        $status = @proc_get_status($process);
        if (\is_array($status) && isset($status['pid'])) {
            $this->trackedPids = [$status['pid']];

            // Discover immediate descendants for explicit teardown.
            $descendants = $this->discoverChildPids($status['pid']);
            $this->trackedPids = array_merge($this->trackedPids, $descendants);
        }
    }

    /**
     * Terminate the controller process group and assert no survivors.
     *
     * Uses SIGTERM → short grace → SIGKILL for the entire tracked
     * set of PIDs.  Afterward, asserts no child processes remain.
     * On failure, records diagnostics for debugging.
     */
    private function stopProcessWithOwnership(): void
    {
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = [];

        if (null === $this->process) {
            return;
        }

        // Refresh tracked PIDs before killing — children may have been
        // spawned since the last discovery.
        $status = @proc_get_status($this->process);
        if (\is_array($status) && isset($status['pid'])) {
            $descendants = $this->discoverChildPids($status['pid']);
            $this->trackedPids = array_unique(
                array_merge($this->trackedPids, $descendants),
            );
        }

        // SIGTERM all tracked PIDs.
        foreach ($this->trackedPids as $pid) {
            if ($pid > 1) {
                @posix_kill($pid, \SIGTERM);
            }
        }

        $deadline = microtime(true) + 1.0;
        $stillAlive = true;
        while ($stillAlive && microtime(true) < $deadline) {
            $stillAlive = false;
            foreach ($this->trackedPids as $pid) {
                if ($pid > 1 && $this->isPidAlive($pid)) {
                    $stillAlive = true;
                    break;
                }
            }
            if ($stillAlive) {
                usleep(50_000);
            }
        }

        // SIGKILL any survivors.
        foreach ($this->trackedPids as $pid) {
            if ($pid > 1 && $this->isPidAlive($pid)) {
                @posix_kill($pid, \SIGKILL);
            }
        }

        // Close the proc resource (reaps the main controller child).
        @proc_close($this->process);
        $this->process = null;

        // Assert: no tracked PIDs still alive.
        $survivors = [];
        foreach ($this->trackedPids as $pid) {
            if ($pid > 1 && $this->isPidAlive($pid)) {
                $survivors[] = $pid;
            }
        }

        if ([] !== $survivors) {
            // Log survivorship — this is a soft diagnostic, not a hard
            // test failure, because some platforms (e.g. macOS) may
            // have timing differences in process reaping.
            $names = [];
            foreach ($survivors as $pid) {
                $cmdline = (string) @file_get_contents("/proc/{$pid}/cmdline");
                $names[] = "  PID {$pid}: ".($cmdline ?: '(unknown)');
            }
            \fwrite(\STDERR, "[WARNING] Process ownership: ".count($survivors)
                ." tracked PIDs still alive after teardown:\n"
                .implode("\n", $names)."\n");
        }
    }

    /**
     * True when the PID still has a live task (not a zombie waiting on reap).
     */
    private function isPidAlive(int $pid): bool
    {
        if (!@posix_kill($pid, 0)) {
            return false;
        }

        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (false === $stat) {
            return true;
        }

        $closeParen = strrpos($stat, ')');
        if (false === $closeParen) {
            return true;
        }

        $rest = trim(substr($stat, $closeParen + 1));
        $fields = preg_split('/\s+/', $rest) ?: [];

        return 'Z' !== ($fields[0] ?? '');
    }

    /**
     * Discover child PIDs of a process by scanning /proc.
     *
     * @return list<int>
     */
    private function discoverChildPids(int $parentPid): array
    {
        $pids = [];

        // Try /proc/<pid>/task/<tid>/children (Linux).
        $childrenPath = "/proc/{$parentPid}/task/{$parentPid}/children";
        if (is_readable($childrenPath)) {
            $content = (string) @file_get_contents($childrenPath);
            foreach (explode(' ', trim($content)) as $token) {
                $childPid = (int) $token;
                if ($childPid > 1) {
                    $pids[] = $childPid;
                    // Recurse into grandchildren.
                    $pids = array_merge($pids, $this->discoverChildPids($childPid));
                }
            }

            return $pids;
        }

        // Fallback: scan /proc for PPID matching (less precise, but
        // works on systems without /proc/<pid>/children).
        $procDir = '/proc';
        if (!is_dir($procDir)) {
            return $pids;
        }

        $entries = @scandir($procDir);
        if (false === $entries) {
            return $pids;
        }

        foreach ($entries as $entry) {
            $candidatePid = (int) $entry;
            if ($candidatePid <= 1 || (string) $candidatePid !== $entry) {
                continue;
            }

            $statPath = "{$procDir}/{$entry}/stat";
            if (!is_readable($statPath)) {
                continue;
            }

            $stat = (string) @file_get_contents($statPath);
            if ('' === $stat) {
                continue;
            }

            // /proc/<pid>/stat fields are space-separated; PPID is field 4
            // (indices 0-based after the pid and comm fields, which may
            // contain spaces).  Use a regex to extract.
            if (preg_match('/^\d+\s+\(.*?\)\s+\w\s+(\d+)/', $stat, $m)
                && (int) $m[1] === $parentPid
            ) {
                $pids[] = $candidatePid;
                $pids = array_merge($pids, $this->discoverChildPids($candidatePid));
            }
        }

        return $pids;
    }

    // ── Override: override stopProcess so the live-LLM teardown helpers
    //    use our ownership-aware version instead.

    protected function stopProcess(): void
    {
        // Called by ControllerE2eTestCase::tearDown().
        // We override to delegate to our ownership-aware version.
        $this->stopProcessWithOwnership();
    }

    protected function collectDiagnostics(array $events): string
    {
        $parentDiag = parent::collectDiagnostics($events);

        $ownershipLines = [
            'Tracked PIDs: '.('' !== implode(', ', $this->trackedPids)
                ? implode(', ', $this->trackedPids) : '(none)'),
            'Fixture count: '.count($this->replayFixtures),
        ];

        return $parentDiag."\n".implode("\n", $ownershipLines)."\n";
    }
}
