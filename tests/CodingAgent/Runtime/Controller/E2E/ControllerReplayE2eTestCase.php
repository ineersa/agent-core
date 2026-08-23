<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay\ControllerReplayLowLatencyMessengerConsole;
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
 * children are spawned under setsid so the controller is the session
 * / process-group leader, then tracked via process-tree PID discovery.
 * Teardown terminates the owned tree deterministically and asserts no
 * children remain.
 *
 * MAINT-05D foundation.  This is the replay seam for controller
 * E2E tests; MAINT-05E will add similar support for TUI E2E.
 */
abstract class ControllerReplayE2eTestCase extends ControllerE2eTestCase
{
    /** Max wait for compaction.completed/failed after compaction.started (replay fixtures). */
    protected const COMPACTION_TERMINAL_AFTER_STARTED_SECONDS = 4.0;
    /** @var list<array<string, mixed>> One fixture per expected LLM call */
    protected array $replayFixtures = [];
    // ── Lifecycle ──

    protected function setUp(): void
    {
        // Skip the parent setUp() which requires LLAMA_CPP_SMOKE_TEST.
        // Instead, run our own lightweight setup without live LLM.
        \PHPUnit\Framework\TestCase::setUp();

        $this->projectDir = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();

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

        // Resolve fixtures from subclass AFTER tempDir is set (fixtures
        // may reference tempDir-relative paths).
        $this->replayFixtures = $this->replayFixtures();
    }

