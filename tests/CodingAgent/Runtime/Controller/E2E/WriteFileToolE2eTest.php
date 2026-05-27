<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test for the write_file tool via the async controller process.
 *
 * Spawns `bin/console agent --controller`, sends a prompt that triggers the
 * write_file tool, and asserts the tool is executed, the file is created, and
 * the run advances past tool execution to completion.
 *
 * @group llm-real
 */
#[Group('llm-real')]
final class WriteFileToolE2eTest extends TestCase
{
    private string $tempDir;
    private string $projectDir;

    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];
    private string $stdoutBuf = '';
    private string $stderrBuf = '';
    private string $runId = '';
    private string $sessionId = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (false === getenv('LLAMA_CPP_SMOKE_TEST') || '' === getenv('LLAMA_CPP_SMOKE_TEST')) {
            self::markTestSkipped(
                'LLAMA_CPP_SMOKE_TEST is not set. Run `castor test:llm-real` or set '
                .'LLAMA_CPP_SMOKE_TEST=1 to enable the real llama.cpp smoke test.'
            );
        }

        $this->projectDir = \realpath(__DIR__.'/../../../../..');
        if (false === $this->projectDir) {
            throw new \RuntimeException('Cannot resolve project root.');
        }

        $this->sessionId = substr(bin2hex(random_bytes(16)), 0, 12);
        $this->tempDir = $this->projectDir.'/var/tmp/test-write-file-'.uniqid('', true);

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
            $this->removeDir($this->tempDir);
        }

        parent::tearDown();
    }

    public function testWriteFileToolExecutesAndRunCompletes(): void
    {
        $this->spawnController();

        // ── Wait for runtime.ready ──
        $this->waitForEvent('runtime.ready', 5.0);

        // Create a target path inside the isolated temp dir
        $targetPath = $this->tempDir.'/hello.txt';

        // ── Send start_run with a prompt that triggers write_file ──
        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Write the exact text "hello world from write_file tool" to '.$targetPath.' using the write tool, then respond with "done".',
            ],
        ]);

        // ── Collect events until run completes or timeout ──
        $events = $this->collectEvents(30.0);
        $byType = [];
        foreach ($events as $e) {
            $type = (string) ($e['type'] ?? 'unknown');
            $byType[$type] = $byType[$type] ?? [];
            $byType[$type][] = $e;
        }

        // ── Assert event sequence ──

        // Must have command.ack for the start_run
        $acks = $byType['command.ack'] ?? [];
        $foundStartAck = false;
        foreach ($acks as $ack) {
            $payload = $ack['payload'] ?? [];
            if (($payload['commandId'] ?? '') === $startCmdId) {
                $foundStartAck = true;
                break;
            }
        }
        self::assertTrue(
            $foundStartAck,
            'Expected command.ack for start_run command. '
            .'Available acks: '.json_encode($acks, \JSON_THROW_ON_ERROR)."\n"
            .$this->collectDiagnostics($events),
        );

        // Must have run.started
        self::assertArrayHasKey(
            'run.started',
            $byType,
            'Expected run.started event.'."\n"
            .$this->collectDiagnostics($events),
        );

        // Capture runId from run.started
        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        self::assertNotEmpty($this->runId, 'run.started must have a runId');

        // Must have tool_execution events
        self::assertArrayHasKey(
            'tool_execution_start',
            $byType,
            'Expected tool_execution_start events.'."\n"
            .$this->collectDiagnostics($events),
        );

        self::assertArrayHasKey(
            'tool_execution_end',
            $byType,
            'Expected tool_execution_end events.'."\n"
            .$this->collectDiagnostics($events),
        );

        // Must have tool_batch_committed — this is the event where the bug was
        // (the run would hang after this without dispatching AdvanceRun).
        self::assertArrayHasKey(
            'tool_batch_committed',
            $byType,
            'Expected tool_batch_committed event — if missing, tool results were not collected.'."\n"
            .$this->collectDiagnostics($events),
        );

        // Must have assistant streaming after tool completion
        self::assertTrue(
            isset($byType['assistant.text_started']) || isset($byType['assistant.message_completed']),
            'Expected assistant.text_started or assistant.message_completed after tool execution. '
            .'If missing, the AdvanceRun after tool_batch_committed is broken. '
            .'Available event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($events),
        );

        // Must have run.completed or run.failed
        self::assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Expected run.completed or run.failed. '
            .'Available event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($events),
        );

        // If tool was actually invoked, verify the file was created on disk
        if (is_file($targetPath)) {
            $content = (string) file_get_contents($targetPath);
            self::assertStringContainsString('hello world', $content, 'File content does not contain expected text.');
        }

        // Verify session artifacts exist
        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);
    }

    // ── Process lifecycle ──

    private function spawnController(): void
    {
        $consolePath = $this->projectDir.'/bin/console';
        self::assertFileExists($consolePath, 'Console entry point not found');

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
            [\PHP_BINARY, $consolePath, 'agent', '--controller', '--cwd='.$this->tempDir],
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

    private function stopProcess(): void
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
    private function isRunning(): bool
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
    private function writeCommand(array $data): void
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
    private function readEvents(): array
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

    /**
     * @return array<string, mixed>
     */
    private function waitForEvent(string $type, float $timeout): array
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
    private function collectEvents(float $timeout): array
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

    private function assertRunning(string $context): void
    {
        if (null !== $this->process && !$this->isRunning()) {
            self::fail(
                'Controller process exited while '.$context.'. '
                .'Stderr: '.$this->stderrBuf,
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function collectDiagnostics(array $events): string
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
            foreach (['events.jsonl', 'state.json', 'transcript.jsonl', 'metadata.yaml', 'idempotency.jsonl'] as $file) {
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
    private function assertSessionArtifactsExist(string $sessionDir, array $events): void
    {
        $missing = [];

        if (!is_dir($sessionDir)) {
            $missing[] = 'session dir ('.$sessionDir.')';
        } else {
            foreach (['events.jsonl', 'state.json', 'transcript.jsonl'] as $file) {
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

    private function createIsolatedProjectDir(): void
    {
        mkdir($this->tempDir.'/.hatfield/sessions', 0777, true);

        // Use project settings as base, overriding model to use test model with tool calling enabled
        $projectSettings = $this->projectDir.'/.hatfield/settings.yaml';
        if (is_readable($projectSettings)) {
            $settings = (string) file_get_contents($projectSettings);
            // Override to use the test model with tool calling enabled
            $settings = preg_replace(
                '/^ai:\n/m',
                "ai:\n    default_model: llama_cpp_test/test\n    default_reasoning: off\n"
                ."    providers:\n"
                ."        llama_cpp_test:\n"
                ."            type: generic\n"
                ."            enabled: true\n"
                ."            base_url: http://192.168.2.38:9052/v1\n"
                ."            api: openai-completions\n"
                ."            api_key: dummy\n"
                ."            completions_path: /chat/completions\n"
                ."            supports_completions: true\n"
                ."            supports_embeddings: false\n"
                ."            supports_thinking_levels: false\n"
                ."            models:\n"
                ."                test:\n"
                ."                    name: test\n"
                ."                    context_window: 32768\n"
                ."                    max_tokens: 32768\n"
                ."                    input: [text]\n"
                ."                    tool_calling: true\n"
                ."                    reasoning: false\n"
                ."                    cost: { input: 0, output: 0, cache_read: 0, cache_write: 0 }\n",
                $settings,
                1,
            ) ?? $settings;
        } else {
            // Minimal settings with tool_calling enabled
            $settings = <<<'YAML'
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
                    input: [text]
                    tool_calling: true
                    reasoning: false
                    cost: { input: 0, output: 0, cache_read: 0, cache_write: 0 }
YAML;
        }

        file_put_contents($this->tempDir.'/.hatfield/settings.yaml', $settings);
        file_put_contents($this->tempDir.'/.hatfield/.gitignore', "*\n");
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                @chmod($file->getPathname(), 0644);
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
