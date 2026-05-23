<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Non-blocking headless controller using Revolt event loop.
 *
 * Orchestrates the controller event loop: reads JSONL commands from stdin,
 * ACKs immediately, dispatches through Symfony EventDispatcher to focused
 * #[AsEventListener] command handlers, and forwards runtime events to
 * stdout for the TUI.
 *
 * Event sources:
 * - Canonical events: polled from events.jsonl via InProcessAgentSessionClient
 *   event drain (seq > 0).
 * - Transient streaming deltas: read from the LLM consumer child process stdout
 *   pipe. Stream subscribers inside the LLM consumer write JSONL to STDOUT;
 *   the controller reads incrementally and forwards.
 *
 * Canonical runtime events (committed by consumer processes to events.jsonl)
 * are polled via InProcessAgentSessionClient and forwarded to TUI through
 * a periodic event drain timer. Transient streaming deltas flow through
 * the LLM consumer stdout pipe.
 *
 * Responsibilities are delegated to collaborators:
 * - EventDispatcherInterface routes commands to #[AsEventListener] handlers
 * - ConsumerSupervisor manages messenger:consume child processes
 *
 * Command protocol:
 *   TUI → stdin JSONL  → controller parses → emits command.ack → dispatches event
 *   Controller → stdout JSONL → TUI reads events including command.ack
 *
 * @see ControllerCommandEvent
 * @see ConsumerSupervisor
 * @see InProcessAgentSessionClient
 */
final class HeadlessController
{
    /** 10ms LLM stdout poll interval for responsive streaming. */
    private const float STREAMING_EVENT_POLL_INTERVAL = 0.01;

    /** 50ms event drain poll interval. */
    private const float EVENT_DRAIN_INTERVAL = 0.05;

    /** 5s consumer supervision interval. */
    private const float SUPERVISE_INTERVAL = 5.0;

    /** @var resource|null */
    private $stdout;

    private bool $shuttingDown = false;

    /** @var array<string, int> runId => lastForwardedSeq */
    private array $runEventCursors = [];

    /**
     * Session identifier from HATFIELD_SESSION_ID env var.
     * Used to scope orphan cleanup to only this session's consumers.
     */
    private readonly string $sessionId;

    /**
     * Partial-line buffer for LLM consumer stdout reads.
     *
     * getIncrementalOutput() can return partial JSONL (no trailing newline).
     * This buffer accumulates incomplete lines across poll cycles so they
     * are not silently lost. Complete lines (trailing \n) are processed;
     * leftovers stay in the buffer for the next poll.
     *
     * @see JsonlProcessAgentSessionClient for the same pattern
     */
    private string $llmStdoutBuffer = '';

    public function __construct(
        private readonly ConsumerSupervisor $consumerSupervisor,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly ?InProcessAgentSessionClient $eventClient = null,
    ) {
        $this->sessionId = $_SERVER['HATFIELD_SESSION_ID'] ?? $_ENV['HATFIELD_SESSION_ID'] ?? 'unknown';
    }

