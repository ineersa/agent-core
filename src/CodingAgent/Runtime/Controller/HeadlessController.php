<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Contract\Tool\ToolExecutionSettingsInterface;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Non-blocking headless controller using Revolt event loop.
 *
 * Orchestrates the controller event loop: reads JSONL commands from stdin,
 * ACKs immediately, dispatches through Symfony EventDispatcher to focused
 * #[AsEventListener] command handlers, and delegates event emit and LLM
 * stdout polling to RuntimeEventEmitter and LlmStdoutPoller respectively.
 *
 * Event sources:
 * - Canonical events: drained by RuntimeEventEmitter from events.jsonl via
 *   InProcessAgentSessionClient (seq > 0).
 * - Transient streaming deltas: polled by LlmStdoutPoller from the LLM
 *   consumer child process stdout pipe.
 *
 * Command protocol:
 *   TUI → stdin JSONL → controller parses → emits command.ack → dispatches event
 *   Controller → stdout JSONL → TUI reads events including command.ack
 *
 * @see ControllerCommandEvent
 * @see ConsumerSupervisor
 * @see RuntimeEventEmitter
 * @see LlmStdoutPoller
 */
final class HeadlessController
{
    /** 5s consumer supervision interval. */
    private const float SUPERVISE_INTERVAL = 5.0;

    private bool $shuttingDown = false;

    /**
     * Session identifier from HATFIELD_SESSION_ID env var.
     * Used to scope orphan cleanup to only this session's consumers.
     */
    private readonly string $sessionId;

    public function __construct(
        private readonly ConsumerSupervisor $consumerSupervisor,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly ToolExecutionSettingsInterface $toolExecutionSettings,
        private readonly RuntimeExceptionBoundary $boundary,
        private readonly RuntimeEventEmitter $emitter,
        /**
         * Optional override for parallel tool messenger consumers.
         * Values <= 0 use tools.execution.max_parallelism from settings.
         */
        private readonly int $toolWorkerCount = 0,
        /**
         * Optional background process manager for session-scoped cleanup
         * on graceful controller shutdown (SIGTERM/SIGINT).
         */
        private readonly ?BackgroundProcessManager $bgProcessManager = null,
        /**
         * Optional tool question poller for cross-process tool questions
         * (e.g. bash background prompts). When provided, polls the DB for
         * un-emitted tool questions and emits RuntimeEvents to the TUI.
         */
        private readonly ?ToolQuestionPoller $toolQuestionPoller = null,
        /**
         * Optional background process completion poller for sending
         * follow-up notifications when a backgrounded process finishes.
         * When provided, polls the DB for completed background processes
         * and sends follow_up UserCommands to the agent session.
         */
        private readonly ?BackgroundProcessCompletionPoller $bgProcessCompletionPoller = null,
    ) {
        $this->sessionId = $_SERVER['HATFIELD_SESSION_ID'] ?? $_ENV['HATFIELD_SESSION_ID'] ?? 'unknown';
    }

