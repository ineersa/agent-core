<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * Owns the controller stdout emit pipeline, per-run event cursoring, and
 * canonical event drain from InProcessAgentSessionClient.
 *
 * All runtime event writes go through this class. Cursor tracking auto-registers
 * on RunStarted/RunResumed events and releases on terminal events
 * (RunCompleted/RunFailed/RunCancelled). The drain loop polls eventClient per
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

    /** @var (\Closure(): void)|null Callback invoked on fatal stdout write failure before event loop stop. */
    private ?\Closure $onFatalShutdown = null;

    public function __construct(
        private readonly ?InProcessAgentSessionClient $eventClient,
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
        $this->stdout = fopen('php://stdout', 'w');
        if (false === $this->stdout) {
            throw new \RuntimeException('Cannot open stdout for controller mode');
        }
    }

    /**
     * Emit a runtime event to stdout with auto cursor register/release.
     *
     * Cursor lifecycle events:
     * - Register on: RunStarted, RunResumed
     * - Release on: RunCompleted, RunFailed, RunCancelled
     */
    public function emit(RuntimeEvent $event): void
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
                    // Event drain failures can stall the TUI silently.
                    // Delegate capture=0 rethrow to boundary.
                    // If we reach here, capture mode is enabled.
                    $this->boundary->catch($e, 'headless_controller.event_drain_failed', [
                        'run_id' => $runId,
                    ]);

                    // Capture mode: emit protocol error for TUI visibility
                    // and release cursor so subsequent polls start fresh.
                    $this->emitInternal(new RuntimeEvent(
                        type: RuntimeEventTypeEnum::RuntimeReady->value, // broadcast to unstick
                        runId: $runId,
                        seq: 0,
                        payload: [],
                    ));

                    $this->emitInternal(new RuntimeEvent(
                        type: RuntimeEventTypeEnum::ProtocolError->value,
                        runId: $runId,
                        seq: 0,
                        payload: [
                            'error' => 'Event drain failed: '.$e->getMessage(),
                            'run_id' => $runId,
                        ],
                    ));

                    unset($this->runEventCursors[$runId]);

                    $this->logger->error('Event drain failed', [
                        'run_id' => $runId,
                        'exception' => $e,
                    ]);

                    // Event drain will retry next tick.
                }
            }
        });
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
            $this->logger->error('Controller stdout write failed, initiating shutdown', [
                'event_type' => $event->type,
                'error' => $error['message'] ?? 'unknown',
            ]);
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
