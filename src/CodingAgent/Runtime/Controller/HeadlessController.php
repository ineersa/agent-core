<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Contract\Tool\ToolExecutionSettingsInterface;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobRegistry;
use Ineersa\CodingAgent\Mcp\McpSessionLifecycleDispatcher;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerTopology;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueEnv;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Non-blocking headless controller using Revolt event loop.
 *
 * Orchestrates the controller event loop: reads JSONL commands from stdin,
 * ACKs immediately, dispatches through Symfony EventDispatcher to focused
 * #[AsEventListener] command handlers, and delegates event emit and LLM
 * consumer stdout polling to RuntimeEventEmitter via ConsumerStdoutPoller.
 *
 * Event sources:
 * - Committed canonical events: messenger consumers persist to events.jsonl, then
 *   stream mapped RuntimeEvent JSONL on stdout; ConsumerStdoutPoller forwards to TUI.
 * - Transient streaming deltas: LLM consumer stream subscribers on the same stdout pipe.
 * - events.jsonl is durable replay/recovery/export only — not the live 50ms event bus.
 *
 * Single-owner lock:
 * - Before orphan reaping / consumer launch, acquires a process-lifetime flock keyed by
 *   canonical runtime CWD + session ID so two live controllers never share session queues.
 * - Store is a dedicated FlockStore under %app.cwd%/.hatfield/tmp/controller-locks so
 *   source/worktree/PHAR controllers with the same project CWD converge (not kernel.project_dir).
 * - Lock is released only after consumers shut down (graceful path) or when the process dies.
 *
 * Command protocol:
 *   TUI → stdin JSONL → controller parses → emits command.ack → dispatches event
 *   Controller → stdout JSONL → TUI reads events including command.ack
 *
 * @see ControllerCommandEvent
 * @see ConsumerSupervisor
 * @see RuntimeEventEmitter
 * @see ConsumerStdoutPoller
 */
final class HeadlessController
{
    private const array ORPHAN_CONSUMER_PATTERNS = ['messenger:consume'];

    /** 5s consumer supervision interval. */
    private const float SUPERVISE_INTERVAL = 5.0;

    private bool $shuttingDown = false;

    // Once launched, MCP remains owned by normal supervision, including backoff
    // and abandonment. Refresh must not bypass that policy or duplicate clients.
    private bool $mcpConsumerLaunched = false;

    /**
     * Session identifier from HATFIELD_SESSION_ID env var.
     * Used to scope orphan cleanup to only this session's consumers.
     */
    private readonly string $sessionId;

    /** Held for the controller process lifetime after successful acquire. */
    private ?LockInterface $sessionOwnerLock = null;

