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
    protected string $stdoutBuf = '';
    protected string $stderrBuf = '';
    protected string $runId = '';
    protected string $sessionId = '';

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
     *                         Default excludes bash for deterministic E2E tests.
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
     * @return array<string, string> Extra lines for diagnostics output.
     */
    protected function extraDiagnostics(): array
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
     */
    protected function liveLlmRunWaitTimeout(): float
    {
        return 8.0;
    }

    // ── Lifecycle ──

    protected function setUp(): void
    {
        parent::setUp();

        if (false === getenv('LLAMA_CPP_SMOKE_TEST') || '' === getenv('LLAMA_CPP_SMOKE_TEST')) {
            self::markTestSkipped(
                'LLAMA_CPP_SMOKE_TEST is not set. Run `castor test:llm-real` or set '
                .'LLAMA_CPP_SMOKE_TEST=1 to enable the real llama.cpp smoke test.'
            );
        }

        $this->projectDir = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();

        $this->sessionId = substr(bin2hex(random_bytes(16)), 0, 12);
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir($this->tempDirPrefix());

        $this->createIsolatedProjectDir();

        $this->process = null;
        $this->pipes = [];
        $this->stdoutBuf = '';
        $this->stderrBuf = '';
        $this->runId = '';
    }

    protected function tearDown(): void
    {
        $this->stopProcess();

        if (isset($this->tempDir) && '' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    // ── Process lifecycle ──

    protected function spawnController(): void
    {
        [$php, $script] = AgentTestExecutable::sourceConsoleCommand();
        self::assertFileExists($script, 'Agent executable not found at '.$script);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // APP_ENV=test loads services_test.yaml (5s HttpClient when replay is off).
        // Source bin/console is required — the PHAR excludes dev/test-only bundles.
        $env = [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            'HATFIELD_TEST_DATABASE_PATH' => 'app_test-live-'.$this->sessionId.'.sqlite',
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => "doctrine://default?queue_name=run_control_{$this->sessionId}",
            'HATFIELD_LLM_TRANSPORT_DSN' => "doctrine://default?queue_name=llm_{$this->sessionId}",
            'HATFIELD_TOOL_TRANSPORT_DSN' => "doctrine://default?queue_name=tool_{$this->sessionId}",
            'HATFIELD_MCP_TRANSPORT_DSN' => "doctrine://default?queue_name=mcp_{$this->sessionId}",
            'HATFIELD_SESSION_ID' => $this->sessionId,
            'LLAMA_CPP_SMOKE_TEST' => '1',
        ];

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

        if ($this->isRunning()) {
            @proc_terminate($this->process, \SIGTERM);
            $deadline = microtime(true) + 3.0;
            while ($this->isRunning() && microtime(true) < $deadline) {
                usleep(50_000);
            }
            if ($this->isRunning()) {
                @proc_terminate($this->process, \SIGKILL);
            }
        }

        @proc_close($this->process);
        $this->process = null;
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
        self::assertTrue(
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

        self::fail(
            'Timed out waiting for '.$type.' after '.$timeout.'s. '
            .'Collected events: '.json_encode(
                $this->parseBuffer($this->stdoutBuf),
                \JSON_THROW_ON_ERROR,
            )."\n"
            .'Stderr: '.$this->stderrBuf,
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

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;

                $type = $event['type'] ?? '';
                if ($targetType === $type
                    || 'run.completed' === $type
                    || 'run.failed' === $type
                    || 'run.cancelled' === $type
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

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;

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

                if ('run.completed' === $type || 'run.failed' === $type || 'run.cancelled' === $type) {
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

    protected function assertRunning(string $context): void
    {
        if (null !== $this->process && !$this->isRunning()) {
            self::fail(
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
            'Events collected: '.count($events),
            'Event types: '.implode(', ', array_unique(array_map(
                static fn (array $e): string => (string) ($e['type'] ?? 'unknown'),
                $events,
            ))),
        ];

        foreach ($this->extraDiagnostics() as $label => $value) {
            $chunks[] = $label.': '.$value;
        }

        $chunks[] = $this->dumpSessionDir($this->tempDir.'/.hatfield/sessions');

        $messengerDb = $this->tempDir.'/.hatfield/messenger.sqlite';
        if (is_file($messengerDb)) {
            $chunks[] = 'Messenger DB: '.\filesize($messengerDb).' bytes';
            try {
                $db = new \PDO('sqlite:'.$messengerDb);
                $rows = $db->query('SELECT count(*), queue_name FROM messenger_messages GROUP BY queue_name');
                if (false !== $rows) {
                    foreach ($rows as $row) {
                        $chunks[] = '  '.($row[0] ?? 0).' messages in '.\escapeshellarg($row[1] ?? '?');
                    }
                }
            } catch (\Throwable $e) {
                $chunks[] = '  Messenger DB read error: '.$e->getMessage();
            }
        } else {
            $chunks[] = 'Messenger DB: missing';
        }

        return "\n\n".implode("\n", $chunks)."\n\n";
    }

    protected function dumpSessionDir(string $sessionsDir): string
    {
        if (!is_dir($sessionsDir)) {
            return 'Session dir: missing';
        }

        $dirs = \glob($sessionsDir.'/*', \GLOB_ONLYDIR) ?: [];
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

        self::assertEmpty(
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

}
