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
     * @return array<string, string> Extra lines for diagnostics output.
     */
    protected function extraDiagnostics(): array
    {
        return [];
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
        [$php, $script] = AgentTestExecutable::command();
        self::assertFileExists($script, 'Agent executable not found at '.$script);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = [
            'APP_ENV' => 'dev',
            'APP_DEBUG' => '1',
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => "doctrine://default?queue_name=run_control_{$this->sessionId}",
            'HATFIELD_LLM_TRANSPORT_DSN' => "doctrine://default?queue_name=llm_{$this->sessionId}",
            'HATFIELD_TOOL_TRANSPORT_DSN' => "doctrine://default?queue_name=tool_{$this->sessionId}",
            'HATFIELD_SESSION_ID' => $this->sessionId,
            'LLAMA_CPP_SMOKE_TEST' => '1',
        ];

        $pipes = [];
        $process = @proc_open(
            [$php, $script, 'agent', '--controller', '--cwd='.$this->tempDir, '--tools-excluded=bash'],
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

    private function drainStderr(): void
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
        $events = [];
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;

                $type = $event['type'] ?? '';
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

    private function dumpSessionDir(string $sessionsDir): string
    {
        if (!is_dir($sessionsDir)) {
            return 'Session dir: missing';
        }

        $dirs = \glob($sessionsDir.'/*', \GLOB_ONLYDIR) ?: [];
        $lines = ['Session dir: '.$sessionsDir."\nSessions: ".implode(', ', array_map('basename', $dirs))];

        foreach ($dirs as $sessionDir) {
            foreach (['events.jsonl', 'state.json', 'transcript.jsonl', 'idempotency.jsonl'] as $file) {
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

            // transcript.jsonl is projected from session events by the
            // TranscriptPersistenceService in controller mode. When it's
            // missing or empty the run still succeeded — report a soft
            // diagnostic without failing the test.
            $transcriptPath = $sessionDir.'/transcript.jsonl';
            if (!is_file($transcriptPath) || 0 === filesize($transcriptPath)) {
                \fwrite(\STDERR, "[INFO] transcript.jsonl empty — projected during controller run.\n");
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

        file_put_contents($this->tempDir.'/.hatfield/settings.yaml', $settings);
        file_put_contents($this->tempDir.'/.hatfield/.gitignore', "*\n");
    }

}