    public function __construct(
        private readonly ConsumerSupervisor $consumerSupervisor,
        private readonly SessionSqliteQueueBrokerSupervisor $brokerSupervisor,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly ToolExecutionSettingsInterface $toolExecutionSettings,
        private readonly RuntimeExceptionBoundary $boundary,
        private readonly RuntimeEventEmitter $emitter,
        /** Project-scoped factory (not FrameworkBundle default LockFactory). */
        private readonly LockFactory $sessionOwnerLockFactory,
        private readonly string $runtimeCwd,
        private readonly McpSessionLifecycleDispatcher $mcpLifecycle,
        private readonly ExtensionAgentJobRegistry $extensionAgentJobRegistry,
        /**
         * Optional override for parallel tool messenger consumers.
         * Values <= 0 use tools.execution.max_parallelism from settings.
         */
        private readonly int $toolWorkerCount = 0,
        /**
         * Optional background process completion poller for sending
         * follow-up notifications when a backgrounded process finishes.
         * When provided, polls the DB for completed background processes
         * and sends append_message UserCommands to the agent session.
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

        // Single live owner for this project+session before reaping or launching
        // consumers. Fail before runtime.ready so a second controller never shares
        // session broker queues with a healthy first owner.
        if (!$this->acquireSessionOwnerLock()) {
            return Command::FAILURE;
        }

        // Reap orphaned messenger:consume processes left behind by SIGKILL'd
        // previous runs. Only kills processes whose parent is init (ppid=1)
        // and whose CWD matches ours — never touches consumers owned by
        // a living controller instance.
        $this->killOrphanedConsumers();

        try {
            $this->startSessionBroker();
            $this->dispatcher->dispatch(new ControllerSessionStartingEvent($this->sessionId));
        } catch (\Throwable $exception) {
            $this->brokerSupervisor->shutdownUntil(microtime(true) + 5.0);
            $this->releaseSessionOwnerLock();

            throw $exception;
        }

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

        // Launch messenger consumers before advertising readiness. Clients that
        // send start_run immediately after runtime.ready must not race empty
        // run_control/llm queues under load.
        try {
            $this->consumerSupervisor->launch('run_control');
            // One llm consumer: FiberMessageExecutionStrategy admits up to 4
            // overlapping ExecuteLlmStep handlers in that process. Parent turns
            // and parallel subagent children share the llm transport.
            $this->consumerSupervisor->launch('llm');
            // tool consumers: N parallel workers for concurrent tool execution.
            // N defaults to tools.execution.max_parallelism.
            $this->consumerSupervisor->launchMultiple('tool', $this->resolveToolWorkerCount());
            // One agent consumer: parent subagent tool orchestration/polling is isolated
            // from generic tool workers so child tool calls are not starved.
            $this->consumerSupervisor->launch('agent');
            // - run_control consumes StartRun, ApplyCommand, run results, and
            //   AdvanceRun/CompactRun transitions as the single state owner
            // - llm consumes ExecuteLlmStep (ASYNC-04)
            // - tool consumes ExecuteToolCall (generic tools; not subagent)
            // - agent consumes ExecuteToolCall when toolName=subagent (AGENT-07)
            // - mcp consumes McpInitializeSessionCommand, McpRefreshCatalogCommand,
            //   McpDisconnectSessionCommand, and McpCallToolCommand (MCP-05).
            //   Exactly ONE mcp consumer per session — serialized MCP calls and
            //   single owner for STDIO child processes.
            // Scheduler consumer: dispatches recurring background tasks such as
            // file mention index refresh.  All future periodic background work
            // should use Scheduler scheduled tasks, not ad-hoc TUI tick process
            // spawning.
            $this->consumerSupervisor->launch('scheduler_default');
            if ($this->mcpLifecycle->requiresConsumer($this->sessionId)) {
                $this->launchMcpConsumer();
            }
            // Dedicated extension-agent worker: isolated from llm/tool/run_control so
            // extension Observer/Reflector jobs do not starve main run execution.
            // Console extension loading completes before controller startup.
            if ($this->extensionAgentJobRegistry->hasHandlers()) {
                $this->consumerSupervisor->launch('extension_agent');
            }
        } catch (\Throwable $exception) {
            $deadline = microtime(true) + 5.0;
            $this->consumerSupervisor->shutdownUntil($deadline);
            $this->brokerSupervisor->shutdownUntil($deadline);
            try {
                $this->dispatcher->dispatch(new ControllerSessionShutdownEvent($this->sessionId));
            } catch (\Throwable $lifecycleException) {
                $this->logger->error('Controller session shutdown lifecycle failed after partial consumer launch', [
                    'component' => 'HeadlessController',
                    'event_type' => 'controller.session_startup_cleanup_lifecycle_failed',
                    'session_id' => $this->sessionId,
                    'exception' => $lifecycleException,
                ]);
            } finally {
                $this->releaseSessionOwnerLock();
            }

            throw $exception;
        }

        // Same event type/payload as before; emission now follows consumer launch.
        $this->emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RuntimeReady->value,
            runId: '',
            seq: 0,
            payload: ['version' => '1.0', 'transport' => 'controller'],
        ));

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

                return;
            }

            $trimmed = trim($line);
            if ('' === $trimmed) {
                return;
            }

            $this->handleCommandLine($trimmed);
        });

        // Poll all messenger consumer stdout pipes for committed + streaming RuntimeEvents.
        $stdoutPoller = new ConsumerStdoutPoller(
            $this->consumerSupervisor,
            $this->emitter,
            $this->boundary,
            $this->logger,
        );
        $stdoutPoller->startPollLoop(0.01);

        // Poll background_process DB table for completed, explicitly-backgrounded
        // processes and send follow-up notifications to the agent session.
        // This mirrors pi's bg-process.ts finalizeBackgroundProcess behavior.
        $this->bgProcessCompletionPoller?->startPollLoop();

        // Consumer supervision: check child process health.
        EventLoop::repeat(self::SUPERVISE_INTERVAL, function (): void {
            if ($this->shuttingDown) {
                return;
            }

            if (!$this->brokerSupervisor->supervise()) {
                $this->shutdown(new RuntimeEvent(
                    type: RuntimeEventTypeEnum::ProtocolError->value,
                    runId: '',
                    seq: 0,
                    payload: [
                        'error' => 'Session sqlite-queue broker exited after readiness. Stopping the session without reconnecting or replaying uncertain queue operations.',
                        'fatal' => true,
                    ],
                ));

                return;
            }

            $this->consumerSupervisor->supervise();
        });

        // Graceful shutdown on termination signals.
        EventLoop::onSignal(\SIGTERM, function (): void {
            $this->shutdown();
        });
        EventLoop::onSignal(\SIGINT, function (): void {
            $this->shutdown();
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
            // Explicit refresh reloads mcp.json, including servers added after
            // runtime.ready. Start the existing supervised owner before enqueueing.
            if ('mcp_refresh' === $command->type && '' !== ($command->runId ?? '')) {
                $this->launchMcpConsumer();
            }
            // Start/attach also initialize MCP. Recheck configuration at that
            // boundary and retain an owner to invalidate a persisted old catalog.
            if (!$this->mcpConsumerLaunched && \in_array($command->type, ['start_run', 'resume'], true)) {
                $runId = trim($command->runId ?? '');
                if ('' === $runId && 'start_run' === $command->type) {
                    $runId = $this->sessionId;
                }
                // Empty resume is rejected by its handler. Empty start_run is
                // valid and allocates a session; it must not probe catalog('').
                if ('' !== $runId && $this->mcpLifecycle->requiresConsumer($runId)) {
                    $this->launchMcpConsumer();
                }
            }
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

    private function launchMcpConsumer(): void
    {
        if ($this->mcpConsumerLaunched) {
            return;
        }

        $this->consumerSupervisor->launch('mcp');
        $this->mcpConsumerLaunched = true;
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
     * handler never fires, leaving consumers adopted by init (ppid=1).
     * Brokers instead stop on controller-owned stdin EOF. Never include them
     * in this session-marker scan: session IDs are not unique across projects.
     *
     * Only kills processes whose:
     *  - parent PID is 1 (truly orphaned, not owned by another controller)
     *  - UID is our non-root UID
     *  - environment contains the exact HATFIELD_SESSION_ID entry
     *  - canonical CWD matches this project
     */
    private function killOrphanedConsumers(): void
    {
        $killedPids = [];
        foreach (self::ORPHAN_CONSUMER_PATTERNS as $pattern) {
            $pgrepOutput = [];
            $pgrepStatus = 0;
            exec('pgrep -f '.escapeshellarg($pattern).' 2>/dev/null', $pgrepOutput, $pgrepStatus);
            if (0 !== $pgrepStatus || [] === $pgrepOutput) {
                continue;
            }

            foreach ($pgrepOutput as $line) {
                $pid = (int) trim($line);
                if ($pid <= 0 || isset($killedPids[$pid])) {
                    continue;
                }

                if (!$this->ownsOrphanedConsumer($pid)) {
                    continue;
                }

                $this->logger->info('Reaping orphaned session process', [
                    'component' => 'HeadlessController',
                    'event_type' => 'controller.orphan_consumer.term',
                    'pid' => $pid,
                    'ppid' => 1,
                    'pattern' => $pattern,
                    'run_id' => $this->sessionId,
                    'session_id' => $this->sessionId,
                ]);

                // Send SIGTERM, wait briefly, escalate to SIGKILL if needed.
                @posix_kill($pid, \SIGTERM);
                $killedPids[$pid] = $pid;
            }
        }

        if ([] !== $killedPids) {
            // Give the processes a moment to clean up.
            usleep(500_000);

            foreach ($killedPids as $pid) {
                // Recheck ownership before escalation; a PID alone is not identity.
                if ($this->ownsOrphanedConsumer($pid)) {
                    @posix_kill($pid, \SIGKILL);
                    $this->logger->warning('Orphaned session process did not stop gracefully, sent SIGKILL', [
                        'component' => 'HeadlessController',
                        'event_type' => 'controller.orphan_consumer.kill',
                        'pid' => $pid,
                        'run_id' => $this->sessionId,
                        'session_id' => $this->sessionId,
                    ]);
                }
            }
        }
    }

    private function ownsOrphanedConsumer(int $pid): bool
    {
        $processDir = '/proc/'.$pid;
        $uid = @fileowner($processDir);
        if (false === $uid || 0 === $uid || $uid !== posix_geteuid()) {
            return false;
        }

        $stat = @file_get_contents($processDir.'/stat');
        if (false === $stat || false === ($commEnd = strrpos($stat, ')'))) {
            return false;
        }
        // The parenthesized command may contain spaces; PPID follows the state.
        $fields = explode(' ', substr($stat, $commEnd + 2));
        if (1 !== (int) ($fields[1] ?? 0)) {
            return false;
        }

        $environment = @file_get_contents($processDir.'/environ');
        $cwd = @readlink($processDir.'/cwd');

        return false !== $environment && false !== $cwd
            && self::matchesConsumerIdentity($environment, $cwd, $this->sessionId, $this->runtimeCwd);
    }

    private static function matchesConsumerIdentity(string $environment, string $cwd, string $sessionId, string $runtimeCwd): bool
    {
        $project = realpath($runtimeCwd);

        // Exact NUL-delimited entries prevent both value and variable-name prefixes.
        return false !== $project && $cwd === $project
            && \in_array('HATFIELD_SESSION_ID='.$sessionId, explode("\0", $environment), true);
    }

    private function shutdown(?RuntimeEvent $terminal = null): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;

        // Terminal output and owned-process teardown share the parent's budget.
        $deadline = microtime(true) + 5.0;
        $this->emitter->shutdown($deadline, $terminal);

        $this->logger->info('Controller shutting down gracefully');

        // Parent JsonlProcessAgentSessionClient stop budget is 7s. Share one
        // deadline across consumers and broker so sequential 5s+5s grace cannot
        // outlive that parent SIGKILL.
        $this->consumerSupervisor->shutdownUntil($deadline);
        $this->brokerSupervisor->shutdownUntil($deadline);

        try {
            $this->dispatcher->dispatch(new ControllerSessionShutdownEvent($this->sessionId));
        } catch (\Throwable $exception) {
            $this->logger->error('Controller session shutdown lifecycle failed', [
                'component' => 'HeadlessController',
                'event_type' => 'controller.session_shutdown_lifecycle_failed',
                'session_id' => $this->sessionId,
                'exception' => $exception,
            ]);
        } finally {
            // Release only after consumers and lifecycle listeners finish so a
            // replacement controller cannot race against cleanup.
            $this->releaseSessionOwnerLock();
            // Only the shutdown owner may stop the loop. A repeated signal
            // must leave its suspended terminal drain and cleanup runnable.
            EventLoop::getDriver()->stop();
        }
    }