    public function run(): int
    {
        $this->stdout = fopen('php://stdout', 'w');
        if (false === $this->stdout) {
            throw new \RuntimeException('Cannot open stdout for controller mode');
        }

        // Reap orphaned messenger:consume processes left behind by SIGKILL'd
        // previous runs. Only kills processes whose parent is init (ppid=1)
        // and whose CWD matches ours — never touches consumers owned by
        // a living controller instance.
        $this->killOrphanedConsumers();

        $this->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RuntimeReady->value,
            runId: '',
            seq: 0,
            payload: ['version' => '1.0', 'transport' => 'controller'],
        ));

        // Launch messenger consumers for async execution and command transports.
        $this->consumerSupervisor->launch('run_control');
        $this->consumerSupervisor->launch('llm');
        $this->consumerSupervisor->launch('tool');
        // - run_control consumes StartRun, ApplyCommand, AdvanceRun (ASYNC-05)
        // - llm consumes ExecuteLlmStep (ASYNC-04)
        // - tool consumes ExecuteToolCall (ASYNC-04)

        // Non-blocking stdin: read JSONL commands from TUI.
        EventLoop::onReadable(\STDIN, function (string $watcherId, $stream): void {
            if ($this->shuttingDown) {
                return;
            }

            $line = fgets($stream);
            if (false === $line) {
                // EOF or error — close stdin watcher.
                EventLoop::cancel($watcherId);

                return;
            }

            $trimmed = trim($line);
            if ('' === $trimmed) {
                return;
            }

            $this->handleCommandLine($trimmed);
        });

        // Poll LLM consumer stdout for transient streaming deltas.
        EventLoop::repeat(self::STREAMING_EVENT_POLL_INTERVAL, function (): void {
            if ($this->shuttingDown) {
                return;
            }

            $this->pollLlmStdout();
        });

        // Periodic event drain: poll canonical events from events.jsonl via
        // InProcessAgentSessionClient and forward to TUI. Only runs when
        // there are active runs registered via emit(). The TUI deduplicates
        // by seq, so re-forwarding already-seen events is harmless.
        EventLoop::repeat(self::EVENT_DRAIN_INTERVAL, function (): void {
            if ($this->shuttingDown || null === $this->eventClient) {
                return;
            }

            // Snapshot active run IDs to avoid modification during iteration.
            $activeRuns = array_keys($this->runEventCursors);

            foreach ($activeRuns as $runId) {
                $cursor = $this->runEventCursors[$runId] ?? null;
                if (null === $cursor) {
                    continue; // Run was cleaned up during iteration.
                }

                try {
                    foreach ($this->eventClient->events($runId) as $event) {
                        // Skip transient streaming deltas (seq=0) — these are
                        // delivered via LLM consumer stdout pipe, not canonical events.
                        if (0 === $event->seq) {
                            continue;
                        }

                        if ($event->seq <= $cursor) {
                            continue;
                        }

                        $this->emitInternal($event);

                        if ($event->seq > 0) {
                            $this->runEventCursors[$runId] = max($cursor, $event->seq);
                            $cursor = $this->runEventCursors[$runId];
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Controller event drain error', [
                        'run_id' => $runId,
                        'exception' => $e,
                    ]);
                }
            }
        });

        // Consumer supervision: check child process health.
        EventLoop::repeat(self::SUPERVISE_INTERVAL, function (): void {
            if ($this->shuttingDown) {
                return;
            }

            $this->consumerSupervisor->supervise();
        });

        // Graceful shutdown on termination signals.
        EventLoop::onSignal(\SIGTERM, function (): void {
            $this->shutdown();
            EventLoop::getDriver()->stop();
        });
        EventLoop::onSignal(\SIGINT, function (): void {
            $this->shutdown();
            EventLoop::getDriver()->stop();
        });

        EventLoop::run();

        return Command::SUCCESS;
    }

    // ── LLM stdout polling ───────────────────────────────────────────────

    /**
     * Poll the LLM consumer child process stdout for transient streaming deltas.
     *
     * Stream subscribers running inside the LLM consumer process write JSONL
     * lines to STDOUT. This reads incremental output from the child process
     * pipe, accumulates partial lines across polls, parses valid RuntimeEvent
     * JSONL, and forwards to the TUI.
     *
     * Non-JSONL lines (e.g. messenger:consume output) are silently skipped.
     * Incomplete lines (no trailing \n) are buffered and completed on the
     * next poll cycle.
     *
     * @see JsonlProcessAgentSessionClient::consumeOutput() for the same pattern
     */
    private function pollLlmStdout(): void
    {
        $llmProcess = $this->consumerSupervisor->getProcess('llm');

        if (null === $llmProcess) {
            return;
        }

        $output = $llmProcess->getIncrementalOutput();

        if ('' === $output && '' === $this->llmStdoutBuffer) {
            return;
        }

        // Accumulate with partial-line buffer (same pattern as JsonlProcessAgentSessionClient).
        $this->llmStdoutBuffer .= $output;
        $lastNewline = strrpos($this->llmStdoutBuffer, "\n");
        if (false === $lastNewline) {
            // No complete line yet — wait for more data.
            return;
        }

        $complete = substr($this->llmStdoutBuffer, 0, $lastNewline + 1);
        $this->llmStdoutBuffer = substr($this->llmStdoutBuffer, $lastNewline + 1);

        foreach (explode("\n", $complete) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            $data = json_decode($trimmed, true);

            if (!\is_array($data) || !isset($data['v'], $data['type'])) {
                // Not a valid RuntimeEvent — likely messenger:consume
                // informational output. Silently skip.
                continue;
            }

            try {
                $event = RuntimeEvent::fromArray($data);
                $this->emit($event);
            } catch (\Throwable $e) {
                $this->logger->debug('Skipping unparseable JSONL from LLM consumer stdout', [
                    'line' => mb_substr($trimmed, 0, 200),
                    'exception' => $e,
                ]);
            }
        }
    }

    // ── Command handling ─────────────────────────────────────────────────

    private function handleCommandLine(string $line): void
    {
        $command = $this->decodeCommand($line);
        if (null === $command) {
            return;
        }

        // ACK immediately before any processing.
        $this->ackCommand($command);

        // Dispatch via Symfony EventDispatcher to #[AsEventListener] handlers.
        // Command bus dispatch is non-blocking — messages are routed to
        // async Doctrine transports.
        try {
            $emit = $this->emit(...);
            $event = new ControllerCommandEvent($command, $emit);
            $this->dispatcher->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('Controller command dispatch failed', [
                'command_type' => $command->type,
                'command_id' => $command->id,
                'exception' => $e,
            ]);
            $this->emitCommandRejected($command, $e->getMessage());
        }
    }

    private function decodeCommand(string $line): ?RuntimeCommand
    {
        try {
            return JsonlCodec::decodeCommand($line);
        } catch (\Throwable $e) {
            $this->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => \sprintf('JSONL decode error: %s', $e->getMessage())],
            ));

            return null;
        }
    }

    // ── ACK protocol ─────────────────────────────────────────────────────

    private function ackCommand(RuntimeCommand $command): void
    {
        $this->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::CommandAck->value,
            runId: $command->runId ?? '',
            seq: 0,
            payload: [
                'commandId' => $command->id,
                'commandType' => $command->type,
                'status' => 'accepted',
            ],
        ));
    }

    private function emitCommandRejected(RuntimeCommand $command, string $reason): void
    {
        $this->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::CommandRejected->value,
            runId: $command->runId ?? '',
            seq: 0,
            payload: [
                'commandId' => $command->id,
                'commandType' => $command->type,
                'status' => 'rejected',
                'reason' => $reason,
            ],
        ));
    }

    // ── Shutdown ─────────────────────────────────────────────────────────

    /**
     * Reap orphaned messenger:consume processes on startup.
     *
     * When a previous TUI process was SIGKILL'd, the controller's onSignal
     * handler never fires, leaving messenger:consume children adopted by init
     * (ppid=1). These orphans hold the SQLite DB lock and prevent new
     * controllers from booting.
     *
     * Only kills processes whose:
     *  - parent PID is 1 (truly orphaned, not owned by another controller)
     *  - environment contains our session ID (HATFIELD_SESSION_ID match)
     *
     * Multi-instance safe: living controllers' consumers have ppid != 1
     * and different session IDs won't match ours.
     */
    private function killOrphanedConsumers(): void
    {
        // Find all messenger:consume PIDs.
        $pgrepOutput = [];
        $pgrepStatus = 0;
        exec('pgrep -f messenger:consume 2>/dev/null', $pgrepOutput, $pgrepStatus);
        if (0 !== $pgrepStatus || [] === $pgrepOutput) {
            return;
        }

        $killedPids = [];

        foreach ($pgrepOutput as $line) {
            $pid = (int) trim($line);
            if ($pid <= 0) {
                continue;
            }

            $stat = @file_get_contents("/proc/{$pid}/stat");
            if (false === $stat) {
                continue;
            }

            // ppid is the 4th space-separated field in /proc/pid/stat.
            $fields = explode(' ', $stat);
            $ppid = (int) ($fields[3] ?? 0);
            // Check parent PID: only ppid=1 means orphaned.
            if (1 !== $ppid) {
                // Parent still alive — belongs to another controller.
                continue;
            }

            // Verify this orphaned consumer belongs to our session by checking
            // /proc/pid/environ for HATFIELD_SESSION_ID. \0-separated env entries.
            $pidEnv = @file_get_contents("/proc/{$pid}/environ");
            if (false === $pidEnv) {
                continue;
            }

            $sessionMarker = "HATFIELD_SESSION_ID={$this->sessionId}";
            if (!str_contains($pidEnv, $sessionMarker)) {
                // Belongs to a different session — leave it alone.
                continue;
            }

            $this->logger->info('Reaping orphaned consumer', [
                'pid' => $pid,
                'ppid' => $ppid,
                'session_id' => $this->sessionId,
            ]);

            // Send SIGTERM, wait briefly, escalate to SIGKILL if needed.
            @posix_kill($pid, \SIGTERM);
            $killedPids[] = $pid;
        }

        if ([] !== $killedPids) {
            // Give the processes a moment to clean up.
            usleep(500_000);

            foreach ($killedPids as $pid) {
                // Check if still running.
                if (false !== @file_get_contents("/proc/{$pid}/stat")) {
                    @posix_kill($pid, \SIGKILL);
                    $this->logger->warning('Orphaned consumer did not stop gracefully, sent SIGKILL', [
                        'pid' => $pid,
                    ]);
                }
            }
        }
    }

    private function shutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;

        $this->logger->info('Controller shutting down gracefully');

        $this->consumerSupervisor->shutdown();
    }

    // ── Output ───────────────────────────────────────────────────────────

    /**
     * Emit a runtime event to TUI stdout.
     *
     * Automatically registers run event cursors when start/resume events
     * are emitted, and releases them on terminal events.
     */
    private function emit(RuntimeEvent $event): void
    {
        // Auto-register/release event drain cursors based on event type.
        if (RuntimeEventTypeEnum::RunStarted->value === $event->type
            || RuntimeEventTypeEnum::RunResumed->value === $event->type
        ) {
            $this->runEventCursors[$event->runId] = $this->runEventCursors[$event->runId] ?? 0;
        } elseif (RuntimeEventTypeEnum::RunCompleted->value === $event->type
            || RuntimeEventTypeEnum::RunFailed->value === $event->type
            || RuntimeEventTypeEnum::RunCancelled->value === $event->type
        ) {
            unset($this->runEventCursors[$event->runId]);
        }

        $this->emitInternal($event);
    }

    private function emitInternal(RuntimeEvent $event): void
    {
        if (null === $this->stdout) {
            return;
        }

        $line = JsonlCodec::encodeEvent($event);
        $written = @fwrite($this->stdout, $line);

        if (false === $written || 0 === $written) {
            $error = error_get_last();
            $this->logger->error('Controller stdout write failed, initiating shutdown', [
                'event_type' => $event->type,
                'error' => $error['message'] ?? 'unknown',
            ]);
            $this->shutdown();
            EventLoop::getDriver()->stop();

            return;
        }

        fflush($this->stdout);
    }
}
