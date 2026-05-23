<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Process-isolated implementation of AgentSessionClient.
 *
 * Spawns `bin/console agent --controller` and communicates via JSONL over
 * long-lived stdin/stdout pipes. Symfony Process intentionally is not used for
 * this parent/child control channel: Process input is designed for finite input
 * known before start(), and its pipe implementation closes stdin when the input
 * iterator is initially empty. The controller protocol needs write-after-start,
 * so this class owns proc_open() pipes directly.
 *
 * The controller process launches messenger:consume run_control, llm, and tool
 * subprocesses, enabling fully async execution. Stderr is reserved for
 * logs/debug output and is included in transport failure exceptions.
 *
 * @todo Implement full process lifecycle, reconnection, heartbeat detection.
 */
final class JsonlProcessAgentSessionClient implements AgentSessionClient
{
    /** Max controller restarts per sliding window before giving up. */
    private const int MAX_RESTARTS = 3;

    /** Sliding window in seconds for restart rate-limiting. */
    private const float RESTART_WINDOW = 60.0;
    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $stdoutBuffer = '';
    private string $stderrBuffer = '';

    /** @var \SplQueue<RuntimeEvent> */
    private \SplQueue $eventBuffer;

    private string $projectDir;

    /** @var array<string, string> */
    private array $runIdMap = [];

    /**
     * The most recently active run ID tracked across start/resume/send/cancel.
     * Used to auto-resume a run after the controller process is restarted.
     */
    private ?string $activeRunId = null;

    /**
     * Whether ensureProcessRunning() auto-resumed the active run during
     * this restart cycle. Used to prevent duplicate resume commands when
     * resume() is called after a transparent restart.
     */
    private bool $autoResumed = false;

    /**
     * Whether runtime.ready has been received from the current controller
     * process. Reset on spawnProcess(). Prevents waitForRuntimeReady()
     * from blocking after the first call consumes the single ready event.
     */
    private bool $runtimeReadyReceived = false;

    /**
     * Timestamps of recent controller restarts for rate-limiting.
     *
     * @var array<int, float>
     */
    private array $restartTimestamps = [];

    public function __construct()
    {
        $this->eventBuffer = new \SplQueue();

        /*
         * SOURCE-CHECKOUT ASSUMPTION:
         *
         * dirname(__DIR__, 4) walks up from
         *   src/CodingAgent/Runtime/Process/
         * to the app install root, where bin/console lives.
         *
         * Runtime data does NOT use this path. The controller process is
         * spawned with the caller's current working directory so .hatfield/
         * sessions, logs, and messenger.sqlite stay relative to the user's
         * project/test CWD.
         *
         * TODO: Replace with a SelfExecutableLocator / BinaryLocator that
         * resolves the headless-agent binary path from:
         *   1. An explicit config/key (e.g., agent.runtime.binary_path).
         *   2. Phar::running() / PHP_BINARY introspection inside a PHAR.
         *   3. PATH-based discovery (e.g., `which agent-core-headless`).
         *
         * See: src/CodingAgent/Runtime/Process/AGENTS.md
         */
        $this->projectDir = \dirname(__DIR__, 4);
    }

    public function __destruct()
    {
        $this->stopProcess();
    }

    public function start(StartRunRequest $request): RunHandle
    {
        // New run — clear stale state from any previous run or crash.
        $this->activeRunId = null;
        $this->autoResumed = false;

        $this->ensureProcessRunning();
        $this->waitForRuntimeReady();

        // Track the intended runId for crash recovery BEFORE sending the
        // command, so if the controller dies before run.started arrives,
        // the activeRunId is already set and auto-resume will target it.
        $runId = '' !== $request->runId ? $request->runId : null;
        if (null !== $runId) {
            $this->activeRunId = $runId;
        }

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: 'start_run',
            payload: array_filter([
                'prompt' => $request->prompt,
                'cwd' => $request->cwd,
                'options' => $request->options,
                'model' => $request->model,
                'reasoning' => $request->reasoning,
            ], static fn (mixed $v) => null !== $v),
        );

        $this->writeCommand($cmd);

        // Read events until run_started arrives.
        $timeout = 15.0;
        $start = microtime(true);

        while (microtime(true) - $start < $timeout) {
            /** @var RuntimeEvent $event */
            foreach ($this->readEvents() as $event) {
                if ('run.started' === $event->type || 'run_started' === $event->type) {
                    $this->runIdMap[$cmd->id] = $event->runId;
                    $this->activeRunId = $event->runId;

                    return new RunHandle(runId: $event->runId, status: 'running');
                }

                if ('runtime.ready' === $event->type || 'command.ack' === $event->type) {
                    continue;
                }

                // Buffer non-matching events.
                $this->eventBuffer->enqueue($event);
            }

            $this->assertProcessStillRunning('waiting for run_started');

            // No events yet — brief sleep to avoid busy-wait.
            usleep(10_000);
        }