    public function run(): int
    {
        $this->emitter->openStdout();

        // Wire fatal shutdown: when stdout write fails, the emitter needs to
        // trigger the full controller shutdown sequence (consumer supervision
        // + bg process cleanup) before stopping the event loop.
        $this->emitter->setFatalShutdownHandler(function (): void {
            $this->shutdown();
        });

        // Reap orphaned messenger:consume processes left behind by SIGKILL'd
        // previous runs. Only kills processes whose parent is init (ppid=1)
        // and whose CWD matches ours — never touches consumers owned by
        // a living controller instance.
        $this->killOrphanedConsumers();

        // Wire the consumer abandonment callback so the TUI sees a
        // protocol error when a consumer is permanently lost instead of
        // sitting on "Working..." indefinitely.
        $this->consumerSupervisor->onConsumerAbandoned(function (string $key, string $transportName): void {
            $this->emitter->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: [
                    'error' => \sprintf(
                        'Consumer abandoned after restart limit: transport=%s key=%s. Some agent capabilities may be unavailable.',
                        $transportName,
                        $key,
                    ),
                    'transport' => $transportName,
                ],
            ));
        });

        $this->emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RuntimeReady->value,
            runId: '',
            seq: 0,
            payload: ['version' => '1.0', 'transport' => 'controller'],
        ));

        // Launch messenger consumers for async execution and command transports.
        $this->consumerSupervisor->launch('run_control');
        $this->consumerSupervisor->launch('llm');
        // tool consumers: N parallel workers for concurrent tool execution.
        // N defaults to tools.execution.max_parallelism.
        $effectiveWorkerCount = $this->toolWorkerCount > 0
            ? $this->toolWorkerCount
            : max(1, $this->toolExecutionSettings->maxParallelism());
        $this->consumerSupervisor->launchMultiple('tool', $effectiveWorkerCount);
        // - run_control consumes StartRun, ApplyCommand, AdvanceRun (ASYNC-05)
        // - llm consumes ExecuteLlmStep (ASYNC-04)
        // - tool consumes ExecuteToolCall (ASYNC-04)
        // - mcp consumes McpInitializeSessionCommand, McpRefreshCatalogCommand,
        //   McpDisconnectSessionCommand, and McpCallToolCommand (MCP-05).
        //   Exactly ONE mcp consumer per session — serialized MCP calls and
        //   single owner for STDIO child processes.
        // Scheduler consumer: dispatches recurring background tasks such as
        // file mention index refresh.  All future periodic background work
        // should use Scheduler scheduled tasks, not ad-hoc TUI tick process
        // spawning.
        $this->consumerSupervisor->launch('scheduler_default');
        $this->consumerSupervisor->launch('mcp');

        // Non-blocking stdin: read JSONL commands from TUI.
        EventLoop::onReadable(\STDIN, function (string $watcherId, $stream): void {
            if ($this->shuttingDown) {
                return;
            }

            $line = fgets($stream);
            if (false === $line) {
                // EOF or error — the parent TUI process has disconnected
                // (crash, SIGKILL, terminal close).  If we only cancel the
                // watcher, the event loop and consumer subprocesses will
                // continue running as orphans consuming resources silently.
                $this->logger->warning('Controller stdin EOF — parent process disconnected, shutting down', [
                    'component' => 'HeadlessController',
                    'event_type' => 'stdin_eof',
                    'session_id' => $this->sessionId,
                ]);
                EventLoop::cancel($watcherId);
                $this->shutdown();
                EventLoop::getDriver()->stop();

                return;
            }

            $trimmed = trim($line);
            if ('' === $trimmed) {
                return;
            }

            $this->handleCommandLine($trimmed);
        });

        // Poll LLM consumer stdout for transient streaming deltas.
        $poller = new LlmStdoutPoller(
            $this->consumerSupervisor,
            $this->emitter,
            $this->boundary,
            $this->logger,
        );
        $poller->startPollLoop(0.01);

        // Poll tool_question DB table for un-emitted tool questions.
        // Runs in-process alongside the controller rather than relying on
        // tool consumer stdout, which is not currently polled.
        $this->toolQuestionPoller?->startPollLoop();

        // Poll background_process DB table for completed, explicitly-backgrounded
        // processes and send follow-up notifications to the agent session.
        // This mirrors pi's bg-process.ts finalizeBackgroundProcess behavior.
        $this->bgProcessCompletionPoller?->startPollLoop();

        // Periodic event drain via emitter.
        $this->emitter->startDrainLoop(0.05);

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
            $emit = $this->emitter->emit(...);
            $event = new ControllerCommandEvent($command, $emit, $this->sessionId);
            $this->dispatcher->dispatch($event);
        } catch (\Throwable $e) {
            // Delegate capture=0 rethrow to boundary.
            // If we reach here, capture mode is enabled.
            $this->boundary->catch($e, 'headless_controller.command_dispatch_failed', [
                'command_type' => $command->type,
                'command_id' => $command->id,
            ]);

            // Capture mode: log and emit a command_rejected event so
            // the TUI shows the user what happened.
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
            $this->emitter->emit(new RuntimeEvent(
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
        $this->emitter->emit(new RuntimeEvent(
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
        $this->emitter->emit(new RuntimeEvent(
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
        $this->emitter->shutdown();

        $this->logger->info('Controller shutting down gracefully');

        $this->consumerSupervisor->shutdown();
        $this->bgProcessManager?->shutdownCleanup($this->sessionId);
    }
}