    /**
     * Non-blocking process-lifetime flock for one controller per project CWD + session.
     * TTL null keeps the lock until explicit release or process death (flock auto-release).
     * Unexpected lock-store failures propagate; only a held lock returns false.
     */
    private function acquireSessionOwnerLock(): bool
    {
        $lock = $this->sessionOwnerLockFactory->createLock($this->sessionOwnerLockResource(), ttl: null, autoRelease: true);

        if (!$lock->acquire(blocking: false)) {
            $this->logger->error('Controller session already owned by another live process', [
                'component' => 'HeadlessController',
                'event_type' => 'controller.session_owner_lock_conflict',
                'session_id' => $this->sessionId,
            ]);

            return false;
        }

        $this->sessionOwnerLock = $lock;
        $this->logger->info('Controller acquired session owner lock', [
            'component' => 'HeadlessController',
            'event_type' => 'controller.session_owner_lock_acquired',
            'session_id' => $this->sessionId,
        ]);

        return true;
    }

    private function releaseSessionOwnerLock(): void
    {
        $lock = $this->sessionOwnerLock;
        $this->sessionOwnerLock = null;
        if (null === $lock) {
            return;
        }

        if ($lock->isAcquired()) {
            $lock->release();
        }
    }

    private function sessionOwnerLockResource(): string
    {
        $canonicalCwd = realpath($this->runtimeCwd);
        if (false === $canonicalCwd) {
            $canonicalCwd = $this->runtimeCwd;
        }

        return 'hatfield.controller.session.'.hash('sha256', $canonicalCwd."\0".$this->sessionId);
    }

    private function startSessionBroker(): void
    {
        $paths = SessionSqliteQueueEnv::pathsFromEnvironment($this->sessionId);
        $requiredConnections = SessionSqliteQueueBrokerTopology::requiredConnections(
            1,
            $this->resolveToolWorkerCount(),
        );
        $this->brokerSupervisor->start($paths, $requiredConnections);

        $advertisedPayload = $this->brokerSupervisor->advertisedMaxPayload();
        $advertisedConnections = $this->brokerSupervisor->advertisedMaxConnections();
        if (null === $advertisedPayload || null === $advertisedConnections) {
            throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" became ready without advertising payload and connection budgets.', $this->sessionId));
        }
        if ($advertisedConnections < $requiredConnections) {
            throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" advertised max_connections=%d below topology requirement %d.', $this->sessionId, $advertisedConnections, $requiredConnections));
        }
    }

    /**
     * Resolve the tool consumer pool size once from either the constructor
     * override or tools.execution.max_parallelism. The same value sizes the
     * broker connection budget and launches the tool workers.
     */
    private function resolveToolWorkerCount(): int
    {
        return $this->toolWorkerCount > 0
            ? $this->toolWorkerCount
            : max(1, $this->toolExecutionSettings->maxParallelism());
    }
}