        throw new \RuntimeException('Agent process did not emit run_started event within '.$timeout.'s'."\n".$this->diagnosticOutput());
    }

    public function resume(string $runId): RunHandle
    {
        $this->activeRunId = $runId;
        $this->ensureProcessRunning();

        // If ensureProcessRunning() auto-resumed the run after a restart,
        // skip the explicit resume write to avoid sending a duplicate command.
        if ($this->autoResumed) {
            $this->autoResumed = false;

            return new RunHandle(runId: $runId, status: 'running');
        }

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: 'resume',
            runId: $runId,
        );

        try {
            $this->writeCommand($cmd);
        } catch (\RuntimeException) {
            // Pipe may have broken — restart and retry once.
            $this->ensureProcessRunning();
            $this->writeCommand($cmd);
        }

        return new RunHandle(runId: $runId, status: 'running');
    }

    public function send(string $runId, UserCommand $command): void
    {
        $this->activeRunId = $runId;
        $this->ensureProcessRunning();

        $type = match ($command->type) {
            'steer', 'message' => 'user_message',
            'follow_up' => 'follow_up',
            'answer_human' => 'answer_human',
            default => throw new \InvalidArgumentException(\sprintf('Unknown command type: "%s"', $command->type)),
        };

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: $type,
            runId: $runId,
            payload: array_filter([
                'text' => $command->text,
                'question_id' => $command->payload['question_id'] ?? null,
                'answer' => $command->payload['answer'] ?? null,
            ]),
        );

        try {
            $this->writeCommand($cmd);
        } catch (\RuntimeException) {
            // Pipe may have broken — restart and retry once.
            $this->ensureProcessRunning();
            $this->writeCommand($cmd);
        }
    }

    public function events(string $runId): iterable
    {
        $this->activeRunId = $runId;

        // Transparently restart the controller process if it died.
        $this->ensureProcessRunning();

        // Drain buffered events first.
        while (!$this->eventBuffer->isEmpty()) {
            $event = $this->eventBuffer->dequeue();
            if ($event->runId === $runId) {
                yield $event;
            }
        }

        // Read new events from the process.
        foreach ($this->readEvents() as $event) {
            if ($event->runId === $runId) {
                yield $event;
            }
        }
    }

    public function cancel(string $runId): void
    {
        $this->activeRunId = $runId;
        $this->ensureProcessRunning();

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: 'cancel',
            runId: $runId,
        );

        try {
            $this->writeCommand($cmd);
        } catch (\RuntimeException) {
            // Pipe may have broken — restart and retry once.
            $this->ensureProcessRunning();
            $this->writeCommand($cmd);
        }
    }

    private function ensureProcessRunning(): void
    {
        if (null !== $this->process && $this->isProcessRunning()) {
            return;
        }

        $hadRunningProcess = null !== $this->process;

        if ($hadRunningProcess) {
            $this->enforceRestartRateLimit();
        }

        $this->stopProcess();
        $this->spawnProcess();

        // If we had an active run before the crash, resume it transparently.
        if ($hadRunningProcess && null !== $this->activeRunId) {
            $this->waitForRuntimeReady();
            $this->writeCommand(new RuntimeCommand(
                id: uniqid('cmd_', true),
                type: 'resume',
                runId: $this->activeRunId,
            ));
            $this->autoResumed = true;
        }
    }

    /**
     * Spawn the controller child process and set up pipe plumbing.
     */
    private function spawnProcess(): void
    {
        $consolePath = $this->projectDir.'/bin/console';

        if (!is_file($consolePath)) {
            throw new \RuntimeException(\sprintf('Console not found at %s', $consolePath));
        }

        $runtimeCwd = getcwd();
        if (false === $runtimeCwd) {
            throw new \RuntimeException('No current working directory available for controller process.');
        }

        $descriptors = [
            0 => ['pipe', 'r'], // stdin: parent writes JSONL commands
            1 => ['pipe', 'w'], // stdout: parent reads JSONL events
            2 => ['pipe', 'w'], // stderr: diagnostics/log leakage
        ];

        $currentEnv = getenv();
        $env = array_merge(\is_array($currentEnv) ? $currentEnv : $_ENV, [
            'APP_ENV' => $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev',
            'APP_DEBUG' => $_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? '1',
            // Controller/worker mode must use real async queues. The parent
            // TUI process defaults to sync:// so --transport=in-process remains
            // usable without a consumer pool.
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => 'doctrine://default?queue_name=run_control',
            'HATFIELD_LLM_TRANSPORT_DSN' => 'doctrine://default?queue_name=llm',
            'HATFIELD_TOOL_TRANSPORT_DSN' => 'doctrine://default?queue_name=tool',
        ]);

        $pipes = [];
        $process = @proc_open(
            [\PHP_BINARY, $consolePath, 'agent', '--controller', '--cwd='.$runtimeCwd],
            $descriptors,
            $pipes,
            $runtimeCwd,
            $env,
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('Failed to start controller process via proc_open().');
        }

        /* @var array<int, resource> $pipes */
        $this->process = $process;
        $this->pipes = $pipes;
        $this->stdoutBuffer = '';
        $this->stderrBuffer = '';
        $this->runtimeReadyReceived = false;

        stream_set_blocking($this->pipes[0], true);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    /**
     * Enforce restart rate limiting: max MAX_RESTARTS per RESTART_WINDOW seconds.
     *
     * @throws \RuntimeException when the restart limit is exceeded
     */
    private function enforceRestartRateLimit(): void
    {
        $now = microtime(true);

        // Prune timestamps outside the sliding window.
        foreach ($this->restartTimestamps as $i => $ts) {
            if ($now - $ts > self::RESTART_WINDOW) {
                unset($this->restartTimestamps[$i]);
            }
        }

        if (\count($this->restartTimestamps) >= self::MAX_RESTARTS) {
            throw new \RuntimeException(\sprintf('Controller process has crashed too many times (%d restarts in %.0fs).', self::MAX_RESTARTS, self::RESTART_WINDOW));
        }

        $this->restartTimestamps[] = $now;
    }

    /**
     * Wait for the controller to emit runtime.ready before sending commands.
     *
     * The controller boots the kernel and launches consumers before entering
     * the event loop. This method blocks until runtime.ready is received, up to
     * 15 seconds, and includes controller stderr when startup fails.
     */
    private function waitForRuntimeReady(): void
    {
        if ($this->runtimeReadyReceived) {
            return;
        }

        $timeout = 15.0;
        $start = microtime(true);

        while (microtime(true) - $start < $timeout) {
            foreach ($this->readEvents() as $event) {
                if ('runtime.ready' === $event->type) {
                    $this->runtimeReadyReceived = true;

                    return;
                }

                $this->eventBuffer->enqueue($event);
            }

            $this->assertProcessStillRunning('waiting for runtime.ready');

            usleep(10_000);
        }

        throw new \RuntimeException('Controller did not emit runtime.ready within '.$timeout.'s'."\n".$this->diagnosticOutput());
    }

    private function writeCommand(RuntimeCommand $command): void
    {
        if (null === $this->process || !isset($this->pipes[0]) || !\is_resource($this->pipes[0])) {
            throw new \RuntimeException('Controller stdin pipe is not available. '.$this->diagnosticOutput());
        }

        $line = JsonlCodec::encodeCommand($command);
        $written = @fwrite($this->pipes[0], $line);
        if (false === $written || $written < \strlen($line)) {
            throw new \RuntimeException('Failed to write command to controller stdin. '.$this->diagnosticOutput());
        }

        fflush($this->pipes[0]);
    }

    /**
     * @return iterable<RuntimeEvent>
     */
    private function readEvents(): iterable
    {
        if (null === $this->process) {
            return;
        }

        $this->drainStderr();

        if (!isset($this->pipes[1]) || !\is_resource($this->pipes[1])) {
            return;
        }

        $chunk = stream_get_contents($this->pipes[1]);
        if (false === $chunk || '' === $chunk) {
            return;
        }

        $this->stdoutBuffer .= $chunk;
        $lastNewline = strrpos($this->stdoutBuffer, "\n");
        if (false === $lastNewline) {
            return;
        }

        $complete = substr($this->stdoutBuffer, 0, $lastNewline + 1);
        $this->stdoutBuffer = substr($this->stdoutBuffer, $lastNewline + 1);

        foreach (explode("\n", $complete) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            try {
                yield JsonlCodec::decodeEvent($trimmed);
            } catch (\JsonException|\RuntimeException) {
                // Skip malformed stdout lines, but preserve them as diagnostics.
                $this->stderrBuffer .= "\n[malformed stdout] ".$trimmed;
                continue;
            }
        }
    }

    private function assertProcessStillRunning(string $context): void
    {
        if (null === $this->process || $this->isProcessRunning()) {
            return;
        }

        throw new \RuntimeException(\sprintf('Controller process exited while %s. %s', $context, $this->diagnosticOutput()));
    }

    /** @phpstan-impure */
    private function isProcessRunning(): bool
    {
        if (null === $this->process) {
            return false;
        }

        $status = proc_get_status($this->process);

        return true === $status['running'];
    }

    private function drainStderr(): void
    {
        if (!isset($this->pipes[2]) || !\is_resource($this->pipes[2])) {
            return;
        }

        $chunk = stream_get_contents($this->pipes[2]);
        if (false !== $chunk && '' !== $chunk) {
            $this->stderrBuffer .= $chunk;
        }
    }

    private function diagnosticOutput(): string
    {
        $this->drainStderr();

        $stderr = trim($this->stderrBuffer);
        $stdout = trim($this->stdoutBuffer);

        return \sprintf(
            'Controller diagnostics: stderr=%s stdout_buffer=%s',
            '' !== $stderr ? $stderr : '<empty>',
            '' !== $stdout ? $stdout : '<empty>',
        );
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

        if ($this->isProcessRunning()) {
            @proc_terminate($this->process, \SIGTERM);
            $deadline = microtime(true) + 3.0;
            while ($this->isProcessRunning() && microtime(true) < $deadline) {
                usleep(50_000);
            }
            if ($this->isProcessRunning()) {
                @proc_terminate($this->process, \SIGKILL);
            }
        }

        @proc_close($this->process);
        $this->process = null;
    }
}
