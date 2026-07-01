<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Psr\Log\LoggerInterface;

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

    /**
     * The most recently active run ID tracked across start/resume/send/cancel.
     * Used to auto-resume a run after the controller process is restarted.
     */
    private ?string $activeRunId = null;

    /**
     * Session-scoped identifier (the full run ID).
     * Used to scope Doctrine Messenger queue names per session so that
     * cross-session message stealing cannot occur. One session per
     * Hatfield instance — session_id === run_id per AGENTS.md.
     */
    private ?string $sessionId = null;

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
     * Session ID that the currently running controller process was
     * spawned with. When start() or resume() sets a different
     * sessionId, ensureProcessRunning() must restart the process so
     * the controller and its consumer subprocesses use the correct
     * session-scoped queue names.
     */
    private ?string $processSessionId = null;

    /**
     * Timestamps of recent controller restarts for rate-limiting.
     *
     * @var array<int, float>
     */
    private array $restartTimestamps = [];

    public function __construct(
        private readonly RuntimeProcessConfig $runtimeConfig,
        private readonly PromptTemplatesRuntimeConfig $promptTemplatesConfig,
        private readonly LoggerInterface $logger,
    ) {
        $this->eventBuffer = new \SplQueue();
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

        // Derive session-scoped queue names from the request runId before
        // spawning the controller process, so its env vars carry the right DSNs.
        // session_id === run_id per AGENTS.md — no truncation needed.
        $runId = '' !== $request->runId ? $request->runId : null;
        $this->sessionId = $runId;

        $this->ensureProcessRunning();

        try {
            $this->waitForRuntimeReady();
        } catch (\RuntimeException $e) {
            // Clean up orphaned controller and consumer processes before
            // propagating the error (issue #183).  Without this, a failed
            // shell-command follow-up start() leaves the controller and
            // all messenger:consume grandchildren orphaned.
            $this->logger->warning('start: stopping process after runtime.ready timeout', [
                'component' => 'JsonlProcessAgentSessionClient',
                'event_type' => 'start_process_cleanup',
                'session_id' => $this->sessionId ?? '',
                'run_id' => $runId ?? '',
                'error' => $e->getMessage(),
            ]);
            $this->stopProcess();

            throw $e;
        }

        // activeRunId was set above from the request runId for crash recovery
        // before spawning the process, so if the controller dies before
        // run.started arrives, auto-resume will target it.
        if (null !== $runId) {
            $this->activeRunId = $runId;
        }

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: 'start_run',
            runId: $runId ?? '',
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

        try {
            while (microtime(true) - $start < $timeout) {
                /** @var RuntimeEvent $event */
                foreach ($this->readEvents() as $event) {
                    if ('run.started' === $event->type || 'run_started' === $event->type) {
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
        } catch (\RuntimeException $e) {
            // The assertProcessStillRunning() call throws when the controller
            // exits prematurely.  Clean up orphaned processes before propagating
            // (issue #183).
            $this->logger->warning('start: stopping process after controller exit during run_started wait', [
                'component' => 'JsonlProcessAgentSessionClient',
                'event_type' => 'start_process_cleanup',
                'session_id' => $this->sessionId ?? '',
                'run_id' => $runId ?? '',
                'error' => $e->getMessage(),
            ]);
            $this->stopProcess();

            throw $e;
        }

        // Timeout reached — clean up before propagating.
        $this->logger->warning('start: stopping process after run_started timeout', [
            'component' => 'JsonlProcessAgentSessionClient',
            'event_type' => 'start_process_cleanup',
            'session_id' => $this->sessionId ?? '',
            'run_id' => $runId ?? '',
            'timeout_seconds' => $timeout,
        ]);
        $this->stopProcess();

        throw new \RuntimeException('Agent process did not emit run_started event within '.$timeout.'s'."\n".$this->diagnosticOutput());
    }

    public function attach(string $runId): RunHandle
    {
        // Reset stale flags from prior sessions / crash-recovery cycles.
        // ensureProcessRunning() may have set autoResumed for a different
        // session during events()/send()/cancel(); only an auto-resume
        // triggered by THIS attach() call should suppress the write below.
        // Mirrors start() which also resets these before a new run.
        $this->autoResumed = false;

        // runId is the session ID — update session-scoped queue DSNs.
        $this->sessionId = $runId;
        $this->activeRunId = $runId;
        $this->ensureProcessRunning();

        // Symmetric with start(): wait for runtime.ready before sending
        // commands, so the controller and its consumer subprocesses have
        // booted and the event loop is accepting input.  If the process
        // was already running (no restart), runtimeReadyReceived is still
        // true from the prior start() / crash-recovery resume, so this is
        // a near-instant no-op.
        $this->waitForRuntimeReady();

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

        $this->writeCommandWithRetry($cmd);

        return new RunHandle(runId: $runId, status: 'attached');
    }

    public function send(string $runId, UserCommand $command): void
    {
        $this->activeRunId = $runId;
        $this->ensureProcessRunning();

        $type = match ($command->type) {
            'steer', 'message' => 'user_message',
            'follow_up' => 'follow_up',
            'append_message' => 'append_message',
            'answer_human' => 'answer_human',
            'answer_tool_question' => 'answer_tool_question',
            'shell_command' => 'shell_command',
            'rewind_to_turn' => 'rewind_to_turn',
            'tree_navigate_to_turn' => 'tree_navigate_to_turn',
            default => throw new \InvalidArgumentException(\sprintf('Unknown command type: "%s"', $command->type)),
        };

        $payload = match ($type) {
            'answer_tool_question' => array_filter([
                'request_id' => $command->payload['request_id'] ?? '',
                'answer' => $command->payload['answer'] ?? null,
            ], static fn (mixed $v): bool => null !== $v),
            'shell_command' => array_filter([
                'text' => $command->text,
                'standalone' => true === ($command->payload['standalone'] ?? false) ? true : null,
            ], static fn (mixed $v): bool => null !== $v),
            'rewind_to_turn', 'tree_navigate_to_turn' => array_filter([
                'turn_no' => $command->payload['turn_no'] ?? null,
                'file_choice' => $command->payload['file_choice'] ?? null,
            ], static fn (mixed $v): bool => null !== $v),
            default => array_filter([
                'text' => $command->text,
                'question_id' => $command->payload['question_id'] ?? null,
                'answer' => $command->payload['answer'] ?? null,
            ], static fn (mixed $v): bool => null !== $v),
        };

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: $type,
            runId: $runId,
            payload: $payload,
        );

        $this->writeCommandWithRetry($cmd);
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

        $this->writeCommandWithRetry($cmd);
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
        $this->activeRunId = $runId;
        $this->ensureProcessRunning();

        $payload = array_filter([
            'custom_instructions' => $customInstructions,
        ], static fn (mixed $v): bool => null !== $v);

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: 'compact',
            runId: $runId,
            payload: $payload,
        );

        $this->writeCommandWithRetry($cmd);
    }

    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle
    {
        $this->activeRunId = $sessionId;
        $this->sessionId = $sessionId;
        $this->ensureProcessRunning();
        $this->waitForRuntimeReady();

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: 'shell_command',
            runId: $sessionId,
            payload: [
                'text' => $command,
                'cwd' => $cwd,
                'standalone' => true,
            ],
        );

        $this->writeCommandWithRetry($cmd);

        return new RunHandle(runId: $sessionId, status: 'running');
    }

    public function completeRun(string $runId): void
    {
        $this->ensureProcessRunning();

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: 'complete_run',
            runId: $runId,
        );

        $this->writeCommandWithRetry($cmd);
    }

    private function ensureProcessRunning(): void
    {
        // Force a restart when the session has changed so the controller
        // and its consumer subprocesses are launched with the new session's
        // env vars (queue DSNs, HATFIELD_SESSION_ID).
        //
        // The `null !== $processSessionId` guard avoids a harmless but
        // wasteful stopProcess() / SIGTERM wait on the very first start()
        // call, when processSessionId is still null.
        //
        // Session-change guard: the old controller process uses stale
        // queue DSNs from the previous session.  Since cancelCurrentRun()
        // already dispatched the cancel command through the old controller
        // (or skipped it for terminal runs), use a short SIGTERM grace
        // period — the old run state is preserved in the DB regardless.
        $sessionChanged = null !== $this->sessionId
            && null !== $this->processSessionId
            && $this->sessionId !== $this->processSessionId;

        if ($sessionChanged) {
            $this->stopProcess(0.5);
        }

        if (null !== $this->process && $this->isProcessRunning()) {
            return;
        }

        $hadRunningProcess = null !== $this->process;

        if ($hadRunningProcess && !$sessionChanged) {
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
        $consolePath = $this->runtimeConfig->executablePath();

        if (!is_file($consolePath)) {
            throw new \RuntimeException(\sprintf('Console not found at %s', $consolePath));
        }

        $runtimeCwd = $this->runtimeConfig->runtimeCwd();

        $descriptors = [
            0 => ['pipe', 'r'], // stdin: parent writes JSONL commands
            1 => ['pipe', 'w'], // stdout: parent reads JSONL events
            2 => ['pipe', 'w'], // stderr: diagnostics/log leakage
        ];

        $currentEnv = getenv();
        // Build session-scoped queue names so no cross-session message
        // stealing can occur. Falls back to 'default' before start().
        $queueSuffix = $this->sessionId ?? 'default';

        $env = array_merge(\is_array($currentEnv) ? $currentEnv : $_ENV, [
            'APP_ENV' => $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev',
            'APP_DEBUG' => $_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? '1',
            // Controller/worker mode must use real async queues. The parent
            // TUI process defaults to sync:// so --transport=in-process remains
            // usable without a consumer pool.
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => "doctrine://default?queue_name=run_control_{$queueSuffix}",
            'HATFIELD_LLM_TRANSPORT_DSN' => "doctrine://default?queue_name=llm_{$queueSuffix}",
            'HATFIELD_TOOL_TRANSPORT_DSN' => "doctrine://default?queue_name=tool_{$queueSuffix}",
            'HATFIELD_AGENT_TRANSPORT_DSN' => "doctrine://default?queue_name=agent_{$queueSuffix}",
            'HATFIELD_MCP_TRANSPORT_DSN' => "doctrine://default?queue_name=mcp_{$queueSuffix}",
            // Pass session ID so the controller can identify and reap its own
            // orphaned consumers when a previous session was SIGKILL'd.
            'HATFIELD_SESSION_ID' => $this->sessionId ?? 'unknown',
            // Signal an approval channel so SafeGuard (and future approval-
            // capable extensions) can prompt instead of auto-blocking. The
            // controller and its messenger consumers inherit this env var.
            'HATFIELD_APPROVAL_CHANNEL' => 'controller',
        ]);

        $pipes = [];
        $process = @proc_open(
            [
                ...$this->runtimeConfig->executableCommand(),
                'agent', '--controller', '--cwd='.$runtimeCwd,
                ...$this->promptTemplatesConfig->controllerArgs(),
            ],
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
        $this->processSessionId = $this->sessionId;

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
     * Write a command to the controller, restarting and retrying once on
     * pipe failure with structured logging.
     */
    private function writeCommandWithRetry(RuntimeCommand $command): void
    {
        try {
            $this->writeCommand($command);
        } catch (\RuntimeException $e) {
            // Pipe may have broken — restart and retry once.
            $this->logger->warning('Controller pipe broken, restarting and retrying', [
                'component' => 'JsonlProcessAgentSessionClient',
                'session_id' => $this->sessionId ?? '',
                'command_type' => $command->type,
                'command_id' => $command->id,
                'exception' => $e,
            ]);
            $this->ensureProcessRunning();
            $this->writeCommand($command);
        }
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

    /**
     * @param float $sigtermGraceSeconds How long to wait for SIGTERM before SIGKILL.
     *                                   Default 3.0s for crash recovery (allows the controller
     *                                   to clean up consumers).  Use 0.5s for intentional
     *                                   session-switch stops where the cancel has already been
     *                                   dispatched and the old run state is preserved in the DB.
     */
    private function stopProcess(float $sigtermGraceSeconds = 3.0): void
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
            $deadline = microtime(true) + $sigtermGraceSeconds;
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
