<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Shared infrastructure for controller E2E smoke tests.
 *
 * Provides process lifecycle (proc_open), JSONL I/O, event collection,
 * diagnostics dumping, session artifact assertions, and isolated test
 * directory creation. Concrete tests only define setUp/test/tearDown.
 *
 * @group llm-real
 */
abstract class ControllerE2eTestCase extends TestCase
{
    protected string $tempDir;
    protected string $projectDir;

    /** @var resource|null */
    protected mixed $process = null;

    /** @var array<int, resource> */
    protected array $pipes = [];

    /** @var list<int> PIDs in this test's controller subprocess tree (proc child + descendants). */
    protected array $trackedControllerPids = [];
    protected string $stdoutBuf = '';
    protected string $stderrBuf = '';
    protected string $runId = '';
    protected string $sessionId = '';

    /**
     * Parent/root run id for multiplexed controller stdout (child runs forwarded on same stream).
     * Set from the first run.started seen during a collection window, or from $this->runId when known.
     */
    protected ?string $parentRunIdForCollection = null;

    // ── Lifecycle ──

    protected function setUp(): void
    {
        parent::setUp();

        if (false === getenv('LLAMA_CPP_SMOKE_TEST') || '' === getenv('LLAMA_CPP_SMOKE_TEST')) {
            $this->markTestSkipped(
                'LLAMA_CPP_SMOKE_TEST is not set. Run `castor test:llm-real` or set '
                .'LLAMA_CPP_SMOKE_TEST=1 to enable the real llama.cpp smoke test.'
            );
        }

        $this->projectDir = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();

        // Ephemeral live E2E runs must not use all-digit ids: SessionAwareModelResolver treats
        // ctype_digit session ids as persisted Hatfield sessions and fails when metadata is missing.
        $this->sessionId = 'e2e-'.substr(bin2hex(random_bytes(16)), 0, 12);
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir($this->tempDirPrefix());

        $this->createIsolatedProjectDir();

        $this->process = null;
        $this->pipes = [];
        $this->trackedControllerPids = [];
        $this->stdoutBuf = '';
        $this->stderrBuf = '';
        $this->runId = '';
        $this->parentRunIdForCollection = null;
    }