    protected function tearDown(): void
    {
        $this->stopProcess();
        // Keep failing fixtures when debugging controller-replay failures.
        // Parent tearDown always removes tempDir; clear it first when keeping
        // so the parent lifecycle cannot delete retained artifacts.
        $keepTmp = false !== getenv('HATFIELD_KEEP_TMP') && '' !== getenv('HATFIELD_KEEP_TMP');
        if (!$keepTmp && isset($this->tempDir) && '' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
        $this->tempDir = '';
        parent::tearDown();
    }

    // Single-llm isolation for process-local FIFO fixtures is applied in
    // ControllerE2eTestCase::createIsolatedProjectDir() (runtime.llm_worker_count=1).
    // Replay inherits that base seam; do not re-write settings here (duplicate keys
    // / post-parse YAML dump would only obscure the shared isolation contract).

    /**
     * Subclasses MUST override to return at least one fixture.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function replayFixtures(): array;

    /**
     * @return array<string, string> extra env vars passed to the
     *                               controller subprocess (added to the replay-aware defaults)
     */
    protected function replayExtraEnv(): array
    {
        return [];
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

        // ConsumerSupervisor relaunches messenger:consume via HATFIELD_BINARY_PATH.
        // Point it at a test-only wrapper that injects a small --sleep so idle
        // queue polls do not burn the default 1s Symfony Messenger sleep under
        // SafeGuard multi-hop / tool-start contention. Controller itself still
        // boots from source bin/console below.
        $messengerConsole = ControllerReplayLowLatencyMessengerConsole::install(
            $this->tempDir,
            $script,
        );

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
            // Hermetic home: skill materialization and user settings must not touch
            // the real developer HOME (proc_open env arrays do not inherit).
            'HOME' => $this->tempDir,
            // Give the subprocess its own isolated SQLite DB so
            // migrations run fresh (the parent PHPUnit migrated the
            // shared test DB already).  The path is relative to
            // %kernel.project_dir%/var/test/ per config/packages/test/doctrine.yaml.
            'HATFIELD_TEST_DATABASE_PATH' => 'app_test-replay-'.$this->sessionId.'.sqlite',
            'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => 'messenger_transport_test-replay-'.$this->sessionId.'.sqlite',
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=run_control_{$this->sessionId}&redeliver_timeout=60",
            'HATFIELD_LLM_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=llm_{$this->sessionId}&redeliver_timeout=60",
            'HATFIELD_TOOL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=tool_{$this->sessionId}&redeliver_timeout=60",
            'HATFIELD_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=agent_{$this->sessionId}&redeliver_timeout=60",
            'HATFIELD_MCP_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=mcp_{$this->sessionId}&redeliver_timeout=60",
            'HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=extension_agent_{$this->sessionId}&redeliver_timeout=60",
            'HATFIELD_SESSION_ID' => $this->sessionId,
            // Replay activation — consumed by ControllerReplayHttpClientFactory
            'HATFIELD_LLM_REPLAY_FIXTURE_PATH' => implode(';', $fixturePaths),
            // Inherited by ConsumerSupervisor children; ConfigExecutableLocator wins.
            'HATFIELD_BINARY_PATH' => $messengerConsole,
            // Explicitly NOT setting LLAMA_CPP_SMOKE_TEST.
        ];

        // Merge subclass extras.
        foreach ($this->replayExtraEnv() as $k => $v) {
            $env[$k] = $v;
        }

        $pipes = [];

        $process = @proc_open(
            array_merge(
                // setsid -w: proc_open child becomes session/PGID leader.
                ['setsid', '-w', $php, $script, 'agent', '--controller', '--cwd='.$this->tempDir],
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

        $this->messengerTransportDbFilename = (string) ($env['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'] ?? '');
        $this->trackControllerProcessTree($process);
    }

    protected function collectDiagnostics(array $events): string
    {
        $parentDiag = parent::collectDiagnostics($events);

        $ownershipLines = [
            'Tracked PIDs: '.('' !== implode(', ', $this->trackedControllerPids)
                ? implode(', ', $this->trackedControllerPids) : '(none)'),
            'Fixture count: '.\count($this->replayFixtures),
        ];

        return $parentDiag."\n".implode("\n", $ownershipLines)."\n";
    }

    /**
     * Collect until the parent run reaches a terminal event, then optionally
     * wait for async after-turn compaction on run_control.
     *
     * @return list<array<string, mixed>>
     */
    protected function collectTurnEventsUntilRunTerminal(
        ?string $runTerminalType,
        float $runTerminalTimeoutSeconds,
        bool $expectAfterTurnCompaction = false,
        float $compactionTimeoutSeconds = 6.0,
    ): array {
        $events = $this->collectEventsUntil($runTerminalType, $runTerminalTimeoutSeconds);

        if (!$expectAfterTurnCompaction) {
            return $events;
        }

        return array_merge(
            $events,
            $this->drainUntilCompactionTerminal($compactionTimeoutSeconds),
        );
    }

    /**
     * After run.completed when compaction is expected: wait event-driven for
     * compaction.completed/failed once compaction.started appears (bounded phase).
     *
     * @return list<array<string, mixed>>
     */
    protected function drainUntilCompactionTerminal(float $timeoutSeconds): array
    {
        $events = [];
        $deadline = microtime(true) + $timeoutSeconds;
        $compactionStartedAt = null;
        $compactionTerminalDeadline = null;

        while (microtime(true) < $deadline) {
            $sawNew = false;
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $sawNew = true;
                $type = $event['type'] ?? '';

                if ('compaction.started' === $type && null === $compactionStartedAt) {
                    $compactionStartedAt = microtime(true);
                    $compactionTerminalDeadline = $compactionStartedAt
                        + self::COMPACTION_TERMINAL_AFTER_STARTED_SECONDS;
                }

                if (\in_array($type, ['compaction.completed', 'compaction.failed'], true)) {
                    return $events;
                }
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                    $sawNew = true;
                    $type = $event['type'] ?? '';
                    if (\in_array($type, ['compaction.completed', 'compaction.failed'], true)) {
                        return $events;
                    }
                }
                break;
            }

            if (null !== $compactionTerminalDeadline && microtime(true) > $compactionTerminalDeadline) {
                break;
            }

            if (!$sawNew) {
                usleep(50_000);
            }
        }

        return $events;
    }
}
