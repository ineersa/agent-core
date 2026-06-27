<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * Owns the controller stdout emit pipeline, per-run event cursoring, and
 * canonical event drain from the headless AgentSessionClient.
 *
 * All runtime event writes go through this class. Cursor tracking auto-registers
 * on RunStarted events and cursors are never released (issue #183). The drain loop polls eventClient per
 * active run, skips already-seen events by cursor,
 * and writes to stdout via JsonlCodec.
 */
final class RuntimeEventEmitter
{
    /** @var resource|null */
    private $stdout;

    private bool $shuttingDown = false;

    /** @var array<string, int> runId => lastForwardedSeq */
    private array $runEventCursors = [];

    /** @var array<string, int> runId => consecutive drain failures without a successful poll */
    private array $runDrainFailureCounts = [];

    /** @var (\Closure(): void)|null Callback invoked on fatal stdout write failure before event loop stop. */
    private ?\Closure $onFatalShutdown = null;

    public function __construct(
        private readonly ?AgentSessionClient $eventClient,
        private readonly RuntimeExceptionBoundary $boundary,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Set the callback invoked when a stdout write failure triggers shutdown.
     * The controller uses this to perform consumer supervision shutdown and
     * background process cleanup before the event loop stops.
     */
    public function setFatalShutdownHandler(\Closure $handler): void
    {
        $this->onFatalShutdown = $handler;
    }

    /**
     * Open the stdout resource for writing.
     *
     * Must be called before emit() or startDrainLoop(). The controller
     * calls this once at startup.
     */
    public function openStdout(): void
    {
        $this->stdout = fopen('php://stdout', 'wb');
        if (false === $this->stdout) {
            throw new \RuntimeException('Cannot open stdout for controller mode');
        }
    }

    /**
     * Emit a runtime event to stdout with auto cursor register.
     *
     * Cursor lifecycle events:
     * - Register on: RunStarted, RunResumed (passive attach — same drain registration)
     * - Cursors are NEVER released — a completed/failed/cancelled run may
     *   receive follow-up commands that produce new canonical events
     *   (issue #183). The drain loop continues polling at the recorded
     *   cursor position and simply yields no new events for idle runs.
     */
    public function emit(RuntimeEvent $event): void
    {
        // Auto-register event drain cursors based on event type.
        if (
            RuntimeEventTypeEnum::RunStarted->value === $event->type
            || RuntimeEventTypeEnum::RunResumed->value === $event->type
        ) {
            $this->runEventCursors[$event->runId] = $this->runEventCursors[$event->runId] ?? 0;
        }

        // Cursor removal on terminal events has been removed (issue #183).
        // When a run completes, the resulting run.completed event would unset
        // the cursor, preventing follow-up AdvanceRun events from being
        // forwarded. Keeping cursors alive has trivial overhead (polling
        // completed runs returns 0 events) and prevents the drain loop from
        // silently dropping follow-up events.

        $this->emitInternal($event);
    }

    /**
     * Whether the emitter has been asked to shut down.
     */
    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    /**
     * Signal the emitter to stop. The drain loop checks this before each tick.
     */
    public function shutdown(): void
    {
        $this->shuttingDown = true;
    }

    /**
     * Register the canonical event drain loop.
     *
     * Polls InProcessAgentSessionClient for each active run, forwards
     * unseen events to stdout.
     */
    public function startDrainLoop(float $interval = 0.05): void
    {
        EventLoop::repeat($interval, function (): void {
            $this->drainRegisteredRunsOnce();
        });
    }

    /**
     * Poll each registered run once and forward unseen canonical events to stdout.
     *
     * A transient failure while draining must not unregister the run; the cursor
     * is preserved so the next tick can resume from the last successfully forwarded seq.
     */
    public function drainRegisteredRunsOnce(): void
    {
        if ($this->shuttingDown || null === $this->eventClient) {
            return;
        }

        // Snapshot active run IDs to avoid modification during iteration.
        // PHP auto-casts numeric-string array keys to ints, so cast back
        // to string before passing to string-typed methods like events().
        $activeRuns = array_keys($this->runEventCursors);

        foreach ($activeRuns as $runId) {
            $runId = (string) $runId;
            $cursor = $this->runEventCursors[$runId] ?? null;
            if (null === $cursor) {
                continue;
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

                unset($this->runDrainFailureCounts[$runId]);
            } catch (\Throwable $e) {
                $failures = ($this->runDrainFailureCounts[$runId] ?? 0) + 1;
                $this->runDrainFailureCounts[$runId] = $failures;

                // Delegate capture=0 rethrow to boundary. If we reach here, capture mode is enabled.
                $this->boundary->catch($e, 'headless_controller.event_drain_failed', [
                    'run_id' => $runId,
                ]);

                $logContext = [
                    'component' => 'RuntimeEventEmitter',
                    'event_type' => 'headless_controller.event_drain_failed',
                    'run_id' => $runId,
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                    'consecutive_failures' => $failures,
                    'last_forwarded_seq' => $this->runEventCursors[$runId] ?? 0,
                ];

                if (1 === $failures || 0 === $failures % 10) {
                    $this->logger->warning('Canonical event drain failed; will retry on next tick', $logContext);
                }

                if (1 === $failures) {
                    $this->emitInternal(new RuntimeEvent(
                        type: RuntimeEventTypeEnum::ProtocolError->value,
                        runId: $runId,
                        seq: 0,
                        payload: [
                            'error' => 'Event drain failed: '.$e->getMessage(),
                            'run_id' => $runId,
                        ],
                    ));
                }

                // Keep runEventCursors[$runId] — abandoning the run after one failure
                // left the TUI stuck in Cancelling while backend events continued (issue #205).
            }
        }
    }

    // ── Internal ────────────────────────────────────────────────────────

    private function emitInternal(RuntimeEvent $event): void
    {
        if (null === $this->stdout) {
            return;
        }

        $line = JsonlCodec::encodeEvent($event);
        $written = @fwrite($this->stdout, $line);
        $writeError = error_get_last();

        if (false === $written || 0 === $written) {
            $error = $writeError;
            $logContext = [
                'component' => 'RuntimeEventEmitter',
                'event_type' => $event->type,
                'error' => $error['message'] ?? 'unknown',
            ];
            if ('' !== $event->runId) {
                $logContext['run_id'] = $event->runId;
            }
            $this->logger->error('Controller stdout write failed, initiating shutdown', $logContext);
            $this->shuttingDown = true;

            // Delegate full shutdown (consumer supervision, bg process cleanup)
            // to the controller via the fatal shutdown handler.
            if (null !== $this->onFatalShutdown) {
                ($this->onFatalShutdown)();
            }

            EventLoop::getDriver()->stop();

            return;
        }

        fflush($this->stdout);
    }
}