    protected function tearDown(): void
    {
        $this->stopProcess();

        if (isset($this->tempDir) && '' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    // ── Overridable hooks ──

    /**
     * Prefix for the temp directory name (e.g. 'test-controller').
     */
    abstract protected function tempDirPrefix(): string;

    /**
     * @return array{input: string[], tool_calling: bool}
     */
    protected function modelConfig(): array
    {
        return [
            'input' => ['text'],
            'tool_calling' => true,
        ];
    }

    /**
     * @return list<string> Extra CLI arguments appended to `agent --controller`.
     *                      Default excludes bash for deterministic E2E tests.
     */
    protected function controllerExtraArgs(): array
    {
        return ['--tools-excluded=bash'];
    }

    /**
     * @return string Extra YAML appended to the generated `.hatfield/settings.yaml`.
     */
    protected function extraSettingsYaml(): string
    {
        return '';
    }

    /**
     * @return array<string, string> extra lines for diagnostics output
     */
    protected function extraDiagnostics(): array
    {
        return [];
    }

    /**
     * Extra environment variables for the controller subprocess (and inherited workers).
     *
     * @return array<string, string>
     */
    protected function controllerSubprocessEnv(): array
    {
        return [];
    }

    /**
     * Wall-clock budget for live LLM tool smoke tests (first LLM turn + tool execution).
     * Replay-backed controller tests may pass with shorter timeouts; live llama.cpp is slower.
     */
    protected function liveLlmToolWaitTimeout(): float
    {
        return 12.0;
    }

    /**
     * Wall-clock budget for a single-turn live LLM run (start_run → terminal state).
     * Under full castor check, ParaTest llm-real (4 workers) competes with other
     * parallel lanes; 8s can expire after run.started before the first LLM response
     * reaches stdout (collectEvents exits on run.completed only when seen).
     */
    protected function liveLlmRunWaitTimeout(): float
    {
        return 12.0;
    }

    /**
     * True when the indexed event stream shows the assistant began or finished a message.
     */
    protected function hasAssistantResponseEvidence(array $byType): bool
    {
        return isset($byType['assistant.message_started'])
            || isset($byType['assistant.text_started'])
            || isset($byType['assistant.text_delta'])
            || isset($byType['assistant.thinking_started'])
            || isset($byType['assistant.message_completed']);
    }

    /**
     * Wall-clock budget for controller subprocess to emit runtime.ready.
     * Under full castor check, ParaTest llm-real (4 workers) competes with
     * other parallel lanes; 5s flakes while standalone llm-real passes.
     * Early-exit on event — does not slow passing tests.
     */
    protected function liveControllerReadyTimeout(): float
    {
        return 12.0;
    }

    // ── Process lifecycle ──

    protected function spawnController(): void
    {
        [$php, $script] = AgentTestExecutable::sourceConsoleCommand();
        $this->assertFileExists($script, 'Agent executable not found at '.$script);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // APP_ENV=test loads services_test.yaml (5s HttpClient when replay is off).
        // Source bin/console is required — the PHAR excludes dev/test-only bundles.
        // APP_DEBUG=1 keeps Symfony verbose errors in subprocess stderr on failure.
        $env = [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            'HATFIELD_TEST_DATABASE_PATH' => 'app_test-live-'.$this->sessionId.'.sqlite',
            'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => 'messenger_transport_test-live-'.$this->sessionId.'.sqlite',
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=run_control_{$this->sessionId}",
            'HATFIELD_LLM_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=llm_{$this->sessionId}",
            'HATFIELD_TOOL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=tool_{$this->sessionId}",
            'HATFIELD_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=agent_{$this->sessionId}",
            'HATFIELD_MCP_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=mcp_{$this->sessionId}",
            'HATFIELD_SESSION_ID' => $this->sessionId,
            'LLAMA_CPP_SMOKE_TEST' => '1',
        ];
        $env = array_merge($env, $this->controllerSubprocessEnv());

        $pipes = [];
        $process = @proc_open(
            array_merge(
                [$php, $script, 'agent', '--controller', '--cwd='.$this->tempDir],
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

        $this->trackControllerProcessTree($process);
    }

    protected function stopProcess(): void
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

        $this->refreshTrackedControllerPids();

        foreach ($this->trackedControllerPids as $pid) {
            if ($this->isProcessOwnedForTeardown($pid)) {
                @posix_kill($pid, \SIGTERM);
            }
        }

        $deadline = microtime(true) + 1.0;
        $stillAlive = true;
        while ($stillAlive && microtime(true) < $deadline) {
            $stillAlive = false;
            foreach ($this->trackedControllerPids as $pid) {
                if ($this->isProcessOwnedForTeardown($pid) && $this->isControllerPidAlive($pid)) {
                    $stillAlive = true;
                    break;
                }
            }
            if ($stillAlive) {
                usleep(50_000);
            }
        }

        foreach ($this->trackedControllerPids as $pid) {
            if ($this->isProcessOwnedForTeardown($pid) && $this->isControllerPidAlive($pid)) {
                @posix_kill($pid, \SIGKILL);
            }
        }

        @proc_close($this->process);
        $this->process = null;

        $this->logControllerProcessSurvivors();
        $this->trackedControllerPids = [];
    }

    /** @phpstan-impure */
    protected function isRunning(): bool
    {
        if (null === $this->process) {
            return false;
        }

        $status = @proc_get_status($this->process);

        return \is_array($status) && true === $status['running'];
    }

    // ── I/O ──

    /**
     * @param array<string, mixed> $data
     */
    protected function writeCommand(array $data): void
    {
        if (!isset($this->pipes[0]) || !\is_resource($this->pipes[0])) {
            throw new \RuntimeException('stdin pipe not available.');
        }

        $line = json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
        $written = @fwrite($this->pipes[0], $line);
        if (false === $written || $written < \strlen($line)) {
            throw new \RuntimeException('Failed to write to controller stdin.');
        }

        fflush($this->pipes[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function readEvents(): array
    {
        if (!isset($this->pipes[1]) || !\is_resource($this->pipes[1])) {
            return [];
        }

        $this->drainStderr();

        $chunk = stream_get_contents($this->pipes[1]);
        if (false === $chunk || '' === $chunk) {
            return [];
        }

        $this->stdoutBuf .= $chunk;

        return $this->parseBuffer($this->stdoutBuf);
    }

    protected function drainStderr(): void
    {
        if (!isset($this->pipes[2]) || !\is_resource($this->pipes[2])) {
            return;
        }

        $chunk = stream_get_contents($this->pipes[2]);
        if (false !== $chunk && '' !== $chunk) {
            $this->stderrBuf .= $chunk;
        }
    }

    // ── Event helpers ──

    /**
     * Index events by type, returning `[type => [event, ...]]`.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function indexByType(array $events): array
    {
        $byType = [];
        foreach ($events as $e) {
            $type = (string) ($e['type'] ?? 'unknown');
            $byType[$type] = $byType[$type] ?? [];
            $byType[$type][] = $e;
        }

        return $byType;
    }

    /**
     * Check whether any command.ack event references the given command ID.
     *
     * @param list<array<string, mixed>> $events
     */
    protected function foundAck(array $events, string $cmdId): bool
    {
        foreach ($events as $event) {
            if (($event['type'] ?? '') !== 'command.ack') {
                continue;
            }
            $payload = $event['payload'] ?? [];
            if (($payload['commandId'] ?? '') === $cmdId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assert that the start_run command was acknowledged.
     *
     * @param list<array<string, mixed>> $events
     */
    protected function assertStartRunAcked(array $events, string $cmdId): void
    {
        $this->assertTrue(
            $this->foundAck($events, $cmdId),
            'Expected command.ack for start_run (cmdId='.$cmdId.'). '
            .$this->collectDiagnostics($events),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function waitForEvent(string $type, float $timeout): array
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                if (($event['type'] ?? '') === $type) {
                    return $event;
                }
            }

            $this->assertRunning('waiting for '.$type);
            usleep(10_000);
        }

        $events = $this->parseBuffer($this->stdoutBuf);
        $this->fail(
            'Timed out waiting for '.$type.' after '.$timeout.'s. '
            .$this->collectDiagnostics($events),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function collectEvents(float $timeout): array
    {
        return $this->collectEventsUntil(null, $timeout);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function collectEventsUntil(?string $targetType, float $timeout): array
    {
        $events = [];
        $deadline = microtime(true) + $timeout;
        $this->parentRunIdForCollection = '' !== $this->runId ? $this->runId : null;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $this->noteParentRunIdFromEvent($event);

                $type = $event['type'] ?? '';
                if ($targetType === $type
                    || $this->isParentRunTerminalEvent($event)
                ) {
                    return $events;
                }
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                }
                break;
            }

            usleep(10_000);
        }

        return $events;
    }

    /**
     * Collect events until a specific tool call has completed.
     *
     * The controller stream exposes the tool name on tool_execution.started,
     * while tool_execution.completed only carries the call id.  Track started
     * call ids so tests can wait for the tool they actually care about instead
     * of stopping at the first completed tool if the small smoke-test model
     * performs an exploratory call first.
     *
     * @return list<array<string, mixed>>
     */
    protected function collectEventsUntilToolCompleted(string $toolName, float $timeout): array
    {
        $events = [];
        $targetToolCallIds = [];
        $deadline = microtime(true) + $timeout;
        $this->parentRunIdForCollection = '' !== $this->runId ? $this->runId : null;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $this->noteParentRunIdFromEvent($event);

                $type = $event['type'] ?? '';
                $payload = $event['payload'] ?? [];
                if (!\is_array($payload)) {
                    $payload = [];
                }

                if ('tool_execution.started' === $type
                    && $toolName === ($payload['tool_name'] ?? null)
                    && isset($payload['tool_call_id'])
                ) {
                    $targetToolCallIds[(string) $payload['tool_call_id']] = true;
                }

                if ('tool_execution.completed' === $type
                    && isset($payload['tool_call_id'])
                    && isset($targetToolCallIds[(string) $payload['tool_call_id']])
                ) {
                    return $events;
                }

                if ($this->isParentRunTerminalEvent($event)) {
                    return $events;
                }
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                }
                break;
            }

            usleep(10_000);
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function noteParentRunIdFromEvent(array $event): void
    {
        if (null !== $this->parentRunIdForCollection && '' !== $this->parentRunIdForCollection) {
            return;
        }

        if (($event['type'] ?? '') !== 'run.started') {
            return;
        }

        $runId = (string) ($event['runId'] ?? $event['payload']['runId'] ?? '');
        if ('' !== $runId) {
            $this->parentRunIdForCollection = $runId;
        }
    }

    /**
     * Terminal lifecycle for the parent/root run under test — not forwarded child runs on the same JSONL stream.
     *
     * @param array<string, mixed> $event
     */
    protected function isParentRunTerminalEvent(array $event): bool
    {
        $type = (string) ($event['type'] ?? '');
        if (!\in_array($type, ['run.completed', 'run.failed', 'run.cancelled'], true)) {
            return false;
        }

        $eventRunId = (string) ($event['runId'] ?? $event['payload']['runId'] ?? '');
        if ('' === $eventRunId) {
            return true;
        }

        if (null === $this->parentRunIdForCollection || '' === $this->parentRunIdForCollection) {
            return true;
        }

        return $eventRunId === $this->parentRunIdForCollection;
    }

    protected function assertRunning(string $context): void
    {
        if (null !== $this->process && !$this->isRunning()) {
            $this->fail(
                'Controller process exited while '.$context.'. '
                .'Stderr: '.$this->stderrBuf,
            );
        }
    }

    // ── Diagnostics ──

    /**
     * @param list<array<string, mixed>> $events
     */
    protected function collectDiagnostics(array $events): string
    {
        $this->drainStderr();

        $chunks = [
            'Temp dir: '.$this->tempDir,
            'Run ID: '.$this->runId,
            'Controller running: '.($this->isRunning() ? 'yes' : 'no'),
            'Stderr: '.$this->stderrBuf,
            'Events collected: '.\count($events),
            'Event types: '.implode(', ', array_unique(array_map(
                static fn (array $e): string => (string) ($e['type'] ?? 'unknown'),
                $events,
            ))),
        ];

        foreach ($this->extraDiagnostics() as $label => $value) {
            $chunks[] = $label.': '.$value;
        }

        $chunks[] = $this->dumpSessionDir($this->tempDir.'/.hatfield/sessions');

        $transportDb = $this->tempDir.'/.hatfield/messenger-transport.sqlite';
        if (is_file($transportDb)) {
            $chunks[] = 'Messenger transport DB: '.filesize($transportDb).' bytes';
            try {
                $db = new \PDO('sqlite:'.$transportDb);
                $rows = $db->query('SELECT count(*), queue_name FROM messenger_messages GROUP BY queue_name');
                if (false !== $rows) {
                    foreach ($rows as $row) {
                        $chunks[] = '  '.($row[0] ?? 0).' messages in '.escapeshellarg($row[1] ?? '?');
                    }
                }
            } catch (\Throwable $e) {
                $chunks[] = '  Messenger transport DB read error: '.$e->getMessage();
            }
        } else {
            $chunks[] = 'Messenger transport DB: missing';
        }

        return "\n\n".implode("\n", $chunks)."\n\n";
    }

    protected function dumpSessionDir(string $sessionsDir): string
    {
        if (!is_dir($sessionsDir)) {
            return 'Session dir: missing';
        }

        $dirs = glob($sessionsDir.'/*', \GLOB_ONLYDIR) ?: [];
        $lines = ['Session dir: '.$sessionsDir."\nSessions: ".implode(', ', array_map('basename', $dirs))];

        foreach ($dirs as $sessionDir) {
            foreach (['events.jsonl', 'state.json', 'idempotency.jsonl'] as $file) {
                $path = $sessionDir.'/'.$file;
                if (is_file($path)) {
                    $content = (string) file_get_contents($path);
                    $lines[] = "--- {$file} (".\strlen($content)." bytes) ---\n".$content;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    protected function assertSessionArtifactsExist(string $sessionDir, array $events): void
    {
        $missing = [];

        if (!is_dir($sessionDir)) {
            $missing[] = 'session dir ('.$sessionDir.')';
        } else {
            foreach (['events.jsonl', 'state.json'] as $file) {
                $path = $sessionDir.'/'.$file;
                if (!is_file($path)) {
                    $missing[] = $file;
                } elseif (0 === filesize($path)) {
                    $missing[] = $file.' (empty)';
                }
            }
        }

        $this->assertEmpty(
            $missing,
            'Missing or empty session artifacts: '.implode(', ', $missing)."\n"
            .$this->collectDiagnostics($events),
        );
    }

    // ── Filesystem ──

    protected function createIsolatedProjectDir(): void
    {
        TestDirectoryIsolation::createHatfieldTree($this->tempDir, withSessions: true, permissions: 0o777);

        $modelConfig = $this->modelConfig();
        $input = implode(', ', array_map(
            static fn (string $v): string => "'{$v}'", // produces 'text' or 'text', 'image'
            $modelConfig['input'],
        ));
        $toolCalling = $modelConfig['tool_calling'] ? 'true' : 'false';

        $settings = <<<YAML
ai:
    default_model: llama_cpp_test/test
    default_reasoning: off
    http:
        timeout: 8
        max_retries: 0
    providers:
        llama_cpp_test:
            type: generic
            enabled: true
            base_url: http://192.168.2.38:9052/v1
            api: openai-completions
            api_key: dummy
            completions_path: /chat/completions
            supports_completions: true
            supports_embeddings: false
            supports_thinking_levels: false
            models:
                test:
                    name: test
                    context_window: 32768
                    max_tokens: 32768
                    input: [{$input}]
                    tool_calling: {$toolCalling}
                    reasoning: false
                    cost: { input: 0, output: 0, cache_read: 0, cache_write: 0 }

extensions:
    enabled:
        - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
    settings:
        safe_guard:
            tool_names:
                bash: bash
                write: write
                edit: edit
                read: read
            allow_command_patterns: []
            allow_write_outside_cwd: []
            protected_read_patterns: []
            dangerous_command_patterns: []
YAML;

        $extraYaml = $this->extraSettingsYaml();
        if ('' !== $extraYaml) {
            $settings .= "\n".$extraYaml;
        }

        file_put_contents($this->tempDir.'/.hatfield/settings.yaml', $settings);
        file_put_contents($this->tempDir.'/.hatfield/.gitignore', "*\n");
    }

    /**
     * Record the controller proc child and any descendants for bounded teardown.
     */
    protected function trackControllerProcessTree(mixed $process): void
    {
        if (!\is_resource($process)) {
            return;
        }

        $status = @proc_get_status($process);
        if (!\is_array($status) || !isset($status['pid'])) {
            return;
        }

        $rootPid = (int) $status['pid'];
        $this->trackedControllerPids = array_values(array_unique(array_merge(
            [$rootPid],
            $this->discoverControllerChildPids($rootPid),
        )));
    }

    /**
     * Refresh descendant PIDs immediately before shutdown (Messenger consumers
     * may spawn after the initial track at proc_open).
     */
    protected function refreshTrackedControllerPids(): void
    {
        if (null === $this->process) {
            return;
        }

        $status = @proc_get_status($this->process);
        if (!\is_array($status) || !isset($status['pid'])) {
            return;
        }

        $rootPid = (int) $status['pid'];
        $this->trackedControllerPids = array_values(array_unique(array_merge(
            $this->trackedControllerPids,
            [$rootPid],
            $this->discoverControllerChildPids($rootPid),
        )));
    }

    /**
     * @return list<int>
     */
    protected function discoverControllerChildPids(int $parentPid): array
    {
        $pids = [];

        $childrenPath = "/proc/{$parentPid}/task/{$parentPid}/children";
        if (is_readable($childrenPath)) {
            $content = (string) @file_get_contents($childrenPath);
            foreach (explode(' ', trim($content)) as $token) {
                $childPid = (int) $token;
                if ($childPid <= 1 || !$this->isProcessOwnedForTeardown($childPid)) {
                    continue;
                }
                $pids[] = $childPid;
                $pids = array_merge($pids, $this->discoverControllerChildPids($childPid));
            }

            return $pids;
        }

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

            if (preg_match('/^\d+\s+\(.*?\)\s+\w\s+(\d+)/', $stat, $m)
                && (int) $m[1] === $parentPid
            ) {
                if (!$this->isProcessOwnedForTeardown($candidatePid)) {
                    continue;
                }
                $pids[] = $candidatePid;
                $pids = array_merge($pids, $this->discoverControllerChildPids($candidatePid));
            }
        }

        return $pids;
    }

    protected function isProcessOwnedForTeardown(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }

        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (false === $stat) {
            return false;
        }

        $closeParen = strrpos($stat, ')');
        if (false === $closeParen) {
            return false;
        }

        $rest = trim(substr($stat, $closeParen + 1));
        $fields = preg_split('/\s+/', $rest) ?: [];
        if ((int) ($fields[2] ?? -1) !== posix_getuid()) {
            return false;
        }

        if ('' === $this->sessionId) {
            return true;
        }

        $environ = @file_get_contents("/proc/{$pid}/environ");
        if (false === $environ) {
            return false;
        }

        $expected = 'HATFIELD_SESSION_ID='.$this->sessionId;
        foreach (explode("\0", $environ) as $entry) {
            if ($entry === $expected) {
                return true;
            }
        }

        return false;
    }

    protected function isControllerPidAlive(int $pid): bool
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

    protected function logControllerProcessSurvivors(): void
    {
        $survivors = [];
        foreach ($this->trackedControllerPids as $pid) {
            if ($this->isProcessOwnedForTeardown($pid) && $this->isControllerPidAlive($pid)) {
                $survivors[] = $pid;
            }
        }

        if ([] === $survivors) {
            return;
        }

        $names = [];
        foreach ($survivors as $pid) {
            $cmdline = (string) @file_get_contents("/proc/{$pid}/cmdline");
            $names[] = "  PID {$pid}: ".str_replace("\0", ' ', $cmdline ?: '(unknown)');
        }

        fwrite(
            \STDERR,
            '[WARNING] Controller E2E process ownership: '.\count($survivors)
            ." tracked PIDs still alive after teardown:\n"
            .implode("\n", $names)."\n",
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseBuffer(string &$buf): array
    {
        $lastNewline = strrpos($buf, "\n");
        if (false === $lastNewline) {
            return [];
        }

        $complete = substr($buf, 0, $lastNewline + 1);
        $buf = substr($buf, $lastNewline + 1);

        $events = [];
        foreach (explode("\n", $complete) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    $events[] = $decoded;
                }
            } catch (\JsonException) {
                $this->stderrBuf .= "\n[malformed stdout] ".$trimmed;
            }
        }

        return $events;
    }
}
